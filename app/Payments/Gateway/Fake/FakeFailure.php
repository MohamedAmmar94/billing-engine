<?php

declare(strict_types=1);

namespace App\Payments\Gateway\Fake;

enum FakeFailure: string
{
    case TimeoutBeforeEffect = 'timeout_before_effect';
    case TimeoutAfterEffect = 'timeout_after_effect';
    case Rejected = 'rejected';
}
