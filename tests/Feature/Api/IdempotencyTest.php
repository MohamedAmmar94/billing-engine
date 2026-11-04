<?php

declare(strict_types=1);

use App\Billing\Enums\InvoiceStatusEnum;
use App\Billing\Events\InvoiceOpenedEvent;
use App\Billing\Models\Invoice;
use App\Billing\Models\InvoiceItem;
use App\Shared\Idempotency\IdempotencyMiddleware;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

const INVOICES = '/api/v1/invoices';

beforeEach(function () {
    $this->seed(SequenceSeeder::class);
    $this->staff = makeStaff();
    Sanctum::actingAs($this->staff);

    $this->payload = [
        'customer' => makeCustomer()->uuid,
        'items' => [['product' => makeProduct(10000)->uuid, 'quantity' => 1]],
    ];
});

// ---------- الإعادة ----------

it('creates one invoice and replays the stored response for the same key (problem: client retries)', function () {
    $key = (string) Str::uuid();

    $first = $this->postJson(INVOICES, $this->payload, idem($key))->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    $second = $this->postJson(INVOICES, $this->payload, idem($key))->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and(Invoice::query()->count())->toBe(1)
        ->and(DB::table('idempotency_keys')->where('key', $key)->value('response_status'))->toBe(201);
});

it('replays the original snapshot even after the invoice changed', function () {
    $key = (string) Str::uuid();
    $created = $this->postJson(INVOICES, $this->payload, idem($key))->assertCreated()->json('data');

    $this->postJson(INVOICES."/{$created['id']}/open", [], idem())->assertOk();

    $this->postJson(INVOICES, $this->payload, idem($key))
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.number', null);
});

it('replays an open that already succeeded instead of answering 409', function () {
    $draft = draftInvoice();
    $key = (string) Str::uuid();

    $first = $this->postJson(INVOICES."/{$draft->uuid}/open", [], idem($key))->assertOk();
    $replay = $this->postJson(INVOICES."/{$draft->uuid}/open", [], idem($key))->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json())->toBe($first->json());

    // نية جديدة (مفتاح جديد) على فاتورة مفتوحة: 409 عادي
    $this->postJson(INVOICES."/{$draft->uuid}/open", [], idem())
        ->assertConflict()
        ->assertJsonPath('code', 'invoice_state_conflict');
});

it('stores and replays deterministic business errors', function () {
    $draft = draftInvoice();
    $key = (string) Str::uuid();

    $this->postJson(INVOICES."/{$draft->uuid}/void", [], idem($key))
        ->assertConflict()
        ->assertJsonPath('code', 'invoice_state_conflict');

    $this->postJson(INVOICES."/{$draft->uuid}/void", [], idem($key))
        ->assertConflict()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('code', 'invoice_state_conflict');
});

// ---------- نفس المفتاح بطلب مختلف ----------

it('rejects a key reused with a different body or endpoint (422)', function () {
    $key = (string) Str::uuid();
    $created = $this->postJson(INVOICES, $this->payload, idem($key))->assertCreated()->json('data');

    $changed = $this->payload;
    $changed['items'][0]['quantity'] = 2;

    $this->postJson(INVOICES, $changed, idem($key))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'idempotency_key_reused');

    $this->postJson(INVOICES."/{$created['id']}/open", [], idem($key))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'idempotency_key_reused');

    expect(Invoice::query()->count())->toBe(1);
});

// ---------- الطلب جاري / المتعطل ----------

it('answers 409 with Retry-After while the first request is still running', function () {
    $key = (string) Str::uuid();
    $this->postJson(INVOICES, $this->payload, idem($key))->assertCreated();

    // نحاكي إن الطلب الأول لسه شغال: مفيش رد مخزن والقفل لسه جديد
    DB::table('idempotency_keys')->update(['response_status' => null, 'response_body' => null, 'locked_at' => now()]);

    $this->postJson(INVOICES, $this->payload, idem($key))
        ->assertConflict()
        ->assertHeader('Retry-After', (string) config('idempotency.retry_after_seconds'))
        ->assertJsonPath('code', 'idempotency_in_progress');

    expect(Invoice::query()->count())->toBe(1);
});

it('takes over an abandoned request after the lock timeout (problem: process killed)', function () {
    $key = (string) Str::uuid();
    $this->postJson(INVOICES, $this->payload, idem($key))->assertCreated();

    // نحاكي process اتقتل: الشغل اتلغى (مفيش فاتورة) والمفتاح فضل "شغال" بقاله أكتر من المهلة
    InvoiceItem::query()->delete();
    Invoice::query()->delete();
    DB::table('idempotency_keys')->update([
        'response_status' => null,
        'response_body' => null,
        'locked_at' => now()->subSeconds(config()->integer('idempotency.lock_timeout_seconds') + 1),
    ]);

    $this->postJson(INVOICES, $this->payload, idem($key))->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    $this->postJson(INVOICES, $this->payload, idem($key))->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(Invoice::query()->count())->toBe(1);
});

