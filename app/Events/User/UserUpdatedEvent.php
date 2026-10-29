<?php

namespace App\Events\User;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserUpdatedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public array $beforeState,
        public array $afterState,
        public ?User $actor = null
    ) {}
}
