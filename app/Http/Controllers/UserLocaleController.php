<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class UserLocaleController extends Controller
{
    private const SUPPORTED_LOCALES = ['pt-BR', 'en'];

    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function update(Request $request): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);

        if (! $subject) {
            return response()->json(['message' => __('messages.unauthenticated')], 401);
        }

        $locale = (string) $request->input('locale', '');
        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            return response()->json([
                'message' => __('messages.locale_unsupported', ['locales' => implode(', ', self::SUPPORTED_LOCALES)]),
            ], 400);
        }

        if (($subject->attributes['sso_provider'] ?? null) === 'grandpasson') {
            $synced = $this->syncViaGrandpaSson($request, $locale);
            if (! $synced) {
                return response()->json(['message' => __('messages.locale_grandpasson_sync_failed')], 502);
            }
        }

        $subject->user?->update(['locale' => $locale]);

        return response()->json(['ok' => true, 'locale' => $locale]);
    }

    private function syncViaGrandpaSson(Request $request, string $locale): bool
    {
        $baseUrl = rtrim((string) config('jotter.sso.broker_base_url'), '/');
        $cookie = $request->cookie('AUTHSESSID') ?? ($_COOKIE['AUTHSESSID'] ?? null);
        if ($baseUrl === '' || $cookie === null) {
            return false;
        }

        $getResponse = Http::withHeaders(['Cookie' => "AUTHSESSID={$cookie}"])
            ->get("{$baseUrl}/me/locale");
        if (! $getResponse->successful()) {
            return false;
        }

        $csrf = (string) ($getResponse->json('csrf') ?? '');
        if ($csrf === '') {
            return false;
        }

        $postResponse = Http::withHeaders(['Cookie' => "AUTHSESSID={$cookie}"])
            ->asForm()
            ->post("{$baseUrl}/me/locale", ['csrf' => $csrf, 'locale' => $locale]);

        return $postResponse->successful();
    }
}
