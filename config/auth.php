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
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
