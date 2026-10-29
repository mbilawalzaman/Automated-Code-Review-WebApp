<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TestQueueJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $message = 'Queue is working'
    ) {}

    public function handle(): void
    {
        Log::info('TestQueueJob executed: '.$this->message);
    }
}
