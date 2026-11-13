<?php

namespace App\Domain\Vault\Store\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * REST calls for one repository (plan §5.1): Laravel `Http`, an installation
 * token or a fine-grained PAT, conditional GETs on the ref, rate-limit headers
 * fed to the write budget, 3 s / 10 s timeouts. Never shells out to `git`.
 *
 * Only the Git database API is used for writes (blobs, trees, commits, refs),
 * plus one `PUT /contents` to create the first commit of an empty repository,
 * which the Git database API refuses (409).
 */
class GitHubClient
{
    /** Token-expiration header of the last response (fine-grained PATs). */
    public ?string $tokenExpiration = null;

    /** `x-ratelimit-remaining` of the last response. */
    public ?int $rateRemaining = null;

    private ?string $installationTokenCache = null;

    private int $installationTokenExpires = 0;

    public function __construct(
        public readonly string $owner,
        public readonly string $repo,
        private readonly ?int $installationId = null,
        private readonly ?string $personalToken = null,
        private readonly GitHubAppAuth $app = new GitHubAppAuth,
        private readonly ?GitHubWriteBudget $budget = null,
    ) {}

    public function usesApp(): bool
    {
        return $this->installationId !== null;
    }

    /** Write-budget key: the installation (App) or the token's owner (PAT). Never the token itself. */
    public function actorKey(): string
    {
        return $this->installationId !== null
            ? 'installation:'.$this->installationId
            : 'pat:'.substr(hash('sha256', (string) $this->personalToken), 0, 32);
    }

    /**
     * @return array<string, mixed>
     */
    public function repository(): array
    {
        return $this->request('GET', '')->json() ?? [];
    }

    /** The branch head, or why there is none. With $etag, `notModified` costs no quota. */
    public function ref(string $branch, ?string $etag = null): GitHubRef
    {
        try {
            $response = $this->request('GET', '/git/ref/heads/'.$this->encodePath($branch), etag: $etag, allow: [304, 404, 409]);
        } catch (GitHubApiError $error) {
            throw $error;
        }

        return match ($response->status()) {
            304 => GitHubRef::notModified($etag),
            404 => GitHubRef::missing(),
            409 => GitHubRef::empty(),
            default => is_array($response->json('object'))
                ? GitHubRef::at((string) $response->json('object.sha'), $response->header('ETag') ?: null)
                // GET /git/ref/heads/x answers a list when x is only a prefix of branch names.
                : GitHubRef::missing(),
        };
    }

    /**
     * @return array{sha: string, tree: string, parents: list<string>, message: string}
     */
    public function commit(string $sha): array
    {
        $json = $this->request('GET', '/git/commits/'.$sha)->json() ?? [];

        return [
            'sha' => (string) ($json['sha'] ?? $sha),
            'tree' => (string) ($json['tree']['sha'] ?? ''),
            'parents' => array_values(array_map(static fn (array $p): string => (string) $p['sha'], (array) ($json['parents'] ?? []))),
            'message' => (string) ($json['message'] ?? ''),
        ];
    }

    /**
     * Every entry of a tree, recursively.
     *
     * @return array{entries: list<array{path: string, mode: string, type: string, sha: string, size: ?int}>, truncated: bool}
     */
    public function tree(string $sha): array
    {
        $json = $this->request('GET', '/git/trees/'.$sha, query: ['recursive' => '1'])->json() ?? [];
        $entries = [];
        foreach ((array) ($json['tree'] ?? []) as $entry) {
            $entries[] = [
                'path' => (string) $entry['path'],
                'mode' => (string) ($entry['mode'] ?? ''),
                'type' => (string) ($entry['type'] ?? ''),
                'sha' => (string) ($entry['sha'] ?? ''),
                'size' => isset($entry['size']) ? (int) $entry['size'] : null,
            ];
        }

        return ['entries' => $entries, 'truncated' => (bool) ($json['truncated'] ?? false)];
    }

