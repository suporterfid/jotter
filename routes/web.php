<?php

use App\Http\Controllers\LlmsTxtController;
use Illuminate\Support\Facades\Route;

Route::get('/llms.txt', [LlmsTxtController::class, 'globalLlmsTxt']);

Route::get('/', function () {
    return view('app');
});
