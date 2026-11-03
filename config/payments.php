<?php

declare(strict_types=1);

return [
    // Phase 3: 'stripe' | 'fake'
    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

    // عمر لينك Stripe (Stripe بيقبل من 30 دقيقة لـ 24 ساعة)
    'link_ttl_minutes' => 720,

    // لو فاضل للينك أقل من كده، نقفله ونعمل جديد بدل ما نديه للعميل
    'link_min_remaining_minutes' => 30,

    // الصف الـ pending اللي أصغر من كده معناه إن حد تاني بيكلم Stripe دلوقتي
    'pending_grace_seconds' => 120,

    // 'success_url' => env('PAYMENT_SUCCESS_URL', 'http://localhost:3000/payment/success'),
    // 'cancel_url' => env('PAYMENT_CANCEL_URL', 'http://localhost:3000/payment/cancel'),
];
