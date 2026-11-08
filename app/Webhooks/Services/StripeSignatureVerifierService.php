<?php

declare(strict_types=1);

namespace App\Webhooks\Services;

use App\Webhooks\Exceptions\InvalidWebhookSignatureException;

/**
 * التحقق من توقيع Stripe بإيدينا (ADR 0003 و0008).
 * الـ header: t=<timestamp>,v1=<signature>[,v1=...][,v0=...]
 * النص الموقّع: "{t}.{raw body}" بـ HMAC-SHA256 والسر whsec_...
 */
final class StripeSignatureVerifierService
{
    /**
     * @param  list<string>  $secrets  أكتر من سر وقت تغيير السر
     *
     * @throws InvalidWebhookSignatureException
     */
    public function verify(string $payload, string $header, int $now, array $secrets, int $toleranceSeconds): void
    {
        if ($header === '') {
            throw InvalidWebhookSignatureException::missingHeader();
        }

        if ($secrets === []) {
            throw InvalidWebhookSignatureException::notConfigured();
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value; // v0 وأي نسخة تانية بنتجاهلها
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw InvalidWebhookSignatureException::malformedHeader();
        }

        // abs(): بيرفض القديم (replay) والمستقبلي
        if (abs($now - $timestamp) > $toleranceSeconds) {
            throw InvalidWebhookSignatureException::timestampOutsideTolerance();
        }

        $signedPayload = $timestamp.'.'.$payload;

        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $signedPayload, $secret);

            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return;
                }
            }
        }

        throw InvalidWebhookSignatureException::noMatchingSignature();
    }
}
