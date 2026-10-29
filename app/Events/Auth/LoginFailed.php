<?php

namespace App\Events\Auth;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LoginFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $email,
        public ?string $ipAddress = null,
        public ?string $userAgent = null
    ) {}
}
