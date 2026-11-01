<?php

declare(strict_types=1);

use App\Shared\Money\Money;

arch('every class in app/ declares strict types')
    ->expect('App')
    ->toUseStrictTypes();
arch('the Money value object is framework-agnostic')
    ->expect('App\Shared\Money')
    ->not->toUse('Illuminate');
// arch('the shared kernel is framework-agnostic')
//     ->expect('App\Shared')
//     ->not->toUse('Illuminate');

arch('the shared kernel does not depend on any domain')
    ->expect('App\Shared')
    ->not->toUse(['App\Ledger', 'App\Billing', 'App\Payments', 'App\Webhooks']);

arch('Money is an immutable value object')
    ->expect(Money::class)
    ->toBeFinal()
    ->toBeReadonly();

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();
