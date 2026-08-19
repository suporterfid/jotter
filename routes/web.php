<?php

use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\PublicNoteShareController;
use App\Http\Controllers\PublicShareAssetController;
use Illuminate\Support\Facades\Route;

Route::get('/llms.txt', [LlmsTxtController::class, 'globalLlmsTxt']);
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
