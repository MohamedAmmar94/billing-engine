<?php

declare(strict_types=1);

namespace App\Payments\Actions;

use App\Billing\Enums\InvoiceStatusEnum;
use App\Billing\Models\Invoice;
use App\Payments\Enums\PaymentLinkStatusEnum;
use App\Payments\Gateway\CheckoutSessionRequestDTO;
use App\Payments\Gateway\CheckoutSessionStatusEnum;
use App\Payments\Gateway\GatewayException;
use App\Payments\Gateway\GatewayTimeoutException;
use App\Payments\Gateway\PaymentGatewayInterface;
use App\Payments\Jobs\ExpireCheckoutSessionJob;
use App\Payments\Models\PaymentLink;
use App\Payments\Support\PayResolutionDTO;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ResolvePaymentLinkAction
{
    private const MAX_ROUNDS = 3;

    public function __construct(private readonly PaymentGatewayInterface $gateway) {}

    public function handle(Invoice $invoice): PayResolutionDTO
    {
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            try {
                $step = $this->decide($invoice);
            } catch (UniqueConstraintViolationException) {
                // شبكة أمان: الـ DB رفضت لينك حي تاني (الـ lock المفروض يمنع ده أصلاً)
                return PayResolutionDTO::tryAgain();
            }

            if ($step instanceof PayResolutionDTO) {
                return $step;
            }

            if ($step->status === PaymentLinkStatusEnum::Pending) {
                return $this->openAtGateway($invoice, $step);
            }

            $resolution = $this->verifyOpenLink($invoice, $step);

            if ($resolution !== null) {
                return $resolution;
            }
            // اللينك القديم اتقفل، نلف تاني وندي العميل لينك جديد
        }

        return PayResolutionDTO::tryAgain();
    }

    /**
     * المرحلة 1: قرار من الـ DB بس (بدون شبكة) تحت lock على الفاتورة.
     */
    private function decide(Invoice $invoice): PayResolutionDTO|PaymentLink
    {
        return DB::transaction(function () use ($invoice): PayResolutionDTO|PaymentLink {
            $invoice = $invoice->lockedCopy();

            if ($invoice->status === InvoiceStatusEnum::Paid) {
                return PayResolutionDTO::alreadyPaid();
            }

            if ($invoice->status !== InvoiceStatusEnum::Open) {
                return PayResolutionDTO::notPayable();
            }

            $live = $invoice->paymentLinks()
                ->whereIn('status', PaymentLinkStatusEnum::liveValues())
                ->first();

            if ($live === null) {
                return $this->newPendingLink($invoice);
            }

            if ($live->status === PaymentLinkStatusEnum::Open) {
                return $live;
            }

            // pending: إما حد بيكلم Stripe دلوقتي، أو العملية وقعت في النص
            $isRecent = $live->created_at?->greaterThan(
                now()->subSeconds(config()->integer('payments.pending_grace_seconds')),
            ) ?? false;

            if ($isRecent) {
                return PayResolutionDTO::tryAgain();
            }

            $tooCloseToExpiry = $live->expires_at->lessThanOrEqualTo(
                now()->addMinutes(config()->integer('payments.link_min_remaining_minutes')),
            );

            if ($tooCloseToExpiry) {
                $live->update(['status' => PaymentLinkStatusEnum::Canceled]);

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
            'status' => PaymentLinkStatusEnum::Pending,
            'expires_at' => now()->addMinutes(config()->integer('payments.link_ttl_minutes')),
        ]);
    }

    /**
     * المرحلة 2 و3 لصف pending: نكلم Stripe (من غير lock) وبعدين نسجل النتيجة.
     */
    private function openAtGateway(Invoice $invoice, PaymentLink $link): PayResolutionDTO
    {
        $request = new CheckoutSessionRequestDTO(
            amount: Money::of($invoice->total, CurrencyEnum::from($invoice->currency)),
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

            return PayResolutionDTO::tryAgain();
        } catch (GatewayException $e) {
            // رفض نهائي: نحرر مكان اللينك الحي
            report($e);
            $link->update(['status' => PaymentLinkStatusEnum::Canceled]);

            return PayResolutionDTO::unavailable();
        }

        return DB::transaction(function () use ($invoice, $link, $session): PayResolutionDTO {
            $invoice->lockedCopy(); // نفس ترتيب الـ locks دايماً: الفاتورة ثم اللينك
            $fresh = PaymentLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== PaymentLinkStatusEnum::Pending) {
                // الفاتورة اتلغت وإحنا بنكلم Stripe: نقفل الـ session اللي اتعملت
                ExpireCheckoutSessionJob::dispatch($session->id)->afterCommit();

                return PayResolutionDTO::notPayable();
            }

            $fresh->update([
                'stripe_session_id' => $session->id,
                'url' => $session->url,
                'status' => PaymentLinkStatusEnum::Open,
                'expires_at' => $session->expiresAt,
            ]);

            return PayResolutionDTO::redirect((string) $session->url);
        });
    }

    /**
     * لينك مفتوح عندنا: نسأل Stripe عن الحقيقة قبل ما نديه للعميل.
     * بيرجّع null لو قفلنا اللينك القديم وعاوزين نلف تاني لإنشاء جديد.
     */
    private function verifyOpenLink(Invoice $invoice, PaymentLink $link): ?PayResolutionDTO
    {
        try {
            $session = $this->gateway->retrieveCheckoutSession((string) $link->stripe_session_id);
        } catch (GatewayException $e) {
            report($e);

            return PayResolutionDTO::tryAgain();
        }

        if ($session->status === CheckoutSessionStatusEnum::Complete) {
            // الدفع حصل والـ webhook لسه ما وصلش: ما نعملش لينك جديد
            return PayResolutionDTO::processing();
        }

        $minutesLeft = now()->diffInMinutes($link->expires_at, false);
        $enoughTime = $minutesLeft > config()->integer('payments.link_min_remaining_minutes');

        if ($session->status === CheckoutSessionStatusEnum::Open && $enoughTime) {
            return PayResolutionDTO::redirect((string) $link->url);
        }

        $retiredAs = PaymentLinkStatusEnum::Expired;

        if ($session->status === CheckoutSessionStatusEnum::Open) {
            // لسه مفتوح بس قرّب ينتهي: إحنا اللي بنقفله
            try {
                $this->gateway->expireCheckoutSession($session->id);
            } catch (GatewayException $e) {
                // غالباً اتدفع في اللحظة دي: الزيارة الجاية هتشوف الحقيقة
                report($e);

                return PayResolutionDTO::tryAgain();
            }

            $retiredAs = PaymentLinkStatusEnum::Canceled;
        }

        $this->retire($invoice, $link, $retiredAs);

        return null;
    }

    private function retire(Invoice $invoice, PaymentLink $link, PaymentLinkStatusEnum $as): void
    {
        DB::transaction(function () use ($invoice, $link, $as): void {
            $invoice->lockedCopy();
            $fresh = PaymentLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status === PaymentLinkStatusEnum::Open) {
                $fresh->update(['status' => $as]);
            }
        });
    }
}
