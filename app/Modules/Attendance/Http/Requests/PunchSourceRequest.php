<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\PunchSourceKind;
use App\Modules\Attendance\Models\PunchSource;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and partial update (PUT). `signed` (create only) gives the
 * source an HMAC signing secret; rotating the key renews it.
 */
class PunchSourceRequest extends FormRequest
{
    public const MAX_ALLOWLIST = 20;

    public function authorize(): bool
    {
        return $this->user()?->can('punch-sources.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $updating = $this->route('punchSource') instanceof PunchSource;
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'kind' => [$required, Rule::enum(PunchSourceKind::class)],
            'ip_allowlist' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_ALLOWLIST],
            'ip_allowlist.*' => ['string', 'max:64', function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::isIpOrCidr((string) $value)) {
                    $fail('Enter an IP address or a CIDR range such as 10.0.0.0/24.');
                }
            }],
            'signed' => $updating ? ['prohibited'] : ['sometimes', 'boolean'],
            'is_active' => $updating ? ['sometimes', 'boolean'] : ['prohibited'],
        ];
    }

    public static function isIpOrCidr(string $value): bool
    {
        [$ip, $bits] = array_pad(explode('/', trim($value), 2), 2, null);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return ctype_digit($bits) && (int) $bits <= $max;
    }
}
