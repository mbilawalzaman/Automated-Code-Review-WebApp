<?php

namespace App\Enums;

enum RepositoryStatus: string
{
    case Disconnected = 'disconnected';
    case Connected = 'connected';
    case Paused = 'paused';
    case Active = 'active';

    public function label(): string
    {
        return match ($this) {
            self::Disconnected => 'Disconnected',
            self::Connected => 'Connected',
            self::Paused => 'Paused',
            self::Active => 'Active',
        };
    }
}
