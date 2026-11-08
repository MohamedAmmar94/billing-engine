<?php

declare(strict_types=1);

namespace App\Webhooks\Enums;

enum WebhookEventStatusEnum: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Dead = 'dead';
}
