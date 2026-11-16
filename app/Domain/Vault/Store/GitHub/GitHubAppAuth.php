<?php

namespace App\Domain\Vault\Store\GitHub;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * GitHub App authentication (D7, plan §4.2): a 10-minute RS256 JWT signed with
 * the App's private key buys a 1-hour installation token, restricted to one
 * repository and `contents: write`. Tokens are cached encrypted until five
 * minutes before they expire.
 */
class GitHubAppAuth
{
    public const SOURCE_ENV = 'env';

    public const SOURCE_DATABASE = 'database';

    public function configured(): bool
    {
        return $this->appId() !== null && $this->privateKeyPem() !== null;
    }

    /**
     * Where the App comes from: JOTTER_GITHUB_APP_ID in .env wins over the App
     * registered from the admin panel; null when neither exists.
     */
    public function source(): ?string
    {
        if ($this->envValue('app_id') !== null) {
            return self::SOURCE_ENV;
        }

        return $this->registration() !== null ? self::SOURCE_DATABASE : null;
    }

    public function appId(): ?string
    {
        return match ($this->source()) {
            self::SOURCE_ENV => $this->envValue('app_id'),
            self::SOURCE_DATABASE => (string) $this->registration()?->app_id,
            default => null,
        };
    }

    public function slug(): ?string
    {
        $slug = match ($this->source()) {
            self::SOURCE_ENV => $this->envValue('app_slug'),
            self::SOURCE_DATABASE => $this->registration()?->slug,
            default => null,
        };

        return $slug === null || $slug === '' ? null : (string) $slug;
    }

    /** Only in .env mode: the registered App keeps its key encrypted in the database. */
    public function privateKeyPath(): ?string
    {
        return $this->source() === self::SOURCE_ENV ? $this->envValue('private_key_path') : null;
    }

    public function webhookSecret(): ?string
    {
        $secret = match ($this->source()) {
            self::SOURCE_ENV => $this->envValue('webhook_secret'),
            self::SOURCE_DATABASE => $this->registration()?->webhook_secret,
            // A secret alone still verifies deliveries (an App configured by hand, key pending).
            default => $this->envValue('webhook_secret'),
        };

        return $secret === null || $secret === '' ? null : (string) $secret;
    }

    public function registration(): ?\App\Models\GitHubAppRegistration
    {
        return app(GitHubAppRegistry::class)->current();
    }

    private function privateKeyPem(): ?string
    {
        if ($this->source() === self::SOURCE_DATABASE) {
            $pem = (string) $this->registration()?->private_key;

            return $pem === '' ? null : $pem;
        }
        $path = $this->privateKeyPath();
        if ($path === null || ! is_readable($path)) {
            return $path === null ? null : '';
        }
        $pem = file_get_contents($path);

        return $pem === false ? '' : $pem;
    }

