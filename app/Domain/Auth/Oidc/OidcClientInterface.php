<?php

namespace App\Domain\Auth\Oidc;

use Illuminate\Http\Request;

interface OidcClientInterface
{
    public function authorizationUrl(Request $request): string;

    public function authenticateCallback(Request $request): OidcClaims;
}
