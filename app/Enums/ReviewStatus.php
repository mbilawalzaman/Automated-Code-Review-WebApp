<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case Passed = 'passed';
    case PassedWithWarnings = 'passed_with_warnings';
    case Failed = 'failed';
    case ReviewError = 'review_error';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::PassedWithWarnings => 'Passed with Warnings',
            self::Failed => 'Failed',
            self::ReviewError => 'Review Error',
        };
    }
}
