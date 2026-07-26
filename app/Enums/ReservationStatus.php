<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';
    case Reserved = 'reserved';
    case PartiallyShipped = 'partially_shipped';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
