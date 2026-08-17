<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Oidc\OidcAuthenticationException;
use App\Domain\Auth\Oidc\OidcAuthenticationService;
use App\Domain\Auth\Oidc\OidcProtocolException;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

final class OidcController extends Controller
{
    public function __construct(
        private readonly OidcAuthenticationService $authenticationService,
        private readonly AuditRecorder $auditRecorder = new AuditRecorder,
    ) {
    }

    public function redirect(Request $request): RedirectResponse
    {
        try {
            return redirect()->away($this->authenticationService->authorizationUrl($request));
        } catch (Throwable) {
            $this->recordFailure('redirect_failed');

            return $this->errorRedirect();
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $this->authenticationService->authenticateCallback($request);

            return redirect()->away((string) config('jotter.oidc.post_login_redirect_uri', config('app.url')));
        } catch (OidcProtocolException|OidcAuthenticationException) {
            $this->recordFailure('callback_failed');

            return $this->errorRedirect();
        } catch (Throwable) {
            $this->recordFailure('callback_failed');

            return $this->errorRedirect();
        }
    }

    private function errorRedirect(): RedirectResponse
    {
        $target = (string) config('jotter.oidc.post_login_redirect_uri', config('app.url'));
        $separator = str_contains($target, '?') ? '&' : '?';

        return redirect()->away($target.$separator.'auth_error=oidc_failed');
    }

    private function recordFailure(string $reason): void
    {
        $this->auditRecorder->record(
            AuditEvent::AUTH_LOGIN_FAILURE,
            metadata: [
                'provider' => 'oidc',
                'reason' => $reason,
            ],
        );
    }
}
