<?php

use App\Http\Controllers\WorkspaceSearchController;
use Illuminate\Support\Facades\Route;

Route::get('/workspaces/{workspace}/search', WorkspaceSearchController::class);
