<?php

namespace App\Enums;

enum IntegrationType: string
{
    case GitHub = 'github';
    case Trello = 'trello';
    case GoogleDrive = 'google_drive';
    case Email = 'email';
}
