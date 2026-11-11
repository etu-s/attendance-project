<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Requests;

use App\Modules\Leave\Services\LeaveAttachmentService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /leave-requests/{id}/attachments (multipart `file`). `mimes` checks
 * the detected content type, not the client's file name. Who may attach is
 * checked by LeaveAttachmentService (LeaveRules).
 */
class LeaveAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.LeaveAttachmentService::MAX_KILOBYTES,
                'mimes:'.implode(',', array_keys(LeaveAttachmentService::TYPES)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'The file may be at most 5 MB.',
            'file.mimes' => 'Attach a PDF, JPG or PNG file.',
        ];
    }
}
