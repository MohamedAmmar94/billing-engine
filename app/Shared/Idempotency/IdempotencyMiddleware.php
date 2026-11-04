<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class IdempotencyMiddleware
{
    private const MAX_CLAIM_ROUNDS = 3;

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request); // الـ auth middleware هو اللي بيرفض قبل كده
        }

        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return $this->problem('idempotency_key_missing', 'The Idempotency-Key header is required.', 400);
        }

        if (preg_match('/^[A-Za-z0-9_-]{8,255}$/', $key) !== 1) {
            return $this->problem(
                'idempotency_key_invalid',
                'The Idempotency-Key must be 8-255 characters: letters, digits, "-" and "_".',
                400,
            );
        }

        $userId = (int) $user->getAuthIdentifier();
        $hash = $this->fingerprint($request);

        for ($round = 0; $round < self::MAX_CLAIM_ROUNDS; $round++) {
            $token = (string) Str::uuid();

            // INSERT ... ON CONFLICT DO NOTHING: من غير exception، والـ UNIQUE هو اللي بيحسم السباق
            $inserted = DB::table('idempotency_keys')->insertOrIgnore([
                'user_id' => $userId,
                'key' => $key,
                'request_hash' => $hash,
                'lock_token' => $token,
                'locked_at' => now(),
                'expires_at' => now()->addHours(config()->integer('idempotency.ttl_hours')),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 1) {
                return $this->execute($request, $next, $userId, $key, $token);
            }

            $existing = DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->first();

            if ($existing === null) {
                continue; // اتحرر في اللحظة دي: نحاول تاني
            }

            if (Carbon::parse($existing->expires_at)->lessThanOrEqualTo(now())) {
                DB::table('idempotency_keys')->where('id', $existing->id)->delete();

                continue; // منتهي: نتعامل معاه كأنه مش موجود
            }

            if ($existing->request_hash !== $hash) {
                return $this->problem(
                    'idempotency_key_reused',
                    'This Idempotency-Key was already used with a different request.',
                    422,
                );
            }

            if ($existing->response_status !== null) {
                return $this->replay($existing);
            }

            $abandoned = Carbon::parse($existing->locked_at)->lessThanOrEqualTo(
                now()->subSeconds(config()->integer('idempotency.lock_timeout_seconds')),
            );

            if (! $abandoned) {
                return $this->inProgress();
            }

            // الطلب الأول متعطل: نتولاه بتغيير الـ token (الأول مش هيقدر يكمل بعد كده)
            $taken = DB::table('idempotency_keys')
                ->where('id', $existing->id)
                ->where('lock_token', $existing->lock_token)
                ->whereNull('response_status')
                ->update(['lock_token' => $token, 'locked_at' => now(), 'updated_at' => now()]);

            if ($taken === 1) {
                return $this->execute($request, $next, $userId, $key, $token);
            }
            // حد تاني سبقنا في التولي: نلف ونشوف الحالة الجديدة
        }

        return $this->inProgress();
    }

    /**
     * التنفيذ والرد المخزن في transaction واحدة، فمستحيل يحصل "اتنفذ ومفيش رد مخزن".
     *
     * @param  Closure(Request): Response  $next
     */
    private function execute(Request $request, Closure $next, int $userId, string $key, string $token): Response
    {
        DB::beginTransaction();

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            DB::rollBack();
            $this->release($userId, $key, $token);

            throw $e;
        }

        $status = $response->getStatusCode();

        if (! $this->isFinal($status)) {
            // 5xx أو 429...: نلغي الشغل ونحرر المفتاح، وإعادة المحاولة بتنفذ من جديد
            DB::rollBack();
            $this->release($userId, $key, $token);

            return $response;
        }

        $saved = DB::table('idempotency_keys')
            ->where('user_id', $userId)
            ->where('key', $key)
            ->where('lock_token', $token)
            ->whereNull('response_status')
            ->update([
                'response_status' => $status,
                'response_body' => (string) $response->getContent(),
                'updated_at' => now(),
            ]);

        if ($saved !== 1) {
            // اتولّى منا أثناء التنفيذ (الـ fencing): نلغي شغلنا عشان محاولة واحدة بس تكمّل
            DB::rollBack();

            return $this->inProgress();
        }

        DB::commit();

        return $response;
    }

    /** النتائج الحتمية بس هي اللي بتتخزن وتتكرر. */
    private function isFinal(int $status): bool
    {
        return ($status >= 200 && $status < 300) || in_array($status, [404, 409, 422], true);
    }

    private function release(int $userId, string $key, string $token): void
    {
        DB::table('idempotency_keys')
            ->where('user_id', $userId)
            ->where('key', $key)
            ->where('lock_token', $token)
            ->delete();
    }

    private function replay(stdClass $row): JsonResponse
    {
        return JsonResponse::fromJsonString(
            (string) $row->response_body,
            (int) $row->response_status,
            ['Idempotent-Replayed' => 'true'],
        );
    }

    private function inProgress(): JsonResponse
    {
        return $this->problem(
            'idempotency_in_progress',
            'A request with this Idempotency-Key is still being processed. Retry with the same key.',
            409,
            ['Retry-After' => (string) config()->integer('idempotency.retry_after_seconds')],
        );
    }

    /** @param  array<string, string>  $headers */
    private function problem(string $code, string $message, int $status, array $headers = []): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status, $headers);
    }

    /** بصمة الطلب: method + path + body مرتب (ترتيب المفاتيح ما يفرقش، ترتيب القوائم يفرق). */
    private function fingerprint(Request $request): string
    {
        $body = json_encode($this->canonical($request->all()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $request->method()."\n".$request->path()."\n".$body);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }
}
