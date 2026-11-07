<?php

namespace App\Enums;

enum SyncRunTriggerType: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
