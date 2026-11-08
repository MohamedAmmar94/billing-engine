<?php

declare(strict_types=1);

namespace App\Payments\Enums;

enum PaymentRecordingOutcomeEnum
{
    case Applied;
    case NotApplied;
    case AlreadyRecorded;
    case UnknownLink;
    case NotPaid;
}
