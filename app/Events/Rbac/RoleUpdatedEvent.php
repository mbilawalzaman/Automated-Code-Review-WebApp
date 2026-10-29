<?php

namespace App\Events\Rbac;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoleUpdatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Role $role,
        public string $action,
        public array $beforeState,
        public array $afterState,
        public ?User $actor = null
    ) {}
}
