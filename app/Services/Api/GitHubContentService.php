<?php

namespace App\Services\Api;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubContentService
{
    public function __construct(
        protected GitHubAppTokenService $tokenService
    ) {}

    /**
     * Fetch a single file's contents from a repository (SRS-001 GitHub source).
     *
     * Accepts either a repository-relative path ("docs/SRS.md") or a full GitHub
     * blob URL, which is normalised to owner/repo + path + ref.
     *
     * @return array{content: string, filename: string, source_url: string}
     */
    public function fetchFile(string $fullName, string $pathOrUrl, ?string $ref = null): array
    {
        $token = $this->tokenService->getAccessToken();

        if (empty($token)) {
            throw new RuntimeException('GitHub credentials are not configured on this environment.');
        }

        [$fullName, $path, $ref] = $this->normalise($fullName, $pathOrUrl, $ref);

        if ($path === '') {
            throw new RuntimeException('A repository file path is required for the GitHub source.');
        }

        $endpoint = config('github.api_base').'/repos/'.trim($fullName, '/').'/contents/'.ltrim($path, '/');

        $response = Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->get($endpoint, $ref ? ['ref' => $ref] : []);

        if ($response->status() === 404) {
            throw new RuntimeException("File '{$path}' was not found in {$fullName}.");
        }

        if (! $response->successful()) {
            throw new RuntimeException("GitHub content API returned HTTP {$response->status()} for {$fullName}/{$path}.");
        }

        if ($response->json('type') !== 'file') {
            throw new RuntimeException("'{$path}' is a directory; select a single document file.");
        }

        $encoded = (string) $response->json('content', '');
        $content = base64_decode(str_replace("\n", '', $encoded), true);

        if ($content === false) {
            throw new RuntimeException("Unable to decode contents of '{$path}'.");
        }

        return [
            'content' => $content,
            'filename' => (string) $response->json('name', basename($path)),
            'source_url' => (string) $response->json('html_url', $endpoint),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function normalise(string $fullName, string $pathOrUrl, ?string $ref): array
    {
        if (! str_starts_with($pathOrUrl, 'http')) {
            return [$fullName, $pathOrUrl, $ref];
        }

        // https://github.com/{owner}/{repo}/blob/{ref}/{path}
        if (preg_match('#github\.com/([^/]+/[^/]+)/(?:blob|raw)/([^/]+)/(.+)$#', $pathOrUrl, $matches)) {
            return [$matches[1], $matches[3], $ref ?: $matches[2]];
        }

        throw new RuntimeException('Unrecognised GitHub file URL. Use a blob URL or a repository-relative path.');
    }
}
