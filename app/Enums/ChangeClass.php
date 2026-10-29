<?php

namespace App\Enums;

enum ChangeClass: string
{
    case DestructiveMigration = 'destructive_migration';
    case ApiContract = 'api_contract';
    case Auth = 'auth';
    case CiCd = 'ci_cd';
    case Secrets = 'secrets';
    case UnsignedCommit = 'unsigned_commit';

    public function label(): string
    {
        return match ($this) {
            self::DestructiveMigration => 'Destructive migration',
            self::ApiContract => 'Breaking API contract',
            self::Auth => 'Authentication / authorisation',
            self::CiCd => 'CI/CD / deployment',
            self::Secrets => 'Secrets / credentials config',
            self::UnsignedCommit => 'Unsigned commit',
        };
    }

    public function requiresPmApproval(): bool
    {
        return match ($this) {
            self::DestructiveMigration, self::ApiContract, self::Auth, self::CiCd, self::Secrets => true,
            self::UnsignedCommit => false,
        };
    }

    public function requiresVerifiedBackup(): bool
    {
        return $this === self::DestructiveMigration;
    }

    public function requiresDocumentation(): bool
    {
        return $this === self::ApiContract;
    }
}
