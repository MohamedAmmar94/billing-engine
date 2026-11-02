<?php

declare(strict_types=1);

namespace App\Payments\Gateway;

interface PaymentGatewayInterface
{
    /**
     * @throws GatewayTimeoutException لو النتيجة مجهولة (ممكن الـ session اتعملت فعلاً)
     * @throws GatewayException لو الطلب اترفض نهائياً
     */
    public function createCheckoutSession(CheckoutSessionRequestDTO $request): CheckoutSessionDTO;

    /** @throws GatewayException */
    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionDTO;

    /** @throws GatewayException لو الـ session مش مفتوحة (اتدفعت مثلاً) */
    public function expireCheckoutSession(string $sessionId): void;
}
