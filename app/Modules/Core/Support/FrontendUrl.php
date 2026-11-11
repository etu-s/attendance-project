<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Links into the SPA for emails. The central console lives on
 * ams.frontend_url; a tenant workspace on the same origin with its
 * subdomain in front of the host.
 */
final class FrontendUrl
{
    /**
     * @param  array<string, string>  $query
     */
    public static function central(string $path, array $query = []): string
    {
        return self::build(null, $path, $query);
    }

    /**
     * @param  array<string, string>  $query
     */
    public static function tenant(string $subdomain, string $path, array $query = []): string
    {
        return self::build($subdomain, $path, $query);
    }

    /**
     * @param  array<string, string>  $query
     */
    private static function build(?string $subdomain, string $path, array $query): string
    {
        $parts = parse_url((string) config('ams.frontend_url'));
        $scheme = $parts['scheme'] ?? 'https';
        $host = ($subdomain === null ? '' : $subdomain.'.').($parts['host'] ?? 'localhost');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}/".ltrim($path, '/').($query === [] ? '' : '?'.http_build_query($query));
    }
}
