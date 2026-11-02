<?php

declare(strict_types=1);

namespace App\Payments\Console;

use App\Payments\Enums\PaymentLinkStatus;
use App\Payments\Models\PaymentLink;
use Illuminate\Console\Command;

final class ExpireStaleLinks extends Command
{
    protected $signature = 'payments:expire-links';

    protected $description = 'Mark open payment links that passed their expiry as expired';

    public function handle(): int
    {
        $count = PaymentLink::query()
            ->where('status', PaymentLinkStatus::Open->value)
            ->where('expires_at', '<', now())
            ->update(['status' => PaymentLinkStatus::Expired->value]);

        $this->info("Expired {$count} link(s).");

        return self::SUCCESS;
    }
}