it('rolls back the work of an attempt that lost its lock (fencing)', function () {
    $draft = draftInvoice();

    // وإحنا بنفتح الفاتورة، محاولة تانية بتتولى المفتاح
    Event::listen(InvoiceOpenedEvent::class, function (): void {
        DB::table('idempotency_keys')->update(['lock_token' => 'taken-by-another-attempt']);
    });

    $this->postJson(INVOICES."/{$draft->uuid}/open", [], idem())
        ->assertConflict()
        ->assertJsonPath('code', 'idempotency_in_progress');

    $fresh = $draft->fresh();
    expect($fresh->status)->toBe(InvoiceStatusEnum::Draft)
        ->and($fresh->number)->toBeNull()
        ->and((int) DB::table('sequences')->where('name', 'invoice')->value('last_value'))->toBe(0);
});

// ---------- الأخطاء غير المخزنة ----------

it('does not store server errors, so a retry runs again (problem: 5xx)', function () {
    $draft = draftInvoice();
    $key = (string) Str::uuid();

    Event::listen(InvoiceOpenedEvent::class, function (): void {
        throw new RuntimeException('boom');
    });

    $this->postJson(INVOICES."/{$draft->uuid}/open", [], idem($key))->assertStatus(500);

    expect(DB::table('idempotency_keys')->count())->toBe(0)
        ->and($draft->fresh()->status)->toBe(InvoiceStatusEnum::Draft);

    Event::forget(InvoiceOpenedEvent::class);

    $this->postJson(INVOICES."/{$draft->uuid}/open", [], idem($key))
        ->assertOk()
        ->assertHeaderMissing('Idempotent-Replayed')
        ->assertJsonPath('data.status', 'open');
});

// ---------- شكل المفتاح ----------

it('refuses a missing or malformed key', function (?string $key, string $code) {
    $headers = $key === null ? [] : ['Idempotency-Key' => $key];

    $this->postJson(INVOICES, $this->payload, $headers)
        ->assertStatus(400)
        ->assertJsonPath('code', $code);

    expect(Invoice::query()->count())->toBe(0);
})->with([
    'missing' => [null, 'idempotency_key_missing'],
    'too short' => ['short', 'idempotency_key_invalid'],
    'bad characters' => ['this key has spaces!', 'idempotency_key_invalid'],
    'too long' => [str_repeat('a', 256), 'idempotency_key_invalid'],
]);

it('requires the key on open as well', function () {
    $draft = draftInvoice();

    $this->postJson(INVOICES."/{$draft->uuid}/open", [], [])
        ->assertStatus(400)
        ->assertJsonPath('code', 'idempotency_key_missing');
});

it('is wired on the three money-affecting endpoints', function (string $uri) {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($candidate) => $candidate->uri() === $uri && in_array('POST', $candidate->methods(), true));

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain(IdempotencyMiddleware::class);
})->with([
    'api/v1/invoices',
    'api/v1/invoices/{invoice}/open',
    'api/v1/invoices/{invoice}/void',
]);

// ---------- النطاق والصلاحية ----------

it('keeps keys separate per staff member', function () {
    $key = (string) Str::uuid();

    $this->postJson(INVOICES, $this->payload, idem($key))->assertCreated();

    Sanctum::actingAs(makeStaff());
    $this->postJson(INVOICES, $this->payload, idem($key))
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(Invoice::query()->count())->toBe(2);
});

it('treats an expired key as unused', function () {
    $key = (string) Str::uuid();
    $this->postJson(INVOICES, $this->payload, idem($key))->assertCreated();

    $this->travelTo(now()->addHours(config()->integer('idempotency.ttl_hours') + 1));

    $this->postJson(INVOICES, $this->payload, idem($key))
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(Invoice::query()->count())->toBe(2);
});

it('prunes expired keys and keeps the live ones', function () {
    $this->postJson(INVOICES, $this->payload, idem())->assertCreated();
    $this->travelTo(now()->addHours(config()->integer('idempotency.ttl_hours') + 1));
    $this->postJson(INVOICES, $this->payload, idem())->assertCreated();

    $this->artisan('idempotency:prune')->assertSuccessful();

    expect(DB::table('idempotency_keys')->count())->toBe(1);
});
