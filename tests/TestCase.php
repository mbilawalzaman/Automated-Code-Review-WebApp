<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Satisfy the reauthentication window required by sensitive actions (SEC-009).
     */
    protected function withConfirmedPassword(): static
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);

        return $this;
    }
}
