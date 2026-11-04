<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\OAuth\OAuthServer;
use App\Models\MachineToken;
use App\Models\OAuthGrant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Connected apps": OAuth grants a person approved, with last use, and
 * revocation (which also revokes their access and refresh tokens). People see
 * their own grants; administrators see every grant. Bearer tokens never reach
 * these routes (EnforceCredentialAudience blocks /api/user and /api/admin).
 */
final class OAuthGrantController extends Controller
{
    public function __construct(private readonly OAuthServer $server) {}

    public function index(Request $request): JsonResponse
    {
        $subject = $this->subject($request);

        return response()->json(['data' => $this->list(OAuthGrant::query()->where('subject_id', $subject->subjectId))]);
    }

    public function destroy(Request $request, int $grant): JsonResponse
    {
        $subject = $this->subject($request);
        $record = OAuthGrant::query()->where('subject_id', $subject->subjectId)->findOrFail($grant);
        $this->server->revokeGrant($record, 'user_revoked', $subject->subjectId);

        return response()->json(['message' => __('messages.oauth_grant_revoked')]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['data' => $this->list(OAuthGrant::query())]);
    }

    public function adminDestroy(Request $request, int $grant): JsonResponse
    {
        $subject = $this->authorizeAdmin($request);
        $this->server->revokeGrant(OAuthGrant::query()->findOrFail($grant), 'admin_revoked', $subject->subjectId);

        return response()->json(['message' => __('messages.oauth_grant_revoked')]);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<OAuthGrant> $query
     * @return list<array<string, mixed>>
     */
    private function list($query): array
    {
        $grants = $query->with('client')->whereNull('revoked_at')->orderByDesc('id')->limit(200)->get();
        $lastUsed = MachineToken::query()
            ->whereIn('oauth_grant_id', $grants->pluck('id'))
            ->selectRaw('oauth_grant_id, MAX(last_used_at) as last_used_at')
            ->groupBy('oauth_grant_id')
            ->pluck('last_used_at', 'oauth_grant_id');
        $workspaces = Workspace::query()->whereIn('id', $grants->pluck('workspace_ids')->flatten()->unique())->pluck('slug', 'id');
        $userIdOf = static fn (OAuthGrant $grant): ?int => $grant->user_id ?? (ctype_digit((string) $grant->subject_id) ? (int) $grant->subject_id : null);
        $users = User::query()->whereIn('id', $grants->map($userIdOf)->filter())->pluck('email', 'id');

        return $grants->map(static fn (OAuthGrant $grant): array => [
            'id' => $grant->id,
            'client_name' => $grant->client?->name,
            'redirect_hosts' => array_values(array_unique(array_filter(array_map(
                static fn ($uri): ?string => parse_url((string) $uri, PHP_URL_HOST) ?: null,
                (array) ($grant->client?->redirect_uris ?? []),
            )))),
            'user_email' => $users->get((int) $userIdOf($grant)),
            'workspaces' => array_values(array_map(static fn ($id): string => (string) ($workspaces->get($id) ?? "#{$id}"), (array) $grant->workspace_ids)),
            'scopes' => $grant->scopes,
            'client_slug' => $grant->client_slug,
            'created_at' => $grant->created_at?->toIso8601String(),
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'last_used_at' => ($value = $lastUsed->get($grant->id)) !== null ? \Illuminate\Support\Carbon::parse($value)->toIso8601String() : null,
        ])->values()->all();
    }

    private function subject(Request $request): AuthenticatedSubject
    {
        $subject = $request->attributes->get('authenticated_subject');
        abort_unless($subject instanceof AuthenticatedSubject && $subject->credential === null, 401);

        return $subject;
    }

    private function authorizeAdmin(Request $request): AuthenticatedSubject
    {
        $subject = $this->subject($request);
        abort_unless($subject->isAdmin, 403, 'Administrator access required.');

        return $subject;
    }
}
