<?php

declare(strict_types=1);

namespace App\Payments\Actions;

use App\Billing\Enums\InvoiceStatusEnum;
use App\Billing\Models\Invoice;
use App\Payments\Enums\PaymentLinkStatusEnum;
use App\Payments\Enums\PaymentRecordingOutcomeEnum;
use App\Payments\Events\PaymentRecordedEvent;
use App\Payments\Jobs\ExpireCheckoutSessionJob;
use App\Payments\Models\Payment;
use App\Payments\Models\PaymentLink;
use App\Payments\Support\CheckoutSessionCompletedDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** الدفع اتأكد من Stripe: ننشئ Payment ونقفل الفاتورة واللينك في transaction واحدة. */
final class RecordCheckoutSessionCompletedAction
{
    public function handle(CheckoutSessionCompletedDTO $data): PaymentRecordingOutcomeEnum
    {
        if ($data->paymentStatus !== 'paid') {
            return PaymentRecordingOutcomeEnum::NotPaid;
        }

        // session من حاجة تانية على نفس حساب Stripe ممكن تيجي بـ id مش uuid
        if (! Str::isUuid($data->clientReferenceId)) {
            return PaymentRecordingOutcomeEnum::UnknownLink;
        }

        $known = PaymentLink::query()->where('uuid', $data->clientReferenceId)->first();

        if ($known === null) {
            return PaymentRecordingOutcomeEnum::UnknownLink;
        }

        return DB::transaction(function () use ($data, $known): PaymentRecordingOutcomeEnum {
            // نفس ترتيب الـ locks دايماً: الفاتورة ثم اللينك
            $invoice = Invoice::query()->whereKey($known->invoice_id)->lockForUpdate()->firstOrFail();
            $link = PaymentLink::query()->whereKey($known->id)->lockForUpdate()->firstOrFail();

            if (Payment::query()->where('payment_link_id', $link->id)->exists()) {
                return PaymentRecordingOutcomeEnum::AlreadyRecorded;
            }

            $currency = strtoupper($data->currency);
            $applies = $invoice->status === InvoiceStatusEnum::Open
            && $data->amountTotal === $invoice->total
            && $currency === $invoice->currency;

            $payment = Payment::query()->create([
                'invoice_id' => $invoice->id,
                'payment_link_id' => $link->id,
                'stripe_payment_intent_id' => $data->paymentIntentId,
                'amount' => $data->amountTotal,
                'currency' => $currency,
                'is_applied' => $applies,
                'paid_at' => $data->paidAt,
            ]);

            // الفلوس حقيقية حتى لو اللينك اتعلّم expired أو canceled في آخر لحظة
            $link->update(['status' => PaymentLinkStatusEnum::Completed, 'response' => $data->raw]);

            if ($applies) {
                $invoice->update(['status' => InvoiceStatusEnum::Paid, 'paid_at' => $data->paidAt]);
                $this->cancelOtherLiveLinks($invoice, $link);
            } else {
                Log::warning('Payment received but not applied to the invoice', [
                    'invoice' => $invoice->uuid,
                    'payment' => $payment->uuid,
                    'reason' => $invoice->status === InvoiceStatusEnum::Open
                        ? 'amount or currency mismatch'
                        : "invoice is {$invoice->status->value}",
                ]);
            }

            // Phase 4: القيد المحاسبي بيتسجل هنا جوه نفس الـ transaction
            event(new PaymentRecordedEvent($payment));

            return $applies ? PaymentRecordingOutcomeEnum::Applied : PaymentRecordingOutcomeEnum::NotApplied;
        });
    }

    private function cancelOtherLiveLinks(Invoice $invoice, PaymentLink $paid): void
    {
        $others = $invoice->paymentLinks()
            ->whereKeyNot($paid->id)
            ->whereIn('status', PaymentLinkStatusEnum::liveValues())
            ->get();

        foreach ($others as $other) {
            $other->update(['status' => PaymentLinkStatusEnum::Canceled]);

            if ($other->stripe_session_id !== null) {
                ExpireCheckoutSessionJob::dispatch($other->stripe_session_id)->afterCommit();
            }
        }
    }
}
