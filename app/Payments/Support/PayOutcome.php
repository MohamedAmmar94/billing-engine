<?php

declare(strict_types=1);

namespace App\Payments\Support;

enum PayOutcome
{
    case Redirect;
    case AlreadyPaid;
    case Processing;
    case TryAgain;
    case NotPayable;
    case Unavailable;
}
