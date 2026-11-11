<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Http\Requests\LeaveAttachmentRequest;
use App\Modules\Leave\Http\Resources\LeaveAttachmentResource;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveRequestAttachment;
use App\Modules\Leave\Services\LeaveAttachmentService;
use App\Modules\Leave\Services\LeaveRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Files of a leave request. Someone who may not see the request's files gets
 * 404, so ids reveal nothing.
 */
final class LeaveAttachmentController extends Controller
{
    public function __construct(
        private readonly LeaveAttachmentService $attachments,
        private readonly LeaveRules $rules,
    ) {}

    public function store(LeaveAttachmentRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $user = $this->viewer($request, $leaveRequest);
        /** @var UploadedFile $file */
        $file = $request->file('file');

        $attachment = $this->attachments->attach($leaveRequest, $file, $user)->setRelation('request', $leaveRequest);

        return (new LeaveAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function download(Request $request, LeaveRequest $leaveRequest, LeaveRequestAttachment $attachment): StreamedResponse
    {
        $this->viewer($request, $leaveRequest, $attachment);

        return $this->attachments->download($attachment);
    }

    public function destroy(Request $request, LeaveRequest $leaveRequest, LeaveRequestAttachment $attachment): Response
    {
        $user = $this->viewer($request, $leaveRequest, $attachment);
        $this->attachments->remove($leaveRequest, $attachment, $user);

        return response()->noContent();
    }

    private function viewer(Request $request, LeaveRequest $leaveRequest, ?LeaveRequestAttachment $attachment = null): User
    {
        /** @var User $user */
        $user = $request->user();
        $leaveRequest->loadMissing('employee');

        if (! $this->rules->canViewAttachments($user, $leaveRequest)
            || ($attachment !== null && $attachment->leave_request_id !== $leaveRequest->id)) {
            throw new NotFoundHttpException;
        }

        return $user;
    }
}
