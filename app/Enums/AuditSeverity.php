<?php

namespace App\Enums;

enum AuditSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Info => 'bg-blue-100 text-blue-800 font-semibold',
            self::Warning => 'bg-amber-100 text-amber-800 font-semibold',
            self::Critical => 'bg-red-100 text-red-800 font-semibold',
        };
    }
}
