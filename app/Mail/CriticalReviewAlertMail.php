<?php

namespace App\Mail;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CriticalReviewAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $criticalFindings
     */
    public function __construct(
        public Review $review,
        public array $criticalFindings,
        public string $dashboardUrl,
    ) {}

    public function envelope(): Envelope
    {
        $repo = $this->review->repository?->full_name ?? 'unknown repository';
        $pr = $this->review->pull_request_number
            ? ' PR #'.$this->review->pull_request_number
            : '';

        return new Envelope(
            subject: '[Critical] '.$repo.$pr.' — automated review failed',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.critical-review-alert',
        );
    }
}
