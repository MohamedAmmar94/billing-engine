<?php

declare(strict_types=1);

namespace App\Payments\Gateway;

/** النتيجة مجهولة: الطلب ممكن يكون نجح عند المزوّد من غير ما نعرف. */
final class GatewayTimeoutException extends GatewayException {}
