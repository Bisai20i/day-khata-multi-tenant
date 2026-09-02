<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Converted = 'converted';
    case Cancelled = 'cancelled';
}
