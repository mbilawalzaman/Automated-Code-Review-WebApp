<?php

namespace App\Services\Admin;

use App\Models\Report;
use App\Models\Review;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class ReportPdfService
{
    /**
     * Bump when the Blade PDF template layout changes so cached reports regenerate.
     */
    public const TEMPLATE_VERSION = 2;

    /**
     * Generates a true PDF binary file for a Review and saves it to application storage.
     * Returns the relative storage path.
     */
    public function generateForReview(Review $review): string
    {
        $review->loadMissing(['repository.project', 'findings']);

        // Compile HTML to binary PDF using DomPDF
        $pdf = Pdf::loadView('pdf.review-report', ['review' => $review])
            ->setPaper('a4', 'portrait')
            ->setWarnings(false);

        $fileName = $this->storagePathForReview($review);

        // Store compiled binary PDF output in configured application storage
        Storage::disk('local')->put($fileName, $pdf->output());

        return $fileName;
    }

    /**
     * Retrieves the PDF binary content for a Report, lazily generating or updating if missing/legacy HTML format.
     */
    public function getOrGeneratePdf(Report $report): string
    {
        $filePath = $report->file_path;

        if ($filePath && Storage::disk('local')->exists($filePath)) {
            $content = Storage::disk('local')->get($filePath);
            // If it's a valid generated PDF (or non-HTML document), serve directly
            if (! str_starts_with(trim($content), '<!DOCTYPE') && ! str_starts_with(trim($content), '<html')) {
                return $content;
            }
        }

        $review = $report->review ?? Review::find($report->review_id);
        if (! $review) {
            throw new \RuntimeException("Cannot generate PDF for Report #{$report->id}: associated Review not found.");
        }

        $filePath = $this->generateForReview($review);
        $report->update(['file_path' => $filePath]);

        return Storage::disk('local')->get($filePath);
    }

    private function storagePathForReview(Review $review): string
    {
        $repoName = $review->repository?->name ?? 'default-repo';

        return "reports/{$repoName}/review-{$review->id}-v".self::TEMPLATE_VERSION.'.pdf';
    }
}
