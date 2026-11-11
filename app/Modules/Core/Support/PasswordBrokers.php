<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Password brokers built for the current database context. Laravel caches
 * its brokers together with the connection they were created on; in a
 * queue worker that serves many tenants that connection would point at an
 * earlier tenant's file. Built on demand, a broker always uses the current
 * default connection (the tenant's, or central's).
 */
final class PasswordBrokers
{
    public static function make(string $name): PasswordBroker
    {
        /** @var array{provider: string, table: string, expire: int, throttle?: int}|null $config */
        $config = config("auth.passwords.{$name}");

        if ($config === null) {
            throw new InvalidArgumentException("Password broker [{$name}] is not defined.");
        }

        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7));
        }

        return new PasswordBroker(
            new DatabaseTokenRepository(DB::connection(), app('hash'), $config['table'], $key, $config['expire'] * 60, $config['throttle'] ?? 0),
            Auth::createUserProvider($config['provider']) ?? throw new InvalidArgumentException("Unknown user provider [{$config['provider']}]."),
            app('events'),
            timeboxDuration: (int) config('auth.timebox_duration', 200000),
        );
    }
}
