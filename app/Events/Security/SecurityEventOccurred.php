<?php

namespace App\Events\Security;

use App\Enums\AuditSeverity;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecurityEventOccurred
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $action,
        public AuditSeverity $severity,
        public array $metadata = [],
        public ?User $actor = null,
        public ?string $reason = null
    ) {}
}
