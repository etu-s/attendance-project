<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authenticated by AuthenticatePunchSource (punch source key), not a user.
 */
class StorePunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return PunchPayload::rules();
    }
}
