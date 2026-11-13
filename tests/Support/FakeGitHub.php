<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * An in-memory GitHub REST API for tests (#440): repositories with refs,
 * commits, flat trees and blobs hashed like Git, conditional GETs on refs
 * (ETag / 304), compare, non-fast-forward 422, the 409 an empty repository
 * gives the Git database API, `PUT /contents` for the first commit, App
 * installation tokens checked against a JWT signed with a key generated per
 * test, and rate-limit answers on demand.
 *
 * Install with `FakeGitHub::install()`; the fake is the only HTTP the test sees.
 */
final class FakeGitHub
{
    /** @var array<string, array{branches: array<string, string>, commits: array<string, array{tree: string, parents: list<string>, message: string}>, trees: array<string, array<string, array{sha: string, mode: string}>>, blobs: array<string, string>, private: bool, push: bool, size: int}> */
    public array $repos = [];

    /** @var list<array{method: string, path: string, write: bool}> */
    public array $requests = [];

    /** @var array<string, list<string>> installation id → repositories it may access ("owner/repo") */
    public array $installations = [];

    /** @var array<string, array{installation: int, repos: ?list<string>, expires: int}> */
    public array $tokens = [];

    /** @var array<string, string> PAT → "owner/repo" it may access ('*' = any) */
    public array $pats = [];

    public ?string $appPublicKey = null;

    /** Headers added to every answer (e.g. a PAT's expiration). */
    public array $extraHeaders = [];

    public ?string $appId = null;

    /** Answer the next N write requests with a secondary-rate-limit 403. */
    public int $rateLimitWrites = 0;

    /** Answer every request with a 5xx (GitHub down). */
    public bool $down = false;

    /** Fail the next PATCH of a ref with a simulated crash (exception) after GitHub applied nothing. */
    public bool $crashBeforePatch = false;

    /** Run this callback right before a ref PATCH is applied (simulate a concurrent push). */
    public ?\Closure $beforePatch = null;

    public static function install(): self
    {
        $fake = new self;
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake->handle($request));

        return $fake;
    }

    /**
     * @param  array<string, string>  $files  path → contents (no commit when empty)
     */
    public function createRepo(string $fullName, array $files = [], string $branch = 'main', bool $push = true): self
    {
        $this->repos[strtolower($fullName)] = [
            'branches' => [], 'commits' => [], 'trees' => [], 'blobs' => [],
            'private' => true, 'push' => $push, 'size' => 0,
        ];
        if ($files !== []) {
            $this->commitFiles($fullName, $branch, $files, 'initial', parents: []);
        }

        return $this;
    }

    /** A personal access token allowed on one repository. */
    public function allowPat(string $token, string $fullName = '*'): self
    {
        $this->pats[$token] = strtolower($fullName);

        return $this;
    }

    /**
     * An App with a fresh RSA key; returns the private key PEM (written by the test to a temp file).
     */
    public function createApp(string $appId = '12345'): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->appPublicKey = openssl_pkey_get_details($key)['key'];
        $this->appId = $appId;

        return $pem;
    }

    /** @param list<string> $repos */
    public function installApp(int $installationId, array $repos): self
    {
        $this->installations[(string) $installationId] = array_map('strtolower', $repos);

        return $this;
    }

    /**
     * A commit made outside Jotter (someone pushing from their laptop).
     *
     * @param  array<string, string|null>  $changes  path → contents, null = delete
     */
    public function push(string $fullName, array $changes, string $branch = 'main', string $message = 'external edit'): string
    {
        $repo = &$this->repo($fullName);
        $head = $repo['branches'][$branch] ?? null;
        $files = $head === null ? [] : $repo['trees'][$repo['commits'][$head]['tree']];
        foreach ($changes as $path => $contents) {
            if ($contents === null) {
                unset($files[$path]);
            } else {
                $sha = $this->storeBlob($repo, $contents);
                $files[$path] = ['sha' => $sha, 'mode' => '100644'];
            }
        }
        $tree = $this->storeTree($repo, $files);

        return $this->storeCommit($repo, $branch, $tree, $head === null ? [] : [$head], $message);
    }

    /** Replace history (force-push): a root commit with these files. */
    public function forcePush(string $fullName, array $files, string $branch = 'main'): string
    {
        $repo = &$this->repo($fullName);
        $tree = [];
        foreach ($files as $path => $contents) {
            $tree[$path] = ['sha' => $this->storeBlob($repo, $contents), 'mode' => '100644'];
        }

        return $this->storeCommit($repo, $branch, $this->storeTree($repo, $tree), [], 'rewritten');
    }

    /** Add a special entry (symlink 120000, submodule 160000) at the head. */
    public function pushSpecial(string $fullName, string $path, string $mode, string $branch = 'main'): string
    {
        $repo = &$this->repo($fullName);
        $head = $repo['branches'][$branch];
        $files = $repo['trees'][$repo['commits'][$head]['tree']];
        $files[$path] = ['sha' => $mode === '160000' ? sha1('submodule'.$path) : $this->storeBlob($repo, 'target'), 'mode' => $mode];

        return $this->storeCommit($repo, $branch, $this->storeTree($repo, $files), [$head], 'special');
    }

    /**
     * Files at the head of a branch.
     *
     * @return array<string, string>
     */
    public function files(string $fullName, string $branch = 'main'): array
    {
        $repo = $this->repos[strtolower($fullName)];
        $head = $repo['branches'][$branch] ?? null;
        if ($head === null) {
            return [];
        }
        $out = [];
        foreach ($repo['trees'][$repo['commits'][$head]['tree']] as $path => $entry) {
            if ($entry['mode'] !== '160000') {
                $out[$path] = $repo['blobs'][$entry['sha']];
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    public function head(string $fullName, string $branch = 'main'): ?string
    {
        return $this->repos[strtolower($fullName)]['branches'][$branch] ?? null;
    }

    /** @return list<string> commit messages, newest first */
    public function log(string $fullName, string $branch = 'main'): array
    {
        $repo = $this->repos[strtolower($fullName)];
        $messages = [];
        $sha = $repo['branches'][$branch] ?? null;
        while ($sha !== null) {
            $messages[] = $repo['commits'][$sha]['message'];
            $sha = $repo['commits'][$sha]['parents'][0] ?? null;
        }

        return $messages;
    }

    public function deleteBranch(string $fullName, string $branch = 'main'): void
    {
        unset($this->repos[strtolower($fullName)]['branches'][$branch]);
    }

    public function writeCount(): int
    {
        return count(array_filter($this->requests, fn (array $r): bool => $r['write']));
    }

    public function countRequests(string $method, string $pathContains): int
    {
        return count(array_filter($this->requests, fn (array $r): bool => $r['method'] === $method && str_contains($r['path'], $pathContains)));
    }

    public function handle(Request $request): PromiseInterface
    {
        $method = strtoupper($request->method());
        $url = parse_url($request->url());
        $path = (string) ($url['path'] ?? '');
        parse_str((string) ($url['query'] ?? ''), $query);
        $body = json_decode((string) $request->body(), true) ?: [];
        $write = in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true) && ! str_contains($path, '/access_tokens');
        $this->requests[] = ['method' => $method, 'path' => $path, 'write' => $write];

        if ($this->down) {
            return $this->json(['message' => 'Server Error'], 502);
        }

        if (preg_match('#^/app/installations/(\d+)/access_tokens$#', $path, $m)) {
            return $this->accessToken($request, $m[1], $body);
        }
        if (preg_match('#^/app/installations/(\d+)$#', $path, $m)) {
            if (! $this->validJwt($request)) {
                return $this->json(['message' => 'Bad credentials'], 401);
            }

            return isset($this->installations[$m[1]])
                ? $this->json(['id' => (int) $m[1], 'account' => ['login' => 'acme']])
                : $this->json(['message' => 'Not Found'], 404);
        }
        if ($path === '/installation/repositories') {
            $token = $this->bearer($request);
            if (! isset($this->tokens[$token])) {
                return $this->json(['message' => 'Bad credentials'], 401);
            }
            $repos = [];
            foreach ($this->installations[(string) $this->tokens[$token]['installation']] ?? [] as $full) {
                [$owner, $name] = explode('/', $full);
                $repos[] = ['name' => $name, 'full_name' => $full, 'owner' => ['login' => $owner], 'private' => true, 'default_branch' => 'main'];
            }

            return $this->json(['total_count' => count($repos), 'repositories' => $repos]);
        }
        if (preg_match('#^/app-manifests/([^/]+)/conversions$#', $path, $m)) {
            return $this->json(['id' => 999, 'slug' => 'jotter-vault-test', 'pem' => "-----BEGIN RSA PRIVATE KEY-----\nfake\n-----END RSA PRIVATE KEY-----\n", 'webhook_secret' => 'whsec', 'html_url' => 'https://github.com/apps/jotter-vault-test'], 201);
        }

        if (! preg_match('#^/repos/([^/]+)/([^/]+)(/.*)?$#', $path, $m)) {
            return $this->json(['message' => 'Not Found'], 404);
        }
        $full = strtolower(rawurldecode($m[1]).'/'.rawurldecode($m[2]));
        $rest = $m[3] ?? '';

        if (! $this->authorized($request, $full)) {
            return $this->json(['message' => 'Bad credentials'], 401);
        }
        if (! isset($this->repos[$full])) {
            return $this->json(['message' => 'Not Found'], 404);
        }

        if ($write && $this->rateLimitWrites > 0) {
            $this->rateLimitWrites--;

            return $this->json(['message' => 'You have exceeded a secondary rate limit.'], 403, ['retry-after' => '120']);
        }

        $repo = &$this->repos[$full];
        $empty = $repo['branches'] === [];

        if ($rest === '' && $method === 'GET') {
            return $this->json([
                'full_name' => $full, 'private' => $repo['private'], 'size' => $repo['size'],
                'default_branch' => 'main', 'permissions' => ['admin' => false, 'push' => $repo['push'], 'pull' => true],
            ]);
        }

        if (str_starts_with($rest, '/git/') && $empty && ! ($method === 'POST' && $rest === '/git/refs')) {
            return $this->json(['message' => 'Git Repository is empty.'], 409);
        }

        if ($method === 'GET' && preg_match('#^/git/ref/heads/(.+)$#', $rest, $mm)) {
            $branch = rawurldecode($mm[1]);
            $sha = $repo['branches'][$branch] ?? null;
            if ($sha === null) {
                return $this->json(['message' => 'Not Found'], 404);
            }
            $etag = '"'.sha1('ref'.$sha).'"';
            if ($request->header('If-None-Match')[0] ?? null) {
                if ($request->header('If-None-Match')[0] === $etag) {
                    return $this->json(null, 304, ['ETag' => $etag]);
                }
            }

            return $this->json(['ref' => 'refs/heads/'.$branch, 'object' => ['sha' => $sha, 'type' => 'commit']], 200, ['ETag' => $etag]);
        }

        if ($method === 'GET' && preg_match('#^/git/commits/([0-9a-f]{40})$#', $rest, $mm)) {
            $commit = $repo['commits'][$mm[1]] ?? null;
            if ($commit === null) {
                return $this->json(['message' => 'Not Found'], 404);
            }

            return $this->json(['sha' => $mm[1], 'tree' => ['sha' => $commit['tree']], 'parents' => array_map(fn ($p) => ['sha' => $p], $commit['parents']), 'message' => $commit['message']]);
        }

        if ($method === 'GET' && preg_match('#^/git/trees/([0-9a-f]{40})$#', $rest, $mm)) {
            $tree = $repo['trees'][$mm[1]] ?? null;
            if ($tree === null) {
                return $this->json(['message' => 'Not Found'], 404);
            }
            $entries = [];
            $dirs = [];
            foreach ($tree as $file => $entry) {
                $parts = explode('/', $file);
                for ($i = 1; $i < count($parts); $i++) {
                    $dirs[implode('/', array_slice($parts, 0, $i))] = true;
                }
                $entries[] = $entry['mode'] === '160000'
                    ? ['path' => $file, 'mode' => '160000', 'type' => 'commit', 'sha' => $entry['sha']]
                    : ['path' => $file, 'mode' => $entry['mode'], 'type' => 'blob', 'sha' => $entry['sha'], 'size' => strlen($repo['blobs'][$entry['sha']])];
            }
            foreach (array_keys($dirs) as $dir) {
                $entries[] = ['path' => $dir, 'mode' => '040000', 'type' => 'tree', 'sha' => sha1('dir'.$dir)];
            }
            usort($entries, fn ($a, $b) => strcmp($a['path'], $b['path']));

            return $this->json(['sha' => $mm[1], 'tree' => $entries, 'truncated' => false]);
        }

        if ($method === 'GET' && preg_match('#^/git/blobs/([0-9a-f]{40})$#', $rest, $mm)) {
            $blob = $repo['blobs'][$mm[1]] ?? null;

            return $blob === null
                ? $this->json(['message' => 'Not Found'], 404)
                : $this->json(['sha' => $mm[1], 'size' => strlen($blob), 'content' => chunk_split(base64_encode($blob), 60, "\n"), 'encoding' => 'base64']);
        }

        if ($method === 'GET' && preg_match('#^/compare/([0-9a-f]{40})\.\.\.([0-9a-f]{40})$#', $rest, $mm)) {
            return $this->compare($repo, $mm[1], $mm[2]);
        }

        if ($method === 'POST' && $rest === '/git/blobs') {
            $contents = ($body['encoding'] ?? 'utf-8') === 'base64' ? base64_decode((string) $body['content']) : (string) $body['content'];

            return $this->json(['sha' => $this->storeBlob($repo, $contents)], 201);
        }

        if ($method === 'POST' && $rest === '/git/trees') {
            $files = [];
            if (isset($body['base_tree'])) {
                $files = $repo['trees'][$body['base_tree']] ?? null;
                if ($files === null) {
                    return $this->json(['message' => 'Invalid tree info'], 422);
                }
            }
            foreach ((array) $body['tree'] as $entry) {
                if (array_key_exists('content', $entry)) {
                    $files[$entry['path']] = ['sha' => $this->storeBlob($repo, (string) $entry['content']), 'mode' => $entry['mode']];
                } elseif ($entry['sha'] === null) {
                    if (! isset($files[$entry['path']])) {
                        return $this->json(['message' => 'GitRPC::BadObjectState'], 422);
                    }
                    unset($files[$entry['path']]);
                } else {
                    if (! isset($repo['blobs'][$entry['sha']])) {
                        return $this->json(['message' => 'Invalid tree info'], 422);
                    }
                    $files[$entry['path']] = ['sha' => $entry['sha'], 'mode' => $entry['mode']];
                }
            }

            return $this->json(['sha' => $this->storeTree($repo, $files)], 201);
        }

        if ($method === 'POST' && $rest === '/git/commits') {
            if (! isset($repo['trees'][$body['tree']])) {
                return $this->json(['message' => 'Tree not found'], 422);
            }
            $sha = sha1('commit'.$body['tree'].implode(',', $body['parents']).$body['message'].microtime().random_int(0, PHP_INT_MAX));
            $repo['commits'][$sha] = ['tree' => $body['tree'], 'parents' => $body['parents'], 'message' => $body['message']];

            return $this->json(['sha' => $sha], 201);
        }

        if ($method === 'PATCH' && preg_match('#^/git/refs/heads/(.+)$#', $rest, $mm)) {
            $branch = rawurldecode($mm[1]);
            if ($this->beforePatch !== null) {
                $callback = $this->beforePatch;
                $this->beforePatch = null;
                $callback($this);
                $repo = &$this->repos[$full];
            }
            if ($this->crashBeforePatch) {
                $this->crashBeforePatch = false;
                throw new \RuntimeException('Simulated crash before PATCH');
            }
            $current = $repo['branches'][$branch] ?? null;
            if ($current === null) {
                return $this->json(['message' => 'Reference does not exist'], 422);
            }
            if (! ($body['force'] ?? false) && ! $this->isAncestor($repo, $current, (string) $body['sha'])) {
                return $this->json(['message' => 'Update is not a fast forward'], 422);
            }
            $repo['branches'][$branch] = (string) $body['sha'];

            return $this->json(['ref' => 'refs/heads/'.$branch, 'object' => ['sha' => $body['sha']]]);
        }

        if ($method === 'POST' && $rest === '/git/refs') {
            $branch = substr((string) $body['ref'], strlen('refs/heads/'));
            if (isset($repo['branches'][$branch])) {
                return $this->json(['message' => 'Reference already exists'], 422);
            }
            $repo['branches'][$branch] = (string) $body['sha'];

            return $this->json(['ref' => $body['ref'], 'object' => ['sha' => $body['sha']]], 201);
        }

        if ($method === 'PUT' && preg_match('#^/contents/(.+)$#', $rest, $mm)) {
            $file = implode('/', array_map('rawurldecode', explode('/', $mm[1])));
            $branch = (string) ($body['branch'] ?? 'main');
            $head = $repo['branches'][$branch] ?? null;
            $files = $head === null ? [] : $repo['trees'][$repo['commits'][$head]['tree']];
            $files[$file] = ['sha' => $this->storeBlob($repo, (string) base64_decode((string) $body['content'])), 'mode' => '100644'];
            $sha = $this->storeCommit($repo, $branch, $this->storeTree($repo, $files), $head === null ? [] : [$head], (string) $body['message']);

            return $this->json(['content' => ['path' => $file], 'commit' => ['sha' => $sha]], 201);
        }

        return $this->json(['message' => 'Not Found (fake)'], 404);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function accessToken(Request $request, string $installationId, array $body): PromiseInterface
    {
        if (! $this->validJwt($request)) {
            return $this->json(['message' => 'A JSON web token could not be decoded'], 401);
        }
        if (! isset($this->installations[$installationId])) {
            return $this->json(['message' => 'Not Found'], 404);
        }
        $repos = null;
        if (isset($body['repositories'])) {
            $repos = [];
            foreach ($body['repositories'] as $name) {
                $match = array_values(array_filter($this->installations[$installationId], fn ($full) => str_ends_with($full, '/'.strtolower($name))));
                if ($match === []) {
                    return $this->json(['message' => 'There is at least one repository that does not exist or is not accessible to the parent installation.'], 422);
                }
                $repos[] = $match[0];
            }
        }
        $token = 'ghs_'.bin2hex(random_bytes(10));
        $this->tokens[$token] = ['installation' => (int) $installationId, 'repos' => $repos, 'expires' => time() + 3600];

        return $this->json([
            'token' => $token,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            'permissions' => $body['permissions'] ?? ['contents' => 'write'],
        ], 201);
    }

    private function validJwt(Request $request): bool
    {
        $jwt = $this->bearer($request);
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || $this->appPublicKey === null) {
            return false;
        }
        $signature = base64_decode(strtr($parts[2], '-_', '+/'));
        if (openssl_verify($parts[0].'.'.$parts[1], $signature, $this->appPublicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return false;
        }
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return ($claims['iss'] ?? null) === $this->appId && ($claims['exp'] ?? 0) > time() && ($claims['exp'] - $claims['iat']) <= 600;
    }

    private function authorized(Request $request, string $full): bool
    {
        $token = $this->bearer($request);
        if (isset($this->pats[$token])) {
            return $this->pats[$token] === '*' || $this->pats[$token] === $full;
        }
        if (isset($this->tokens[$token])) {
            $grant = $this->tokens[$token];
            if ($grant['expires'] < time() || ! in_array($full, $this->installations[(string) $grant['installation']] ?? [], true)) {
                return false;
            }

            return $grant['repos'] === null || in_array($full, $grant['repos'], true);
        }

        return false;
    }

    private function bearer(Request $request): string
    {
        $header = $request->header('Authorization')[0] ?? '';

        return (string) preg_replace('/^(Bearer|token)\s+/i', '', $header);
    }

    private function compare(array $repo, string $base, string $head): PromiseInterface
    {
        if (! isset($repo['commits'][$base]) || ! isset($repo['commits'][$head])) {
            return $this->json(['message' => 'Not Found'], 404);
        }
        $status = match (true) {
            $base === $head => 'identical',
            $this->isAncestor($repo, $base, $head) => 'ahead',
            $this->isAncestor($repo, $head, $base) => 'behind',
            default => 'diverged',
        };
        $from = $repo['trees'][$repo['commits'][$base]['tree']];
        $to = $repo['trees'][$repo['commits'][$head]['tree']];
        $files = [];
        foreach ($to as $path => $entry) {
            if (! isset($from[$path])) {
                $files[] = ['filename' => $path, 'status' => 'added', 'sha' => $entry['sha']];
            } elseif ($from[$path]['sha'] !== $entry['sha']) {
                $files[] = ['filename' => $path, 'status' => 'modified', 'sha' => $entry['sha']];
            }
        }
        foreach ($from as $path => $entry) {
            if (! isset($to[$path])) {
                $files[] = ['filename' => $path, 'status' => 'removed', 'sha' => $entry['sha']];
            }
        }

        return $this->json(['status' => $status, 'total_commits' => 1, 'files' => array_slice($files, 0, 300)]);
    }

    private function isAncestor(array $repo, string $ancestor, string $sha): bool
    {
        $queue = [$sha];
        $seen = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $ancestor) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($repo['commits'][$current]['parents'] ?? [] as $parent) {
                $queue[] = $parent;
            }
        }

        return false;
    }

    private function &repo(string $fullName): array
    {
        return $this->repos[strtolower($fullName)];
    }

    private function storeBlob(array &$repo, string $contents): string
    {
        $sha = sha1('blob '.strlen($contents)."\0".$contents);
        $repo['blobs'][$sha] = $contents;

        return $sha;
    }

    /** @param array<string, array{sha: string, mode: string}> $files */
    private function storeTree(array &$repo, array $files): string
    {
        ksort($files, SORT_STRING);
        $sha = sha1('tree'.serialize($files));
        $repo['trees'][$sha] = $files;

        return $sha;
    }

    /** @param list<string> $parents */
    private function storeCommit(array &$repo, string $branch, string $tree, array $parents, string $message): string
    {
        $sha = sha1('commit'.$tree.implode(',', $parents).$message.microtime().random_int(0, PHP_INT_MAX));
        $repo['commits'][$sha] = ['tree' => $tree, 'parents' => $parents, 'message' => $message];
        $repo['branches'][$branch] = $sha;
        $repo['size'] = (int) ceil(array_sum(array_map('strlen', $repo['blobs'])) / 1024);

        return $sha;
    }

    /** @param array<string, string> $files */
    private function commitFiles(string $fullName, string $branch, array $files, string $message, array $parents): string
    {
        $repo = &$this->repo($fullName);
        $tree = [];
        foreach ($files as $path => $contents) {
            $tree[$path] = ['sha' => $this->storeBlob($repo, $contents), 'mode' => '100644'];
        }

        return $this->storeCommit($repo, $branch, $this->storeTree($repo, $tree), $parents, $message);
    }

    private function json(mixed $data, int $status = 200, array $headers = []): PromiseInterface
    {
        $headers += $this->extraHeaders + ['x-ratelimit-remaining' => '4999', 'x-ratelimit-reset' => (string) (time() + 3600)];

        return Http::response($data, $status, $headers);
    }
}
