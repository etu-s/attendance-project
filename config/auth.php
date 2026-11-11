<?php

use App\Modules\Central\Models\SuperAdmin;
use App\Modules\Identity\Models\User;

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
     * `web` authenticates tenant users against the current tenant's database.
     * `central` authenticates super admins against the central database. They
     * use different session keys, so a session on one never authenticates the other.
     */
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'central' => [
            'driver' => 'session',
            'provider' => 'super_admins',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],

        'super_admins' => [
            'driver' => 'eloquent',
            'model' => SuperAdmin::class,
        ],
    ],

    'passwords' => [
        // Tenant users ("Forgot password"); the table lives in each tenant DB.
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
        // Invitations to set a first password: same table, a week to accept.
        'invitations' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 7 * 24 * 60,
            'throttle' => 0,
        ],
        // Super admins (central DB).
        'super_admins' => [
            'provider' => 'super_admins',
            'table' => 'super_admin_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
