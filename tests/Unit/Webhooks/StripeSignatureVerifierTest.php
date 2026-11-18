<?php

declare(strict_types=1);

use App\Webhooks\Exceptions\InvalidWebhookSignatureException;
use App\Webhooks\Services\StripeSignatureVerifierService;

function sigHeader(string $payload, int $timestamp, string $secret, string $scheme = 'v1'): string
{
    return "t={$timestamp},{$scheme}=".hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
}

beforeEach(function () {
    $this->verifier = new StripeSignatureVerifierService;
    $this->now = 1_800_000_000;
    $this->payload = '{"id":"evt_1","type":"checkout.session.completed"}';
    $this->secret = 'whsec_unit';
});

it('accepts a correctly signed payload', function () {
    $header = sigHeader($this->payload, $this->now, $this->secret);

    expect(fn () => $this->verifier->verify($this->payload, $header, $this->now, [$this->secret], 300))
        ->not->toThrow(InvalidWebhookSignatureException::class);
});

it('rejects an invalid header or signature', function (string $case, string $message) {
    $good = sigHeader($this->payload, $this->now, $this->secret);

    [$payload, $header, $secrets] = match ($case) {
        'missing header' => [$this->payload, '', [$this->secret]],
        'no secret configured' => [$this->payload, $good, []],
        'garbage header' => [$this->payload, 'not-a-signature', [$this->secret]],
        'no timestamp' => [$this->payload, 'v1=abc', [$this->secret]],
        'no v1 signature' => [$this->payload, "t={$this->now}", [$this->secret]],
        'only a v0 signature' => [$this->payload, sigHeader($this->payload, $this->now, $this->secret, 'v0'), [$this->secret]],
        'body was changed' => [$this->payload.' ', $good, [$this->secret]],
        'wrong secret' => [$this->payload, $good, ['whsec_other']],
    };

    expect(fn () => $this->verifier->verify($payload, $header, $this->now, $secrets, 300))
        ->toThrow(InvalidWebhookSignatureException::class, $message);
})->with([
    ['missing header', 'missing'],
    ['no secret configured', 'No webhook signing secret'],
    ['garbage header', 'malformed'],
    ['no timestamp', 'malformed'],
    ['no v1 signature', 'malformed'],
    ['only a v0 signature', 'malformed'],
    ['body was changed', 'No signature matches'],
    ['wrong secret', 'No signature matches'],
]);

it('enforces the replay window in both directions', function (int $age, bool $accepted) {
    $header = sigHeader($this->payload, $this->now - $age, $this->secret);
    $call = fn () => $this->verifier->verify($this->payload, $header, $this->now, [$this->secret], 300);

    $accepted
        ? expect($call)->not->toThrow(InvalidWebhookSignatureException::class)
        : expect($call)->toThrow(InvalidWebhookSignatureException::class, 'tolerance');
})->with([
    'exactly 300 s old' => [300, true],
    '301 s old (replay)' => [301, false],
    '300 s in the future' => [-300, true],
    '301 s in the future' => [-301, false],
]);

it('accepts the request when any v1 signature matches and ignores v0', function () {
    $valid = hash_hmac('sha256', $this->now.'.'.$this->payload, $this->secret);
    $header = "t={$this->now},v0=ignored,v1=deadbeef,v1={$valid}";

    expect(fn () => $this->verifier->verify($this->payload, $header, $this->now, [$this->secret], 300))
        ->not->toThrow(InvalidWebhookSignatureException::class);
});

it('accepts a payload signed with any configured secret (rotation)', function () {
    $header = sigHeader($this->payload, $this->now, 'whsec_new');

    expect(fn () => $this->verifier->verify($this->payload, $header, $this->now, ['whsec_old', 'whsec_new'], 300))
        ->not->toThrow(InvalidWebhookSignatureException::class);
});

it('signs the exact bytes: re-encoding the JSON breaks the signature', function () {
    $pretty = (string) json_encode(json_decode($this->payload, true), JSON_PRETTY_PRINT);
    $header = sigHeader($this->payload, $this->now, $this->secret);

    expect(fn () => $this->verifier->verify($pretty, $header, $this->now, [$this->secret], 300))
        ->toThrow(InvalidWebhookSignatureException::class, 'No signature matches');
});
