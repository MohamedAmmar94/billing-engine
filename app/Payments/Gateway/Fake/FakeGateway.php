<?php

declare(strict_types=1);

namespace App\Payments\Gateway\Fake;

use App\Payments\Gateway\CheckoutSessionDTO;
use App\Payments\Gateway\CheckoutSessionRequestDTO;
use App\Payments\Gateway\CheckoutSessionStatusEnum;
use App\Payments\Gateway\GatewayException;
use App\Payments\Gateway\GatewayTimeoutException;
use App\Payments\Gateway\PaymentGatewayInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * بوابة مزيفة بتتصرف زي Stripe: نفس الـ idempotency key بيرجّع نفس الـ session،
 * والـ session المفتوحة بتنتهي لما وقتها يعدي، ونقدر نحاكي الـ timeouts والرفض.
 * الحالة في الـ cache (مش في الذاكرة) عشان تفضل بين الـ requests في التطوير.
 *
 * @phpstan-type SessionData array{id: string, url: string, status: string, expires_at: int, amount: int, client_reference_id: string}
 * @phpstan-type State array{counter: int, calls: array<string, int>, sessions: array<string, SessionData>, keys: array<string, string>, failures: array<string, list<string>>}
 */
final class FakeGateway implements PaymentGatewayInterface
{
    private const STATE_KEY = 'fake-gateway:state';

    public function createCheckoutSession(CheckoutSessionRequestDTO $request): CheckoutSessionDTO
    {
        $failure = $this->begin('create');

        if ($failure === FakeFailureEnum::TimeoutBeforeEffect) {
            throw new GatewayTimeoutException('Fake gateway: timed out before the session was created.');
        }

        if ($failure === FakeFailureEnum::Rejected) {
            throw new GatewayException('Fake gateway: the request was rejected.');
        }

        $state = $this->state();
        $sessionId = $state['keys'][$request->idempotencyKey] ?? null;

        if ($sessionId === null) {
            $sessionId = sprintf('cs_test_fake_%06d', ++$state['counter']);
            $state['keys'][$request->idempotencyKey] = $sessionId;
            $state['sessions'][$sessionId] = [
                'id' => $sessionId,
                'url' => 'https://checkout.fake/pay/'.$sessionId,
                'status' => CheckoutSessionStatusEnum::Open->value,
                'expires_at' => $request->expiresAt->getTimestamp(),
                'amount' => $request->amount->amount,
                'client_reference_id' => $request->clientReferenceId,
            ];
            $this->save($state);
        }

        if ($failure === FakeFailureEnum::TimeoutAfterEffect) {
            throw new GatewayTimeoutException('Fake gateway: the session exists but the response was lost.');
        }

        return $this->toSession($state['sessions'][$sessionId]);
    }

    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionDTO
    {
        $failure = $this->begin('retrieve');

        if ($failure === FakeFailureEnum::Rejected) {
            throw new GatewayException('Fake gateway: the request was rejected.');
        }

        if ($failure !== null) {
            throw new GatewayTimeoutException('Fake gateway: timed out while retrieving the session.');
        }

        return $this->toSession($this->sessionData($this->state(), $sessionId));
    }

    public function expireCheckoutSession(string $sessionId): void
    {
        $failure = $this->begin('expire');

        if ($failure === FakeFailureEnum::Rejected) {
            throw new GatewayException('Fake gateway: the request was rejected.');
        }

        if ($failure === FakeFailureEnum::TimeoutBeforeEffect) {
            throw new GatewayTimeoutException('Fake gateway: timed out before the session was expired.');
        }

        $state = $this->state();
        $session = $this->toSession($this->sessionData($state, $sessionId));

        if ($session->status === CheckoutSessionStatusEnum::Complete) {
            throw new GatewayException('Fake gateway: a completed session cannot be expired.');
        }

        if ($session->status === CheckoutSessionStatusEnum::Open) {
            $state['sessions'][$sessionId]['status'] = CheckoutSessionStatusEnum::Expired->value;
            $this->save($state);
        }

        if ($failure === FakeFailureEnum::TimeoutAfterEffect) {
            throw new GatewayTimeoutException('Fake gateway: the session was expired but the response was lost.');
        }
    }

    // ---------- أدوات للـ tests والتطوير (مش جزء من الـ interface) ----------

    /** العميل دفع على صفحة Stripe. */
    public function complete(string $sessionId): void
    {
        $this->setStatus($sessionId, CheckoutSessionStatusEnum::Complete);
    }

    /** الـ session انتهت عند Stripe. */
    public function expire(string $sessionId): void
    {
        $this->setStatus($sessionId, CheckoutSessionStatusEnum::Expired);
    }

    /** الاستدعاء الجاي لـ ($operation = create | retrieve | expire) هيفشل بالطريقة دي. */
    public function failNext(string $operation, FakeFailureEnum $failure): void
    {
        $state = $this->state();
        $state['failures'][$operation][] = $failure->value;
        $this->save($state);
    }

    public function session(string $sessionId): CheckoutSessionDTO
    {
        return $this->toSession($this->sessionData($this->state(), $sessionId));
    }

    public function sessionCount(): int
    {
        return count($this->state()['sessions']);
    }

    public function calls(string $operation): int
    {
        return $this->state()['calls'][$operation] ?? 0;
    }

    public function reset(): void
    {
        Cache::forget(self::STATE_KEY);
    }

    // ---------- داخلي ----------

    /** بيسجّل الاستدعاء ويسحب الفشل المخطط له (لو فيه). */
    private function begin(string $operation): ?FakeFailureEnum
    {
        $state = $this->state();
        $state['calls'][$operation] = ($state['calls'][$operation] ?? 0) + 1;

        $queue = $state['failures'][$operation] ?? [];
        $planned = array_shift($queue);
        $state['failures'][$operation] = $queue;

        $this->save($state);

        return $planned === null ? null : FakeFailureEnum::from($planned);
    }

    private function setStatus(string $sessionId, CheckoutSessionStatusEnum $status): void
    {
        $state = $this->state();
        $this->sessionData($state, $sessionId);
        $state['sessions'][$sessionId]['status'] = $status->value;
        $this->save($state);
    }

    /**
     * @param  State  $state
     * @return SessionData
     */
    private function sessionData(array $state, string $sessionId): array
    {
        return $state['sessions'][$sessionId] ?? throw new GatewayException("No such checkout session: {$sessionId}.");
    }

    /** @param  SessionData  $data */
    private function toSession(array $data): CheckoutSessionDTO
    {
        $expiresAt = CarbonImmutable::createFromTimestamp($data['expires_at']);
        $status = CheckoutSessionStatusEnum::from($data['status']);

        // الـ session المفتوحة بتنتهي لوحدها لما وقتها يعدي (زي Stripe)
        if ($status === CheckoutSessionStatusEnum::Open && $expiresAt->lessThanOrEqualTo(now())) {
            $status = CheckoutSessionStatusEnum::Expired;
        }

        return new CheckoutSessionDTO(
            id: $data['id'],
            url: $status === CheckoutSessionStatusEnum::Open ? $data['url'] : null,
            status: $status,
            expiresAt: $expiresAt,
        );
    }

    /** @return State */
    private function state(): array
    {
        /** @var State|null $state */
        $state = Cache::get(self::STATE_KEY);

        return $state ?? ['counter' => 0, 'calls' => [], 'sessions' => [], 'keys' => [], 'failures' => []];
    }

    /** @param  State  $state */
    private function save(array $state): void
    {
        Cache::forever(self::STATE_KEY, $state);
    }
}
