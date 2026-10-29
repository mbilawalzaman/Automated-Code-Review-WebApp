<?php

namespace App\Services\Admin;

use App\Models\Repository;
use App\Services\Api\GitHubContentService;
use App\Services\Api\GoogleDriveContentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SrsSourceAdapterService
{
    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_GITHUB = 'github';

    public const SOURCE_GOOGLE_DRIVE = 'google_drive';

    public function __construct(
        protected GitHubContentService $gitHubContentService,
        protected GoogleDriveContentService $googleDriveContentService,
        protected SrsTextExtractionService $textExtractor,
    ) {}

    /**
     * @return array<int, string>
     */
    public function availableSources(): array
    {
        $sources = [self::SOURCE_UPLOAD, self::SOURCE_GITHUB];

        if ($this->googleDriveContentService->isConfigured()) {
            $sources[] = self::SOURCE_GOOGLE_DRIVE;
        }

        return $sources;
    }

    /**
     * Retrieve a document from its source, store a private snapshot, and return
     * the plain text used for checklist extraction (SRS-001).
     *
     * @return array{
     *     source_type: string,
     *     source_url: string|null,
     *     file_path: string,
     *     original_filename: string,
     *     content_hash: string,
     *     text: string
     * }
     */
    public function intake(
        string $sourceType,
        ?Repository $repository = null,
        ?UploadedFile $file = null,
        ?string $reference = null,
        ?string $gitRef = null,
    ): array {
        [$content, $filename, $sourceUrl] = match ($sourceType) {
            self::SOURCE_UPLOAD => $this->fromUpload($file),
            self::SOURCE_GITHUB => $this->fromGitHub($repository, $reference, $gitRef),
            self::SOURCE_GOOGLE_DRIVE => $this->fromGoogleDrive($reference),
            default => throw new RuntimeException("Unsupported SRS source '{$sourceType}'."),
        };

        $text = $this->textExtractor->extract($content, $filename);

        if (trim($text) === '') {
            throw new RuntimeException('No readable text was found in this document.');
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'txt';
        $storedPath = 'srs_documents/' . Str::uuid()->toString() . '.' . $extension;
        Storage::disk('local')->put($storedPath, $content);

        return [
            'source_type' => $sourceType,
            'source_url' => $sourceUrl,
            'file_path' => $storedPath,
            'original_filename' => $filename,
            'content_hash' => hash('sha256', $content),
            'text' => $text,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function fromUpload(?UploadedFile $file): array
    {
        if (! $file) {
            throw new RuntimeException('Select a document file to upload.');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $this->textExtractor->supportedExtensions(), true)) {
            throw new RuntimeException('Unsupported file type. Upload Markdown, TXT, PDF, or DOCX.');
        }

        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        return [$contents, $file->getClientOriginalName(), null];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function fromGitHub(?Repository $repository, ?string $reference, ?string $gitRef): array
    {
        if (! $reference) {
            throw new RuntimeException('Provide the document path or GitHub file URL.');
        }

        $fullName = $repository?->full_name ?? '';

        if ($fullName === '' && ! str_starts_with($reference, 'http')) {
            throw new RuntimeException('A repository is required when using a relative GitHub path.');
        }

        $result = $this->gitHubContentService->fetchFile($fullName, $reference, $gitRef);

        return [$result['content'], $result['filename'], $result['source_url']];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function fromGoogleDrive(?string $reference): array
    {
        if (! $reference) {
            throw new RuntimeException('Provide the Google Drive file ID or sharing link.');
        }

        $result = $this->googleDriveContentService->fetchFile($reference);

        return [$result['content'], $result['filename'], $result['source_url']];
    }
}