    public function blob(string $sha): string
    {
        $json = $this->request('GET', '/git/blobs/'.$sha)->json() ?? [];
        $content = (string) ($json['content'] ?? '');

        if (($json['encoding'] ?? 'base64') === 'base64') {
            $decoded = base64_decode(str_replace(["\n", "\r"], '', $content), true);
            if ($decoded === false) {
                throw new GitHubApiError("GitHub returned an undecodable blob [{$sha}].");
            }

            return $decoded;
        }

        return $content;
    }

    /**
     * Files changed between two commits (at most 300, GitHub's limit).
     *
     * @return array{status: string, total_commits: int, files: list<array{filename: string, status: string, sha: ?string, previous_filename: ?string}>, truncated: bool}
     */
    public function compare(string $base, string $head): array
    {
        $json = $this->request('GET', '/compare/'.$base.'...'.$head, query: ['per_page' => 100])->json() ?? [];
        $files = [];
        foreach ((array) ($json['files'] ?? []) as $file) {
            $files[] = [
                'filename' => (string) $file['filename'],
                'status' => (string) ($file['status'] ?? 'modified'),
                'sha' => isset($file['sha']) ? (string) $file['sha'] : null,
                'previous_filename' => isset($file['previous_filename']) ? (string) $file['previous_filename'] : null,
            ];
        }

        return [
            'status' => (string) ($json['status'] ?? 'diverged'),
            'total_commits' => (int) ($json['total_commits'] ?? 0),
            'files' => $files,
            'truncated' => count($files) >= 300,
        ];
    }

    /** POST /git/blobs (1 write). */
    public function createBlob(string $contents): string
    {
        return (string) $this->request('POST', '/git/blobs', [
            'content' => base64_encode($contents),
            'encoding' => 'base64',
        ], write: true)->json('sha');
    }

    /**
     * POST /git/trees (1 write). Entries: `content` (inline text), `sha` (blob) or `sha: null` (delete).
     *
     * @param  list<array<string, mixed>>  $entries
     */
    public function createTree(?string $baseTree, array $entries): string
    {
        $body = ['tree' => $entries];
        if ($baseTree !== null) {
            $body['base_tree'] = $baseTree;
        }

        return (string) $this->request('POST', '/git/trees', $body, write: true)->json('sha');
    }

    /**
     * POST /git/commits (1 write).
     *
     * @param  list<string>  $parents
     */
    public function createCommit(string $message, string $tree, array $parents): string
    {
        return (string) $this->request('POST', '/git/commits', [
            'message' => $message,
            'tree' => $tree,
            'parents' => $parents,
        ], write: true)->json('sha');
    }

    /** PATCH /git/refs (1 write), never forced: a concurrent push answers 422. */
    public function updateRef(string $branch, string $sha): void
    {
        $this->request('PATCH', '/git/refs/heads/'.$this->encodePath($branch), [
            'sha' => $sha,
            'force' => false,
        ], write: true);
    }

    /** POST /git/refs (1 write): the branch does not exist yet. */
    public function createRef(string $branch, string $sha): void
    {
        $this->request('POST', '/git/refs', [
            'ref' => 'refs/heads/'.$branch,
            'sha' => $sha,
        ], write: true);
    }

