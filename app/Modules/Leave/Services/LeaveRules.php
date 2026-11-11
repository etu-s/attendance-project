<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Core\Support\TenantClock;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveRequestAttachment;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Services\EmployeeVisibility;

/**
 * Who may do what with a leave request (approved 2026-10-22). Shared by the
 * service (enforcement) and the API resource (what the UI offers).
 *
 *  - decide: leave.approve for an employee one can see, never one's own
 *    request and never about oneself;
 *  - auto-approve on entry: someone who sees everyone and may approve (HR,
 *    Tenant Admin), entering for someone else;
 *  - cancel: pending — the requester, the employee, or an approver; approved
 *    and not started — the same; approved and started — leave.manage only.
 */
final class LeaveRules
{
    /** @var array<string, bool> */
    private array $seen = [];

    public function __construct(
        private readonly EmployeeVisibility $visibility,
        private readonly TenantClock $clock,
    ) {}

    public function canDecide(User $actor, LeaveRequest $request): bool
    {
        return $request->status === LeaveRequestStatus::Pending
            && $actor->can('leave.approve')
            && ! $this->isOwn($actor, $request)
            && $this->canSee($actor, $request->employee);
    }

    public function autoApproves(User $actor, Employee $employee): bool
    {
        return $actor->can('leave.approve') && $this->visibility->seesEveryone($actor) && $employee->user_id !== $actor->id;
    }

    public function canCancel(User $actor, LeaveRequest $request): bool
    {
        if (! $request->status->isActive() || ! $this->canSee($actor, $request->employee)) {
            return false;
        }

        $involved = $this->isOwn($actor, $request) || $actor->can('leave.approve');

        if ($request->status === LeaveRequestStatus::Pending) {
            return $involved;
        }

        // Approved leave that has started (or passed) is HR's to undo.
        return $request->start_date->greaterThan($this->clock->today())
            ? $involved
            : $actor->can('leave.manage');
    }

    /**
     * Attachments (medical certificates are sensitive): the requester or the
     * employee, and people who may view or decide that employee's leave.
     */
    public function canViewAttachments(User $actor, LeaveRequest $request): bool
    {
        return $this->isOwn($actor, $request)
            || (($actor->can('leave.view') || $actor->can('leave.approve')) && $this->canSee($actor, $request->employee));
    }

    /**
     * Adding files: the requester or the employee, or HR (leave.manage), while
     * the request is pending or approved (a certificate may come later).
     */
    public function canAttach(User $actor, LeaveRequest $request): bool
    {
        return $request->status->isActive()
            && ($this->isOwn($actor, $request) || ($actor->can('leave.manage') && $this->canSee($actor, $request->employee)));
    }

    /**
     * Removing a file: its uploader or HR, while the request is pending
     * (the evidence of an approved request stays).
     */
    public function canRemoveAttachment(User $actor, LeaveRequest $request, LeaveRequestAttachment $attachment): bool
    {
        return $request->status === LeaveRequestStatus::Pending
            && ($attachment->uploaded_by === $actor->id || ($actor->can('leave.manage') && $this->canSee($actor, $request->employee)));
    }

    public function isOwn(User $actor, LeaveRequest $request): bool
    {
        return $request->requested_by === $actor->id || $request->employee->user_id === $actor->id;
    }

    /**
     * Memoized per user and employee: a list of requests asks for the same
     * employees again and again. Registered scoped, so it resets per request/job.
     */
    private function canSee(User $actor, Employee $employee): bool
    {
        return $this->seen[$actor->id.':'.$employee->id] ??= $this->visibility->canSee($actor, $employee);
    }
}
