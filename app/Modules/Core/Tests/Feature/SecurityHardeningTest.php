<?php

declare(strict_types=1);

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Logging\MaskSensitiveData;
use App\Modules\Core\Logging\MaskSensitiveDataTap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

uses(RefreshDatabase::class);

/**
 * API routes anyone may call without signing in. Adding to this list is a
 * security decision: review it.
 */
const PUBLIC_API_ROUTES = [
    'POST api/v1/auth/login',
    'POST api/v1/auth/forgot-password',
    'POST api/v1/auth/reset-password',
    'POST api/v1/auth/accept-invitation',
    'POST api/v1/central/auth/login',
    'POST api/v1/central/auth/forgot-password',
    'POST api/v1/central/auth/reset-password',
    'GET api/v1/health',
];

/**
 * @return list<RoutingRoute>
 */
function apiRoutes(): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/')));
}

function routeLabel(RoutingRoute $route): string
{
    return $route->methods()[0].' '.$route->uri();
}

describe('route audit', function (): void {
    it('requires authentication on every API route except the reviewed public ones', function (): void {
        $unauthenticated = [];
        foreach (apiRoutes() as $route) {
            $middleware = implode(' ', $route->gatherMiddleware());
            $authenticated = str_contains($middleware, 'auth:') || str_contains($middleware, 'Authenticate:')
                || str_contains($middleware, 'AuthenticatePunchSource');

            if (! $authenticated && ! in_array(routeLabel($route), PUBLIC_API_ROUTES, true)) {
                $unauthenticated[] = routeLabel($route);
            }
        }

        expect($unauthenticated)->toBe([]);
    });

    it('rate limits every API route and keeps tenant and central routes apart', function (): void {
        $problems = [];
        foreach (apiRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $joined = implode(' ', $middleware);

            if (! str_contains($joined, 'throttle:') && ! str_contains($joined, 'ThrottleRequests')) {
                $problems[] = 'no rate limit: '.routeLabel($route);
            }
            $central = str_starts_with($route->uri(), 'api/v1/central');
            if (! in_array($central ? 'central' : 'tenant', $middleware, true)) {
                $problems[] = 'wrong host group: '.routeLabel($route);
            }
        }

        expect($problems)->toBe([]);
    });

    it('applies the heavy limit to exports, recalculations and imports', function (): void {
        foreach (['tenant.reports.exports.store', 'tenant.attendance.recalculate', 'tenant.overtime.recalculate', 'tenant.employees.import'] as $name) {
            expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('throttle:heavy');
        }
    });
});

describe('security headers', function (): void {
    it('sends them on tenant and central API responses', function (): void {
        $tenant = $this->createTenant('acme');

        foreach ([$this->getJson($this->tenantUrl($tenant, 'health')), $this->getJson($this->centralUrl('auth/me'))] as $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
                ->assertHeaderMissing('Strict-Transport-Security');
        }
    });

    it('sends HSTS only over HTTPS and when configured', function (): void {
        $tenant = $this->createTenant('acme');
        config(['ams.security.hsts_max_age' => 31536000]);

        $this->getJson($this->tenantUrl($tenant, 'health'))->assertHeaderMissing('Strict-Transport-Security');
        $this->getJson(str_replace('http://', 'https://', $this->tenantUrl($tenant, 'health')))
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    });

    it('limits heavy requests per user', function (): void {
        config(['ams.rate_limits.heavy_per_minute' => 2]);
        $tenant = $this->createTenant('acme');
        $this->actingAsTenantUser($this->createTenantUser($tenant, RoleName::HrManager));
        $url = $this->tenantUrl($tenant, 'overtime/recalculate');

        $this->postJson($url, ['from' => '2026-03-01', 'to' => '2026-03-02'])->assertStatus(202);
        $this->postJson($url, ['from' => '2026-03-01', 'to' => '2026-03-02'])->assertStatus(202);
        $this->postJson($url, ['from' => '2026-03-01', 'to' => '2026-03-02'])->assertStatus(429);
    });
});

describe('log masking', function (): void {
    function maskedRecord(string $message, array $context = []): LogRecord
    {
        return (new MaskSensitiveData)(new LogRecord(new DateTimeImmutable, 'test', Level::Error, $message, $context));
    }

    it('masks emails, keys, tokens and long numbers in messages', function (): void {
        $record = maskedRecord('Login failed for jane.doe@acme.test with ams_ab12cd_s3cr3tv4lue, Bearer 1|abcDEF, phone +92 300 1234567 (id 42)');

        expect($record->message)->toBe('Login failed for [email] with [key], Bearer [token], phone [number] (id 42)')
            ->and(maskedRecord('Day 2026-03-01 09:00:00 for 0300-1234567 recalculated')->message)
            ->toBe('Day 2026-03-01 09:00:00 for [number] recalculated');
    });

    it('masks sensitive context keys at any depth, and exceptions', function (): void {
        $record = maskedRecord('Import failed', [
            'password' => 'hunter2',
            'row' => ['first_name' => 'Jane', 'employee_code' => 'E-1', 'contact_email' => 'x'],
            'exception' => new RuntimeException('Duplicate entry for jane@acme.test'),
            'count' => 3,
        ]);

        expect($record->context['password'])->toBe('[masked]')
            ->and($record->context['row'])->toBe(['first_name' => '[masked]', 'employee_code' => 'E-1', 'contact_email' => '[masked]'])
            ->and($record->context['exception'])->toStartWith('RuntimeException: Duplicate entry for [email] at ')
            ->and($record->context['count'])->toBe(3);
    });

    it('is attached to the configured log channels', function (): void {
        foreach (['single', 'daily', 'stderr', 'syslog', 'errorlog'] as $channel) {
            expect(config("logging.channels.{$channel}.tap"))->toContain(MaskSensitiveDataTap::class);
        }

        $handler = new TestHandler;
        $logger = Log::build(['driver' => 'monolog', 'handler' => TestHandler::class, 'tap' => [MaskSensitiveDataTap::class]]);
        $logger->getLogger()->setHandlers([$handler]);
        (new MaskSensitiveDataTap)($logger);
        $logger->error('Mail to ada@example.com bounced', ['token' => 'abc']);

        expect($handler->getRecords()[0]->message)->toBe('Mail to [email] bounced')
            ->and($handler->getRecords()[0]->context['token'])->toBe('[masked]');
    });
});
