<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\PunchSourceKind;
use App\Modules\Attendance\Models\PunchSource;
use App\Modules\Core\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Punch source keys look like ams_<prefix>_<secret>. The prefix finds the
 * source; only sha256(secret) is stored, and the full key is returned once,
 * at creation or rotation. The optional HMAC signing secret has to be
 * readable to verify signatures, so it is stored encrypted instead.
 */
final class PunchSourceService
{
    public const KEY_PATTERN = '/^ams_([a-z0-9]{10})_([A-Za-z0-9]{40})$/';

    /**
     * @param  array{name: string, kind: string, ip_allowlist?: list<string>|null, signed?: bool}  $data
     * @return array{0: PunchSource, 1: string, 2: string|null} [source, key, signing secret]
     */
    public function create(array $data): array
    {
        [$prefix, $secret] = $this->newKey();
        $signingSecret = ($data['signed'] ?? false) ? Str::random(48) : null;

        $source = PunchSource::query()->create([
            'name' => $data['name'],
            'kind' => PunchSourceKind::from($data['kind']),
            'key_prefix' => $prefix,
            'key_hash' => hash('sha256', $secret),
            'signing_secret' => $signingSecret,
            'ip_allowlist' => $this->allowlist($data['ip_allowlist'] ?? null),
        ]);

        return [$source, $this->format($prefix, $secret), $signingSecret];
    }

    /**
     * @param  array{name?: string, kind?: string, ip_allowlist?: list<string>|null, is_active?: bool}  $data
     */
    public function update(PunchSource $source, array $data): PunchSource
    {
        if (array_key_exists('ip_allowlist', $data)) {
            $data['ip_allowlist'] = $this->allowlist($data['ip_allowlist']);
        }

        $source->update($data);

        return $source;
    }

    /**
     * New key (and, if the source signs requests, a new signing secret). The
     * old key stops working immediately.
     *
     * @return array{0: PunchSource, 1: string, 2: string|null}
     */
    public function rotate(PunchSource $source): array
    {
        [$prefix, $secret] = $this->newKey();
        $signingSecret = $source->requiresSignature() ? Str::random(48) : null;

        $source->update([
            'key_prefix' => $prefix,
            'key_hash' => hash('sha256', $secret),
            'signing_secret' => $signingSecret,
        ]);

        return [$source, $this->format($prefix, $secret), $signingSecret];
    }

    public function delete(PunchSource $source): void
    {
        // Raw punches are permanent and keep their source; deactivate instead.
        if (DB::table('raw_punches')->where('punch_source_id', $source->id)->exists()) {
            throw new BusinessRuleException('This source has sent punches. Deactivate it instead.', 'punch_source_in_use', 409);
        }

        $source->delete();
    }

    /**
     * The active source a bearer key belongs to, or null.
     */
    public function authenticate(string $key): ?PunchSource
    {
        if (! preg_match(self::KEY_PATTERN, $key, $parts)) {
            return null;
        }

        $source = PunchSource::query()->where('key_prefix', $parts[1])->first();

        if ($source === null || ! hash_equals($source->key_hash, hash('sha256', $parts[2]))) {
            return null;
        }

        return $source;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function newKey(): array
    {
        do {
            $prefix = Str::lower(Str::random(10));
        } while (PunchSource::query()->where('key_prefix', $prefix)->exists());

        return [$prefix, Str::random(40)];
    }

    private function format(string $prefix, string $secret): string
    {
        return "ams_{$prefix}_{$secret}";
    }

    /**
     * @param  list<string>|null  $entries
     * @return list<string>|null
     */
    private function allowlist(?array $entries): ?array
    {
        $entries = array_values(array_unique(array_filter(array_map('trim', $entries ?? []))));

        return $entries === [] ? null : $entries;
    }
}
