<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveRequestAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Leave attachments (approved for Module 9): PDF, JPG or PNG, at most 5 MB
 * and 3 per request, on the tenant's local disk under a random name (the
 * original name is only shown and used for downloads). Files of a pending
 * request that is cancelled are deleted with it.
 */
final class LeaveAttachmentService
{
    public const MAX_FILES = 3;

    public const MAX_KILOBYTES = 5120;

    /** Allowed extensions => MIME type stored and served. */
    public const TYPES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    public function __construct(
        private readonly LeaveRules $rules,
    ) {}

    public function attach(LeaveRequest $request, UploadedFile $file, User $actor): LeaveRequestAttachment
    {
        $request->loadMissing('employee');

        if (! $this->rules->canAttach($actor, $request)) {
            throw new BusinessRuleException(
                $request->status->isActive() ? 'You cannot add files to this request.' : 'Files can only be added to pending or approved requests.',
                'leave_attachment_forbidden',
                $request->status->isActive() ? 403 : 409,
            );
        }
        if ($request->attachments()->count() >= self::MAX_FILES) {
            throw new BusinessRuleException('A request can have at most '.self::MAX_FILES.' files. Remove one first.', 'leave_attachment_limit', 409);
        }

        // The extension comes from the detected content (validated as pdf/jpg/png), not the client's name.
        $extension = strtolower((string) $file->guessExtension());
        $path = $file->storeAs('leave-attachments/'.$request->id, Str::lower(Str::random(32)).'.'.$extension, 'local');

        if ($path === false) {
            throw new BusinessRuleException('The file could not be stored. Try again.', 'leave_attachment_storage', 500);
        }

        return LeaveRequestAttachment::query()->create([
            'leave_request_id' => $request->id,
            'original_name' => $this->displayName($file->getClientOriginalName(), $extension),
            'mime_type' => self::TYPES[$extension] ?? 'application/octet-stream',
            'size_bytes' => (int) $file->getSize(),
            'path' => $path,
            'uploaded_by' => $actor->id,
        ])->load('uploader');
    }

    public function download(LeaveRequestAttachment $attachment): StreamedResponse
    {
        return Storage::disk('local')->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
        ]);
    }

    public function remove(LeaveRequest $request, LeaveRequestAttachment $attachment, User $actor): void
    {
        $request->loadMissing('employee');

        if (! $this->rules->canRemoveAttachment($actor, $request, $attachment)) {
            throw new BusinessRuleException('Only the uploader or HR can remove a file, while the request is pending.', 'leave_attachment_forbidden', 403);
        }

        $attachment->delete();
        Storage::disk('local')->delete($attachment->path);
    }

    /**
     * Deletes every file of a request (a pending request that was cancelled).
     */
    public function removeAll(LeaveRequest $request): void
    {
        foreach ($request->attachments()->get() as $attachment) {
            $attachment->delete();
            Storage::disk('local')->delete($attachment->path);
        }
    }

    /**
     * A safe display name: no path, no control characters, the real extension.
     */
    private function displayName(string $original, string $extension): string
    {
        $name = pathinfo(str_replace('\\', '/', $original), PATHINFO_FILENAME);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"<>|:*?\/]+/u', '', $name));

        return mb_substr($name === '' ? 'attachment' : $name, 0, 140).'.'.$extension;
    }
}
