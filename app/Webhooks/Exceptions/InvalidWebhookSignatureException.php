<?php

declare(strict_types=1);

namespace App\Webhooks\Exceptions;

use RuntimeException;

final class InvalidWebhookSignatureException extends RuntimeException
{
    public static function missingHeader(): self
    {
        return new self('The Stripe-Signature header is missing.');
    }

    public static function notConfigured(): self
    {
        return new self('No webhook signing secret is configured.');
    }

    public static function malformedHeader(): self
    {
        return new self('The Stripe-Signature header is malformed.');
    }

    public static function timestampOutsideTolerance(): self
    {
        return new self('The signature timestamp is outside the tolerance window.');
    }

    public static function noMatchingSignature(): self
    {
        return new self('No signature matches the payload.');
    }
}
