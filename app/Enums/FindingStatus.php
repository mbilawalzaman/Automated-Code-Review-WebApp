<?php

namespace App\Enums;

enum FindingStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case FalsePositive = 'false_positive';
    case Accepted = 'accepted';
}
