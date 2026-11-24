<?php

declare(strict_types=1);

use App\Ledger\Models\JournalEntry;
use App\Ledger\Models\JournalLine;
use App\Shared\Money\Money;
use Illuminate\Contracts\Queue\ShouldQueue;

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
arch('actions end with Action')
    ->expect(['App\Billing\Actions', 'App\Payments\Actions'])
    ->toHaveSuffix('Action');

arch('enums end with Enum')
    ->expect(['App\Billing\Enums', 'App\Ledger\Enums', 'App\Payments\Enums'])
    ->toHaveSuffix('Enum');

arch('events end with Event')
    ->expect('App\Billing\Events')
    ->toHaveSuffix('Event');

arch('listeners end with Listener')
    ->expect('App\Payments\Listeners')
    ->toHaveSuffix('Listener');

arch('jobs end with Job')
    ->expect('App\Payments\Jobs')
    ->toHaveSuffix('Job');

arch('commands end with Command')
    ->expect('App\Payments\Console')
    ->toHaveSuffix('Command');
arch('actions are final and expose handle()')
    ->expect(['App\Billing\Actions', 'App\Payments\Actions', 'App\Ledger\Actions'])
    ->toBeFinal()
    ->toHaveMethod('handle');

arch('services end with Service')
    ->expect(['App\Webhooks\Services', 'App\Ledger\Services'])
    ->toHaveSuffix('Service');

arch('ledger DTOs end with DTO')
    ->expect('App\Ledger\Support')
    ->toHaveSuffix('DTO');

arch('billing, payments and webhooks know nothing about the ledger')
    ->expect(['App\Billing', 'App\Payments', 'App\Webhooks'])
    ->not->toUse('App\Ledger');

arch('ledger listeners are synchronous so they run inside the business transaction')
    ->expect('App\Ledger\Listeners')
    ->not->toImplement(ShouldQueue::class);

arch('only the ledger writes journal entries and lines')
    ->expect([JournalEntry::class, JournalLine::class])
    ->toOnlyBeUsedIn('App\Ledger');
