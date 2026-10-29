<?php

namespace App\Services\Admin;

use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

class SrsTextExtractionService
{
    /**
     * Convert a document's raw bytes into plain text for checklist extraction.
     * Markdown and text pass straight through; PDF and DOCX are decoded.
     */
    public function extract(string $contents, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $text = match ($extension) {
            'pdf' => $this->fromPdf($contents),
            'docx' => $this->fromDocx($contents),
            'md', 'markdown', 'txt', 'text', '' => $contents,
            default => $this->fromPlainTextOrFail($contents, $extension),
        };

        return $this->normalise($text);
    }

    public function supportedExtensions(): array
    {
        return ['md', 'markdown', 'txt', 'text', 'pdf', 'docx'];
    }

    protected function fromPdf(string $contents): string
    {
        try {
            return (new PdfParser())->parseContent($contents)->getText();
        } catch (\Throwable $e) {
            throw new RuntimeException('Unable to read text from this PDF: ' . $e->getMessage());
        }
    }

    /**
     * DOCX is a zip archive; requirement text lives in word/document.xml.
     */
    protected function fromDocx(string $contents): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'srs_docx_');

        if ($tempPath === false) {
            throw new RuntimeException('Unable to create a temporary file for DOCX extraction.');
        }

        try {
            file_put_contents($tempPath, $contents);

            $zip = new ZipArchive();
            if ($zip->open($tempPath) !== true) {
                throw new RuntimeException('This DOCX file could not be opened.');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml === false) {
                throw new RuntimeException('This DOCX file does not contain a readable document body.');
            }

            // Preserve paragraph and line breaks before stripping markup.
            $xml = preg_replace('#</w:p>#', "\n", $xml) ?? $xml;
            $xml = preg_replace('#<w:br[^>]*/>#', "\n", $xml) ?? $xml;

            return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        } finally {
            @unlink($tempPath);
        }
    }

    protected function fromPlainTextOrFail(string $contents, string $extension): string
    {
        // Accept anything that is genuinely text; reject binaries we cannot parse.
        if (! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            throw new RuntimeException("Unsupported document type '.{$extension}'. Upload Markdown, TXT, PDF, or DOCX.");
        }

        return $contents;
    }

    protected function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
