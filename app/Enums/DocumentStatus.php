<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Active = 'active';
    case Voided = 'voided';
}
