<?php

declare(strict_types=1);

namespace App\Payments\Actions;

use App\Billing\Models\Invoice;
use App\Payments\Enums\PaymentLinkStatusEnum;
use App\Payments\Models\PaymentLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** الاعتماد على الحالة الحالية مش ترتيب الـ events: expired بعد completed ما بيعملش حاجة. */
final class MarkPaymentLinkExpiredAction
{
    public function handle(string $linkUuid): bool
    {
        if (! Str::isUuid($linkUuid)) {
            return false;
        }

        $known = PaymentLink::query()->where('uuid', $linkUuid)->first();

        if ($known === null) {
            return false;
        }

        return DB::transaction(function () use ($known): bool {
            Invoice::query()->whereKey($known->invoice_id)->lockForUpdate()->first();
            $link = PaymentLink::query()->whereKey($known->id)->lockForUpdate()->firstOrFail();

            if (! in_array($link->status->value, PaymentLinkStatusEnum::liveValues(), true)) {
                return false;
            }

            $link->update(['status' => PaymentLinkStatusEnum::Expired]);

            return true;
        });
    }
}