    /**
     * PUT /contents (1 write): the only way to create the first commit of an
     * empty repository. Returns the new commit SHA.
     */
    public function createFirstFile(string $branch, string $path, string $contents, string $message): string
    {
        return (string) $this->request('PUT', '/contents/'.$this->encodePath($path), [
            'message' => $message,
            'content' => base64_encode($contents),
            'branch' => $branch,
        ], write: true)->json('commit.sha');
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @param  list<int>  $allow  non-2xx statuses returned instead of thrown
     */
    private function request(string $method, string $path, ?array $body = null, array $query = [], bool $write = false, ?string $etag = null, array $allow = []): Response
    {
        if ($write && $this->budget !== null) {
            $this->budget->assertAvailable($this->actorKey());
        }

        $headers = $this->app->headers($this->authorization());
        if ($etag !== null) {
            $headers['If-None-Match'] = $etag;
        }

        $url = $this->app->apiUrl('/repos/'.rawurlencode($this->owner).'/'.rawurlencode($this->repo).$path);
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        try {
            $pending = Http::withHeaders($headers)
                ->connectTimeout((int) config('jotter.github.connect_timeout', 3))
                ->timeout((int) config('jotter.github.timeout', 10));
            $response = match ($method) {
                'GET' => $pending->get($url),
                'POST' => $pending->post($url, $body ?? []),
                'PATCH' => $pending->patch($url, $body ?? []),
                'PUT' => $pending->put($url, $body ?? []),
                'DELETE' => $pending->delete($url, $body ?? []),
                default => throw new \InvalidArgumentException("Unsupported method {$method}"),
            };
        } catch (ConnectionException $exception) {
            throw new GitHubApiError('GitHub is unreachable: '.$this->scrub($exception->getMessage()));
        }

        $this->observe($response, $write);

        if ($response->successful() || in_array($response->status(), $allow, true)) {
            return $response;
        }

        $message = (string) ($response->json('message') ?? '');
        $retryAfter = self::retryAfterOf($response);
        if ($retryAfter !== null && $this->budget !== null) {
            $this->budget->block($this->actorKey(), $retryAfter);
        }
        if ($response->status() === 401 && $this->installationId !== null) {
            $this->installationTokenCache = null;
            $this->app->forgetInstallationTokens($this->installationId, $this->repo);
        }

        throw new GitHubApiError(
            "GitHub {$method} {$path} answered HTTP {$response->status()}".($message !== '' ? ": {$message}" : '').'.',
            $response->status(),
            $retryAfter,
        );
    }

    private function observe(Response $response, bool $write): void
    {
        $remaining = $response->header('x-ratelimit-remaining');
        $this->rateRemaining = $remaining === '' ? $this->rateRemaining : (int) $remaining;
        $expiration = $response->header('github-authentication-token-expiration');
        if ($expiration !== '') {
            $this->tokenExpiration = $expiration;
        }

        if ($write && $this->budget !== null && $response->successful()) {
            $this->budget->consume($this->actorKey(), 1);
        }
        if ($this->budget !== null && $remaining !== '' && (int) $remaining === 0) {
            $reset = (int) $response->header('x-ratelimit-reset');
            $this->budget->block($this->actorKey(), max(1, $reset - time()));
        }
    }

    /** Seconds to wait from `retry-after`, or from an exhausted primary limit; null when not rate-limited. */
    public static function retryAfterOf(Response $response): ?int
    {
        if (! in_array($response->status(), [403, 429], true)) {
            return null;
        }

        $retryAfter = $response->header('retry-after');
        if ($retryAfter !== '') {
            return max(1, (int) $retryAfter);
        }
        if ($response->header('x-ratelimit-remaining') === '0') {
            return max(1, (int) $response->header('x-ratelimit-reset') - time());
        }

        $message = strtolower((string) ($response->json('message') ?? ''));

        return str_contains($message, 'rate limit') ? 60 : null;
    }

    private function authorization(): string
    {
        if ($this->installationId === null) {
            if ($this->personalToken === null || $this->personalToken === '') {
                throw new GitHubApiError('No GitHub credentials: connect the GitHub App or paste a fine-grained token.', 401);
            }

            return 'Bearer '.$this->personalToken;
        }

        if ($this->installationTokenCache === null || $this->installationTokenExpires <= time() + 300) {
            $token = $this->app->installationToken($this->installationId, $this->repo);
            $this->installationTokenCache = $token['token'];
            $this->installationTokenExpires = $token['expires_at'];
        }

        return 'Bearer '.$this->installationTokenCache;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function scrub(string $message): string
    {
        foreach ([$this->personalToken, $this->installationTokenCache] as $secret) {
            if ($secret !== null && $secret !== '') {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }

        return $message;
    }
}
