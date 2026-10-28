<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Overtime\Enums\OvertimeKind;
use App\Modules\Overtime\Enums\OvertimeSource;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRecord;
use App\Modules\Overtime\Support\OvertimeSettings;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Deciding overtime and HR's manual entries. An approver may approve fewer
 * minutes than calculated; the weighted minutes shrink in proportion.
 */
final class OvertimeService
{
    public function __construct(
        private readonly OvertimeAccess $access,
        private readonly SettingsService $settings,
        private readonly TenantClock $clock,
    ) {}

    public function approve(OvertimeRecord $record, User $actor, ?int $minutes, ?string $note): OvertimeRecord
    {
        $this->assertDecidable($record, $actor);

        if ($record->calculated_minutes === 0) {
            throw new BusinessRuleException('There is no overtime left to approve; reject it instead.', 'overtime_empty');
        }

        $minutes ??= $record->calculated_minutes;
        if ($minutes < 1 || $minutes > $record->calculated_minutes) {
            throw ValidationException::withMessages(['minutes' => "Approve between 1 and {$record->calculated_minutes} minutes."]);
        }

        $record->update([
            'status' => OvertimeStatus::Approved,
            'approved_minutes' => $minutes,
            'approved_weighted_minutes' => intdiv($record->weighted_minutes * $minutes, $record->calculated_minutes),
            'decided_by' => $actor->id,
            'decided_at' => CarbonImmutable::now('UTC'),
            'decision_note' => $note,
            'changed_after_decision' => false,
        ]);

        return $record;
    }

    public function reject(OvertimeRecord $record, User $actor, ?string $note): OvertimeRecord
    {
        $this->assertDecidable($record, $actor);

        $record->update([
            'status' => OvertimeStatus::Rejected,
            'approved_minutes' => null,
            'approved_weighted_minutes' => null,
            'decided_by' => $actor->id,
            'decided_at' => CarbonImmutable::now('UTC'),
            'decision_note' => $note,
            'changed_after_decision' => false,
        ]);

        return $record;
    }

    /**
     * Approves every given record the actor may decide, as calculated; the rest are skipped.
     *
     * @param  list<int>  $ids
     * @return array{approved: int, skipped: int}
     */
    public function approveMany(array $ids, User $actor): array
    {
        $approved = 0;
        $records = OvertimeRecord::query()->with('employee')->whereKey($ids)->get();

        foreach ($records as $record) {
            if ($record->calculated_minutes > 0 && $this->access->canDecide($actor, $record)) {
                $this->approve($record, $actor, null, null);
                $approved++;
            }
        }

        return ['approved' => $approved, 'skipped' => count($ids) - $approved];
    }

    /**
     * @param  array{work_date: string, minutes: int, kind: string, reason: string}  $data
     */
    public function createManual(Employee $employee, array $data, User $actor): OvertimeRecord
    {
        $date = LocalDate::parse($data['work_date']);
        $this->assertNotFuture($date);

        $exists = OvertimeRecord::withTrashed()
            ->where('employee_id', $employee->id)
            ->where('work_date', $date->format(LocalDate::FORMAT))
            ->where('source', OvertimeSource::Manual->value)
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['work_date' => 'This employee already has a manual entry for that day; edit it instead.']);
        }

        $autoApprove = $this->access->autoApproves($actor, $employee);
        $record = OvertimeRecord::query()->create($this->manualAttributes(OvertimeKind::from($data['kind']), (int) $data['minutes']) + [
            'employee_id' => $employee->id,
            'work_date' => $date,
            'source' => OvertimeSource::Manual,
            'status' => OvertimeStatus::Pending,
            'reason' => $data['reason'],
            'created_by' => $actor->id,
        ]);

        if ($autoApprove) {
            $record->update([
                'status' => OvertimeStatus::Approved,
                'approved_minutes' => $record->calculated_minutes,
                'approved_weighted_minutes' => $record->weighted_minutes,
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now('UTC'),
            ]);
        }

        return $record;
    }

    /**
     * Manual entries stay editable while pending.
     *
     * @param  array{minutes?: int, kind?: string, reason?: string}  $data
     */
    public function updateManual(OvertimeRecord $record, array $data): OvertimeRecord
    {
        $this->assertEditableManual($record);

        $kind = isset($data['kind']) ? OvertimeKind::from($data['kind']) : $record->mainKind();
        $record->update($this->manualAttributes($kind, (int) ($data['minutes'] ?? $record->calculated_minutes)) + [
            'reason' => $data['reason'] ?? $record->reason,
        ]);

        return $record;
    }

    public function deleteManual(OvertimeRecord $record): void
    {
        $this->assertEditableManual($record);
        $record->forceDelete();
    }

    /**
     * @return array<string, int>
     */
    private function manualAttributes(OvertimeKind $kind, int $minutes): array
    {
        $rules = OvertimeSettings::fromSettings($this->settings);
        $parts = [
            'before' => 0,
            'after' => $kind === OvertimeKind::Workday ? $minutes : 0,
            'rest_day' => $kind === OvertimeKind::RestDay ? $minutes : 0,
            'holiday' => $kind === OvertimeKind::Holiday ? $minutes : 0,
        ];

        return [
            'before_minutes' => 0,
            'after_minutes' => $parts['after'],
            'rest_day_minutes' => $parts['rest_day'],
            'holiday_minutes' => $parts['holiday'],
            'calculated_minutes' => $minutes,
            'rate_workday' => $rules->rateWorkday,
            'rate_rest_day' => $rules->rateRestDay,
            'rate_holiday' => $rules->rateHoliday,
            'weighted_minutes' => $rules->weighted($parts),
        ];
    }

    private function assertDecidable(OvertimeRecord $record, User $actor): void
    {
        $record->loadMissing('employee');

        if ($record->status !== OvertimeStatus::Pending) {
            throw new BusinessRuleException('This overtime was already decided.', 'overtime_decided', 409);
        }

        if (! $this->access->canDecide($actor, $record)) {
            throw $this->access->isOwn($actor, $record)
                ? new BusinessRuleException('You cannot decide your own overtime.', 'overtime_own', 403)
                : new BusinessRuleException('You cannot decide this overtime.', 'overtime_forbidden', 403);
        }
    }

    private function assertEditableManual(OvertimeRecord $record): void
    {
        if ($record->source !== OvertimeSource::Manual) {
            throw new BusinessRuleException('Calculated overtime follows attendance; correct the attendance instead.', 'overtime_calculated', 409);
        }
        if ($record->status !== OvertimeStatus::Pending) {
            throw new BusinessRuleException('This entry was already decided.', 'overtime_decided', 409);
        }
    }

    private function assertNotFuture(CarbonImmutable $date): void
    {
        if ($date->greaterThan($this->clock->today())) {
            throw ValidationException::withMessages(['work_date' => 'Overtime cannot be entered for a future date.']);
        }
    }
}
