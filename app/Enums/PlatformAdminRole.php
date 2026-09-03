<?php

namespace App\Enums;

enum PlatformAdminRole: string
{
    case Owner = 'owner';
    case Support = 'support';
}
