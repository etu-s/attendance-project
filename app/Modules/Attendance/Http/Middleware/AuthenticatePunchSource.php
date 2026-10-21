<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Middleware;

use App\Modules\Attendance\Models\PunchSource;
use App\Modules\Attendance\Services\PunchSourceService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates punch ingestion requests by punch source key, within the
 * tenant identified by the subdomain (a key from another workspace simply
 * does not exist here). Then, per source: IP allowlist, and HMAC signature
 * when the source has a signing secret:
 *
 *   X-Timestamp: <unix seconds>   (within 5 minutes, against replays)
 *   X-Signature: sha256=<hex hmac_sha256("<timestamp>.<raw body>", secret)>
 *
 * Errors never say which check failed beyond what the caller can fix.
 */
final class AuthenticatePunchSource
{
    public const ATTRIBUTE = 'punch_source';

    public const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly PunchSourceService $sources,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $source = $this->sources->authenticate((string) $request->bearerToken());

        if ($source === null) {
            return $this->deny('Invalid punch source key.', 401);
        }

        if (! $source->is_active) {
            return $this->deny('This punch source is disabled.', 403);
        }

        if ($source->ip_allowlist !== null && ! IpUtils::checkIp((string) $request->ip(), $source->ip_allowlist)) {
            return $this->deny('Requests from this address are not allowed for this source.', 403);
        }

        if ($source->requiresSignature() && ! $this->validSignature($request, $source)) {
            return $this->deny('Missing, expired or invalid signature.', 401);
        }

        $request->attributes->set(self::ATTRIBUTE, $source);

        return $next($request);
    }

    private function validSignature(Request $request, PunchSource $source): bool
    {
        $timestamp = (string) $request->header('X-Timestamp');
        $signature = (string) $request->header('X-Signature');

        if (! ctype_digit($timestamp) || abs(CarbonImmutable::now()->getTimestamp() - (int) $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), (string) $source->signing_secret);

        return hash_equals($expected, $signature);
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
