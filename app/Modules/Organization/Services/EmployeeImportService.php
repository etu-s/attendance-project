<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Enums\EmploymentType;
use App\Modules\Organization\Enums\ImportStatus;
use App\Modules\Organization\Jobs\ProcessEmployeeImport;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\EmployeeImport;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\Location;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SplTempFileObject;
use Throwable;

/**
 * CSV employee import. Valid rows are created, invalid rows are skipped and
 * reported by line number. Each row is created in its own short transaction
 * (via EmployeeService) so a large file never holds the SQLite write lock.
 *
 * Columns (header row, any order, case-insensitive):
 *   required: employee_code, first_name, last_name, hire_date (Y-m-d), department_code
 *   optional: email, phone, job_title, employment_type, location_code,
 *             group_codes (separated by ";")
 */
final class EmployeeImportService
{
    public const REQUIRED = ['employee_code', 'first_name', 'last_name', 'hire_date', 'department_code'];

    public const OPTIONAL = ['email', 'phone', 'job_title', 'employment_type', 'location_code', 'group_codes'];

    public const MAX_ROWS = 5000;

    /** Errors stored per import; the counts stay exact beyond this. */
    private const MAX_STORED_ERRORS = 500;

    public function __construct(
        private readonly EmployeeService $employees,
    ) {}

    public function start(UploadedFile $file, User $uploader): EmployeeImport
    {
        $import = EmployeeImport::query()->create([
            'user_id' => $uploader->id,
            'status' => ImportStatus::Pending,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'contents' => (string) file_get_contents($file->getRealPath()),
        ]);

        ProcessEmployeeImport::dispatch($import->id);

        return $import;
    }

    public function process(EmployeeImport $import): void
    {
        if ($import->status !== ImportStatus::Pending) {
            return;
        }

        $import->update(['status' => ImportStatus::Processing, 'started_at' => now()]);

        try {
            [$header, $rows] = $this->parse((string) $import->contents);
            $this->assertHeader($header);

            $created = 0;
            $errors = [];
            $failed = 0;
            $seenCodes = [];

            foreach ($rows as $line => $values) {
                $row = $this->combine($header, $values);
                $code = $row['employee_code'] ?? null;

                try {
                    $key = mb_strtolower((string) $code);
                    if ($code !== null && $code !== '' && isset($seenCodes[$key])) {
                        throw ValidationException::withMessages(['employee_code' => "Duplicate employee_code in file (first seen on line {$seenCodes[$key]})."]);
                    }
                    $seenCodes[$key] = $line;

                    $this->employees->create($this->toEmployeeData($row), null);
                    $created++;
                } catch (ValidationException $e) {
                    $failed++;
                    $this->collect($errors, $line, $code, array_merge(...array_values($e->errors())));
                } catch (BusinessRuleException $e) {
                    $failed++;
                    $this->collect($errors, $line, $code, [$e->getMessage()]);
                }
            }

            $import->update([
                'status' => ImportStatus::Completed,
                'total_rows' => count($rows),
                'created_count' => $created,
                'failed_count' => $failed,
                'errors' => $errors,
                'contents' => null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $import->update([
                'status' => ImportStatus::Failed,
                'errors' => [['row' => 0, 'code' => null, 'messages' => [$e instanceof ValidationException ? $e->getMessage() : 'The file could not be processed.']]],
                'contents' => null,
                'finished_at' => now(),
            ]);

            if (! $e instanceof ValidationException) {
                report($e);
            }
        }
    }

    /**
     * @return array{0: list<string>, 1: array<int, list<string|null>>} header, rows keyed by line number
     */
    private function parse(string $contents): array
    {
        // Strip a UTF-8 BOM (Excel adds one) so the first header matches.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $file = new SplTempFileObject;
        $file->fwrite($contents);
        $file->rewind();
        $file->setFlags(SplTempFileObject::READ_CSV | SplTempFileObject::SKIP_EMPTY | SplTempFileObject::READ_AHEAD | SplTempFileObject::DROP_NEW_LINE);

        $header = null;
        $rows = [];

        foreach ($file as $index => $values) {
            if (! is_array($values) || $values === [null]) {
                continue;
            }

            if ($header === null) {
                $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $values);

                continue;
            }

            $rows[(int) $index + 1] = array_map(fn ($v) => $v === null ? null : trim((string) $v), $values);

            if (count($rows) > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'The file has more than '.self::MAX_ROWS.' rows.']);
            }
        }

        if ($header === null) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        return [$header, $rows];
    }

    /**
     * @param  list<string>  $header
     */
    private function assertHeader(array $header): void
    {
        $missing = array_diff(self::REQUIRED, $header);

        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => 'Missing columns: '.implode(', ', $missing).'.']);
        }
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $values
     * @return array<string, string|null>
     */
    private function combine(array $header, array $values): array
    {
        $row = [];
        foreach ($header as $i => $column) {
            $value = $values[$i] ?? null;
            $row[$column] = $value === '' ? null : $value;
        }

        return $row;
    }

    /**
     * Validates one row with the same rules as the create form and resolves codes to ids.
     *
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    private function toEmployeeData(array $row): array
    {
        $row['employment_type'] ??= EmploymentType::FullTime->value;

        $validated = Validator::make($row, [
            'employee_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique(Employee::class, 'employee_code')],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique(Employee::class, 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'hire_date' => ['required', 'date_format:'.LocalDate::FORMAT],
            'department_code' => ['required', 'string', Rule::exists(Department::class, 'code')->where('is_active', true)],
            'location_code' => ['nullable', 'string', Rule::exists(Location::class, 'code')->where('is_active', true)],
            'group_codes' => ['nullable', 'string'],
        ], [
            'department_code.exists' => 'Unknown or inactive department code.',
            'location_code.exists' => 'Unknown or inactive location code.',
        ])->validate();

        $groupCodes = array_values(array_filter(array_map('trim', explode(';', (string) ($validated['group_codes'] ?? '')))));
        $groups = Group::query()->whereIn('code', $groupCodes)->where('is_active', true)->pluck('id', 'code');

        $unknown = array_udiff($groupCodes, $groups->keys()->all(), 'strcasecmp');
        if ($unknown !== []) {
            throw ValidationException::withMessages(['group_codes' => 'Unknown or inactive group codes: '.implode(', ', $unknown).'.']);
        }

        return [
            'employee_code' => $validated['employee_code'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'job_title' => $validated['job_title'] ?? null,
            'employment_type' => $validated['employment_type'],
            'hire_date' => $validated['hire_date'],
            'department_id' => (int) Department::query()->where('code', $validated['department_code'])->value('id'),
            'location_id' => isset($validated['location_code'])
                ? (int) Location::query()->where('code', $validated['location_code'])->value('id')
                : null,
            'group_ids' => $groups->values()->map(fn ($id) => (int) $id)->all(),
        ];
    }

    /**
     * @param  list<array{row: int, code: string|null, messages: list<string>}>  $errors
     * @param  list<string>  $messages
     */
    private function collect(array &$errors, int $line, ?string $code, array $messages): void
    {
        if (count($errors) < self::MAX_STORED_ERRORS) {
            $errors[] = ['row' => $line, 'code' => $code, 'messages' => array_values($messages)];
        }
    }
}
