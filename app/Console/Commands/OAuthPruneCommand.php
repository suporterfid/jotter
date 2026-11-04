<?php

namespace App\Console\Commands;

use App\Models\MachineToken;
use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\OAuthGrant;
use App\Models\OAuthRefreshToken;
use Illuminate\Console\Command;

/**
 * Daily OAuth housekeeping (brain plan PR 5): expired or used authorization
 * codes, expired or rotated refresh tokens, expired OAuth access tokens, and
 * clients registered through DCR that have no active grant and have not been
 * used for `jotter.oauth.client_idle_days` days. Bounded and idempotent.
 */
final class OAuthPruneCommand extends Command
{
    protected $signature = 'oauth:prune {--json : Emit JSON}';

    protected $description = 'Delete expired OAuth codes and tokens, and idle OAuth clients without active grants.';

    public function handle(): int
    {
        $dayAgo = now()->subDay();

        $counts = [
            'codes' => OAuthAuthCode::query()
                ->where(static fn ($query) => $query->where('expires_at', '<', $dayAgo)->orWhere('used_at', '<', $dayAgo))
                ->delete(),
            'refresh_tokens' => OAuthRefreshToken::query()
                ->where(static fn ($query) => $query->where('expires_at', '<', now())->orWhere('used_at', '<', $dayAgo))
                ->delete(),
            'access_tokens' => MachineToken::query()
                ->where('kind', MachineToken::KIND_OAUTH)
                ->where(static fn ($query) => $query->where('expires_at', '<', $dayAgo)->orWhere('revoked_at', '<', $dayAgo))
                ->delete(),
        ];

        $idleBefore = now()->subDays(max(1, (int) config('jotter.oauth.client_idle_days', 30)));
        $idle = OAuthClient::query()
            ->where(static function ($query) use ($idleBefore): void {
                $query->where('last_used_at', '<', $idleBefore)
                    ->orWhere(static fn ($never) => $never->whereNull('last_used_at')->where('created_at', '<', $idleBefore));
            })
            ->whereDoesntHave('grants', static function ($grants): void {
                $grants->whereNull('revoked_at')->where(static fn ($active) => $active->whereNull('expires_at')->orWhere('expires_at', '>', now()));
            })
            ->pluck('id');
        $counts['revoked_grants'] = OAuthGrant::query()->whereIn('oauth_client_id', $idle)->delete();
        $counts['clients'] = OAuthClient::query()->whereIn('id', $idle)->delete();

        if ($this->option('json')) {
            $this->line((string) json_encode($counts));
        } else {
            $this->info(sprintf(
                'OAuth prune: %d codes, %d refresh tokens, %d access tokens, %d clients removed.',
                $counts['codes'], $counts['refresh_tokens'], $counts['access_tokens'], $counts['clients'],
            ));
        }

        return self::SUCCESS;
    }
}
