<?php

namespace App\Enums;

enum DepreciationMethod: string
{
    case StraightLine = 'slm';
    case WrittenDownValue = 'wdv';
}
