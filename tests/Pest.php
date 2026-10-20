<?php

declare(strict_types=1);

use Tests\TestCase;

/*
 * Module tests live next to their code in app/Modules/<Module>/Tests.
 * Files opt in to RefreshDatabase themselves (backup tests must run
 * outside a transaction, because VACUUM INTO cannot).
 */
pest()->extend(TestCase::class)->in('Feature', 'Unit', '../app/Modules');
