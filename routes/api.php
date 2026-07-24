<?php

use App\Http\Controllers\WorkspaceSearchController;
use App\Http\Controllers\WorkspaceNoteController;
use Illuminate\Support\Facades\Route;

Route::get('/workspaces/{workspace}/search', WorkspaceSearchController::class);

Route::middleware('workspace.authorization')->group(function (): void {
    Route::get('/workspaces/{workspace}/notes', [WorkspaceNoteController::class, 'index']);
    Route::post('/workspaces/{workspace}/notes', [WorkspaceNoteController::class, 'store']);
    Route::get('/workspaces/{workspace}/notes/{note}', [WorkspaceNoteController::class, 'show']);
    Route::put('/workspaces/{workspace}/notes/{note}', [WorkspaceNoteController::class, 'update']);
    Route::delete('/workspaces/{workspace}/notes/{note}', [WorkspaceNoteController::class, 'destroy']);
});
