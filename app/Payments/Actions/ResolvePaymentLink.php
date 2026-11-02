<?php

declare(strict_types=1);

namespace App\Payments\Actions;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Models\Invoice;
use App\Payments\Enums\PaymentLinkStatus;
use App\Payments\Gateway\CheckoutSessionRequest;
use App\Payments\Gateway\CheckoutSessionStatus;
use App\Payments\Gateway\GatewayException;
use App\Payments\Gateway\GatewayTimeoutException;
use App\Payments\Gateway\PaymentGateway;
use App\Payments\Jobs\ExpireCheckoutSession;
use App\Payments\Models\PaymentLink;
use App\Payments\Support\PayResolution;
use App\Shared\Money\Currency;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ResolvePaymentLink
{
    private const MAX_ROUNDS = 3;

    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(Invoice $invoice): PayResolution
    {
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            try {
                $step = $this->decide($invoice);
            } catch (UniqueConstraintViolationException) {
                // شبكة أمان: الـ DB رفضت لينك حي تاني (الـ lock المفروض يمنع ده أصلاً)
                return PayResolution::tryAgain();
            }

            if ($step instanceof PayResolution) {
                return $step;
            }

            if ($step->status === PaymentLinkStatus::Pending) {
                return $this->openAtGateway($invoice, $step);
            }

            $resolution = $this->verifyOpenLink($invoice, $step);

            if ($resolution !== null) {
                return $resolution;
            }
            // اللينك القديم اتقفل، نلف تاني وندي العميل لينك جديد
        }

        return PayResolution::tryAgain();
    }

    /**
     * المرحلة 1: قرار من الـ DB بس (بدون شبكة) تحت lock على الفاتورة.
     */
    private function decide(Invoice $invoice): PayResolution|PaymentLink
    {
        return DB::transaction(function () use ($invoice): PayResolution|PaymentLink {
            $invoice = $invoice->lockedCopy();

            if ($invoice->status === InvoiceStatus::Paid) {
                return PayResolution::alreadyPaid();
            }

            if ($invoice->status !== InvoiceStatus::Open) {
                return PayResolution::notPayable();
            }

            $live = $invoice->paymentLinks()
                ->whereIn('status', PaymentLinkStatus::liveValues())
                ->first();

            if ($live === null) {
                return $this->newPendingLink($invoice);
            }

            if ($live->status === PaymentLinkStatus::Open) {
                return $live;
            }

            // pending: إما حد بيكلم Stripe دلوقتي، أو العملية وقعت في النص
            $isRecent = $live->created_at?->greaterThan(
                now()->subSeconds(config()->integer('payments.pending_grace_seconds')),
            ) ?? false;

            if ($isRecent) {
                return PayResolution::tryAgain();
            }

            $tooCloseToExpiry = $live->expires_at->lessThanOrEqualTo(
                now()->addMinutes(config()->integer('payments.link_min_remaining_minutes')),
            );

            if ($tooCloseToExpiry) {
                $live->update(['status' => PaymentLinkStatus::Canceled]);

                return $this->newPendingLink($invoice);
            }

            return $live; // نكمّل المكالمة بنفس الـ idempotency key
        });
    }

    private function newPendingLink(Invoice $invoice): PaymentLink
    {
        $attempt = ((int) $invoice->paymentLinks()->max('attempt')) + 1;

        return $invoice->paymentLinks()->create([
            'attempt' => $attempt,
            'status' => PaymentLinkStatus::Pending,
            'expires_at' => now()->addMinutes(config()->integer('payments.link_ttl_minutes')),
        ]);
    }

    /**
     * المرحلة 2 و3 لصف pending: نكلم Stripe (من غير lock) وبعدين نسجل النتيجة.
     */
    private function openAtGateway(Invoice $invoice, PaymentLink $link): PayResolution
    {
        $request = new CheckoutSessionRequest(
            amount: Money::of($invoice->total, Currency::from($invoice->currency)),
            description: 'Invoice '.(string) $invoice->number,
            clientReferenceId: $link->uuid,
            idempotencyKey: "invoice-{$invoice->uuid}-link-{$link->attempt}",
            expiresAt: CarbonImmutable::instance($link->expires_at),
            successUrl: config()->string('payments.success_url'),
            cancelUrl: config()->string('payments.cancel_url'),
        );

        try {
            $session = $this->gateway->createCheckoutSession($request);
        } catch (GatewayTimeoutException $e) {
            // النتيجة مجهولة: نسيب الصف pending، والمحاولة الجاية بنفس المفتاح تكمّل
            report($e);

            return PayResolution::tryAgain();
        } catch (GatewayException $e) {
            // رفض نهائي: نحرر مكان اللينك الحي
            report($e);
            $link->update(['status' => PaymentLinkStatus::Canceled]);

            return PayResolution::unavailable();
        }

        return DB::transaction(function () use ($invoice, $link, $session): PayResolution {
            $invoice->lockedCopy(); // نفس ترتيب الـ locks دايماً: الفاتورة ثم اللينك
            $fresh = PaymentLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== PaymentLinkStatus::Pending) {
                // الفاتورة اتلغت وإحنا بنكلم Stripe: نقفل الـ session اللي اتعملت
                ExpireCheckoutSession::dispatch($session->id)->afterCommit();

                return PayResolution::notPayable();
            }

            $fresh->update([
                'stripe_session_id' => $session->id,
                'url' => $session->url,
                'status' => PaymentLinkStatus::Open,
                'expires_at' => $session->expiresAt,
            ]);

            return PayResolution::redirect((string) $session->url);
        });
    }

    /**
     * لينك مفتوح عندنا: نسأل Stripe عن الحقيقة قبل ما نديه للعميل.
     * بيرجّع null لو قفلنا اللينك القديم وعاوزين نلف تاني لإنشاء جديد.
     */
    private function verifyOpenLink(Invoice $invoice, PaymentLink $link): ?PayResolution
    {
        try {
            $session = $this->gateway->retrieveCheckoutSession((string) $link->stripe_session_id);
        } catch (GatewayException $e) {
            report($e);

            return PayResolution::tryAgain();
        }

        if ($session->status === CheckoutSessionStatus::Complete) {
            // الدفع حصل والـ webhook لسه ما وصلش: ما نعملش لينك جديد
            return PayResolution::processing();
        }

        $minutesLeft = now()->diffInMinutes($link->expires_at, false);
        $enoughTime = $minutesLeft > config()->integer('payments.link_min_remaining_minutes');

        if ($session->status === CheckoutSessionStatus::Open && $enoughTime) {
            return PayResolution::redirect((string) $link->url);
        }

        $retiredAs = PaymentLinkStatus::Expired;

        if ($session->status === CheckoutSessionStatus::Open) {
            // لسه مفتوح بس قرّب ينتهي: إحنا اللي بنقفله
            try {
                $this->gateway->expireCheckoutSession($session->id);
            } catch (GatewayException $e) {
                // غالباً اتدفع في اللحظة دي: الزيارة الجاية هتشوف الحقيقة
                report($e);

                return PayResolution::tryAgain();
            }

            $retiredAs = PaymentLinkStatus::Canceled;
        }

        $this->retire($invoice, $link, $retiredAs);

        return null;
    }

    private function retire(Invoice $invoice, PaymentLink $link, PaymentLinkStatus $as): void
    {
        DB::transaction(function () use ($invoice, $link, $as): void {
            $invoice->lockedCopy();
            $fresh = PaymentLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status === PaymentLinkStatus::Open) {
                $fresh->update(['status' => $as]);
            }
        });
    }
}