    private function envValue(string $key): ?string
    {
        $value = config('jotter.github.'.$key);

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Problems with the App configuration, for the doctor (never the key itself).
     *
     * @return list<string>
     */
    public function configurationProblems(): array
    {
        if ($this->source() === self::SOURCE_DATABASE) {
            $problems = [];
            if (openssl_pkey_get_private((string) $this->privateKeyPem()) === false) {
                $problems[] = 'the private key of the GitHub App registered in the admin panel is not a valid PEM key';
            }
            if ($this->webhookSecret() === null) {
                $problems[] = 'the GitHub App registered in the admin panel has no webhook secret (external edits only arrive by polling)';
            }

            return $problems;
        }

        $problems = [];
        $path = $this->privateKeyPath();
        if ($this->appId() === null) {
            $problems[] = 'JOTTER_GITHUB_APP_ID is not set';
        }
        if ($this->slug() === null) {
            $problems[] = 'JOTTER_GITHUB_APP_SLUG is not set';
        }
        if ($path === null) {
            $problems[] = 'JOTTER_GITHUB_APP_PRIVATE_KEY_PATH is not set';
        } elseif (! is_readable($path)) {
            $problems[] = 'the GitHub App private key is not readable';
        } else {
            $real = realpath($path) ?: $path;
            $public = realpath(public_path()) ?: public_path();
            if (str_starts_with($real, rtrim($public, '/').'/')) {
                $problems[] = 'the GitHub App private key is inside public/';
            }
            if (openssl_pkey_get_private((string) file_get_contents($path)) === false) {
                $problems[] = 'the GitHub App private key is not a valid PEM key';
            }
        }
        if ($this->webhookSecret() === null) {
            $problems[] = 'JOTTER_GITHUB_WEBHOOK_SECRET is not set (external edits only arrive by polling)';
        }

        return $problems;
    }

    /** The App JWT (RS256, iat 60 s in the past for clock drift, 9 minutes of validity). */
    public function jwt(?int $now = null): string
    {
        $appId = $this->appId();
        $pem = $this->privateKeyPem();
        if ($appId === null || $pem === null) {
            throw new GitHubApiError('The GitHub App is not configured (register it in Administration → Storage, or set JOTTER_GITHUB_APP_ID / JOTTER_GITHUB_APP_PRIVATE_KEY_PATH).');
        }

        $key = $pem === '' ? false : openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new GitHubApiError('The GitHub App private key cannot be read.');
        }

        $now ??= time();
        $segments = [
            self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url((string) json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => $appId])),
        ];
        $signature = '';
        if (! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new GitHubApiError('Unable to sign the GitHub App JWT.');
        }
        $segments[] = self::base64Url($signature);

        return implode('.', $segments);
    }

    /**
     * An installation token for one repository. $repo null = every repository
     * of the installation (only used to list them during the install flow).
     *
     * @return array{token: string, expires_at: int, permissions: array<string, string>}
     */
    public function installationToken(int $installationId, ?string $repo = null): array
    {
        $cacheKey = 'github:installation-token:'.$installationId.':'.($repo === null ? '*' : strtolower($repo));
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            try {
                $decoded = json_decode(Crypt::decryptString($cached), true);
                if (is_array($decoded) && ($decoded['expires_at'] ?? 0) > time() + 300) {
                    return $decoded;
                }
            } catch (\Throwable) {
                // APP_KEY rotated: fetch a new token.
            }
        }

        $body = ['permissions' => ['contents' => 'write', 'metadata' => 'read']];
        if ($repo !== null) {
            $body['repositories'] = [$repo];
        }

        try {
            $response = Http::withHeaders($this->headers('Bearer '.$this->jwt()))
                ->connectTimeout((int) config('jotter.github.connect_timeout', 3))
                ->timeout((int) config('jotter.github.timeout', 10))
                ->post($this->apiUrl("/app/installations/{$installationId}/access_tokens"), $body);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            throw new GitHubApiError('GitHub is unreachable: '.$exception->getMessage());
        }

        if (! $response->successful()) {
            throw new GitHubApiError(
                'GitHub refused an installation token (HTTP '.$response->status().'): '.(string) ($response->json('message') ?? ''),
                $response->status(),
                GitHubClient::retryAfterOf($response),
            );
        }

        $token = [
            'token' => (string) $response->json('token'),
            'expires_at' => strtotime((string) $response->json('expires_at')) ?: time() + 3600,
            'permissions' => (array) ($response->json('permissions') ?? []),
        ];
        Cache::put($cacheKey, Crypt::encryptString((string) json_encode($token)), max(60, $token['expires_at'] - time() - 300));

        return $token;
    }

    public function forgetInstallationTokens(int $installationId, ?string $repo = null): void
    {
        Cache::forget('github:installation-token:'.$installationId.':*');
        if ($repo !== null) {
            Cache::forget('github:installation-token:'.$installationId.':'.strtolower($repo));
        }
    }

    /**
     * The installation's account and its repositories (install flow, admin picks one).
     *
     * @return array{account: ?string, repositories: list<array{owner: string, repo: string, full_name: string, private: bool, default_branch: string}>}
     */
    public function installationRepositories(int $installationId): array
    {
        $account = null;
        try {
            $installation = Http::withHeaders($this->headers('Bearer '.$this->jwt()))
                ->connectTimeout((int) config('jotter.github.connect_timeout', 3))
                ->timeout((int) config('jotter.github.timeout', 10))
                ->get($this->apiUrl("/app/installations/{$installationId}"));
            if ($installation->successful()) {
                $account = $installation->json('account.login');
            } elseif (in_array($installation->status(), [401, 403, 404], true)) {
                throw new GitHubApiError('The GitHub App installation was not found.', $installation->status());
            }

            $token = $this->installationToken($installationId)['token'];
            $repositories = [];
            $page = 1;
            do {
                $response = Http::withHeaders($this->headers('token '.$token))
                    ->connectTimeout((int) config('jotter.github.connect_timeout', 3))
                    ->timeout((int) config('jotter.github.timeout', 10))
                    ->get($this->apiUrl('/installation/repositories'), ['per_page' => 100, 'page' => $page]);
                if (! $response->successful()) {
                    throw new GitHubApiError('GitHub refused to list the installation repositories (HTTP '.$response->status().').', $response->status());
                }
                foreach ((array) $response->json('repositories', []) as $repository) {
                    $repositories[] = [
                        'owner' => (string) ($repository['owner']['login'] ?? ''),
                        'repo' => (string) ($repository['name'] ?? ''),
                        'full_name' => (string) ($repository['full_name'] ?? ''),
                        'private' => (bool) ($repository['private'] ?? false),
                        'default_branch' => (string) ($repository['default_branch'] ?? 'main'),
                    ];
                }
                $page++;
            } while (count((array) $response->json('repositories', [])) === 100 && $page <= 10);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            throw new GitHubApiError('GitHub is unreachable: '.$exception->getMessage());
        }

        return ['account' => is_string($account) ? $account : null, 'repositories' => $repositories];
    }

    /**
     * G1 manifest flow: exchange the temporary code for the new App's id, slug,
     * PEM key and webhook secret (shown once to the instance administrator).
     *
     * @return array{id: string, slug: string, pem: string, webhook_secret: ?string, html_url: ?string, owner: ?string}
     */
    public function convertManifest(string $code): array
    {
        try {
            $response = Http::withHeaders($this->headers(null))
                ->connectTimeout((int) config('jotter.github.connect_timeout', 3))
                ->timeout((int) config('jotter.github.timeout', 10))
                // No body: GitHub refuses `[]`, which is what post() sends for no data.
                ->send('POST', $this->apiUrl('/app-manifests/'.rawurlencode($code).'/conversions'));
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            throw new GitHubApiError('GitHub is unreachable: '.$exception->getMessage());
        }
        if (! $response->successful()) {
            throw new GitHubApiError('GitHub refused the App manifest code (HTTP '.$response->status().'): '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 300), $response->status());
        }

        return [
            'id' => (string) $response->json('id'),
            'slug' => (string) $response->json('slug'),
            'pem' => (string) $response->json('pem'),
            'webhook_secret' => $response->json('webhook_secret'),
            'html_url' => $response->json('html_url'),
            'owner' => is_string($response->json('owner.login')) ? $response->json('owner.login') : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function headers(?string $authorization): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'Jotter-Vault',
        ];
        if ($authorization !== null) {
            $headers['Authorization'] = $authorization;
        }

        return $headers;
    }

    public function apiUrl(string $path): string
    {
        return rtrim((string) config('jotter.github.api_url', 'https://api.github.com'), '/').$path;
    }

    public static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
