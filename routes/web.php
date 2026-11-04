<?php

use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\OAuthAuthorizeController;
use App\Http\Controllers\OAuthMetadataController;
use App\Http\Controllers\OAuthRegistrationController;
use App\Http\Controllers\OAuthTokenController;
use App\Http\Middleware\EnsureOAuthEnabled;
use App\Http\Controllers\PublicNoteShareController;
use App\Http\Controllers\PublicShareAssetController;
use Illuminate\Support\Facades\Route;

Route::get('/llms.txt', [LlmsTxtController::class, 'globalLlmsTxt']);

// Embedded OAuth 2.1 server for MCP clients (docs/mcp.md); 404 unless
// JOTTER_OAUTH_ENABLED=true. register/token/revoke are CSRF-exempt machine
// endpoints (bootstrap/app.php); the consent form keeps CSRF protection.
Route::middleware(EnsureOAuthEnabled::class)->group(function (): void {
    Route::get('/.well-known/oauth-protected-resource', [OAuthMetadataController::class, 'protectedResource']);
    Route::get('/.well-known/oauth-protected-resource/api/mcp', [OAuthMetadataController::class, 'protectedResource']);
    Route::get('/.well-known/oauth-authorization-server', [OAuthMetadataController::class, 'authorizationServer']);
    Route::post('/oauth/register', [OAuthRegistrationController::class, 'store']);
    Route::get('/oauth/authorize', [OAuthAuthorizeController::class, 'show']);
    Route::post('/oauth/authorize', [OAuthAuthorizeController::class, 'decide']);
    Route::post('/oauth/token', [OAuthTokenController::class, 'token']);
    Route::post('/oauth/revoke', [OAuthTokenController::class, 'revoke']);
});
Route::get('/share-assets/publish.css', [PublicShareAssetController::class, 'css']);
Route::get('/share-assets/publish-theme.js', [PublicShareAssetController::class, 'theme']);
Route::get('/share-assets/fonts/{font}', [PublicShareAssetController::class, 'font']);
Route::get('/share/{token}/attachments/{path}', [PublicNoteShareController::class, 'attachment'])
    ->where('path', '.*')
    ->name('public.note-share.attachment');
Route::get('/share/{token}', [PublicNoteShareController::class, 'show'])
    ->name('public.note-share');

Route::get('/', function () {
    return view('app');
});
