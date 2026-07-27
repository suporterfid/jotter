<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditLogQueryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WorkspaceExportController;
use App\Http\Controllers\WorkspaceNoteController;
use App\Http\Controllers\WorkspaceSearchController;
use App\Http\Controllers\WorkspaceSyncController;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [AuthController::class, 'me']);

Route::middleware('workspace.authorization')->group(function (): void {
    Route::get('/workspaces', function () {
        return response()->json([
            'data' => Workspace::query()
                ->select(['id', 'tenant_id', 'slug', 'name'])
                ->orderBy('id')
                ->get(),
        ]);
    });

    Route::get('/workspaces/{workspace}/audit-logs', [AuditLogQueryController::class, 'index']);
    Route::get('/workspaces/{workspace}/export', [WorkspaceExportController::class, 'export']);
    Route::get('/workspaces/{workspace}/sync', [WorkspaceSyncController::class, 'sync']);
    Route::get('/workspaces/{workspace}/search', WorkspaceSearchController::class);
    Route::get('/workspaces/{workspace}/notes', [WorkspaceNoteController::class, 'index']);
    Route::post('/workspaces/{workspace}/notes', [WorkspaceNoteController::class, 'store']);
    Route::get('/workspaces/{workspace}/notes/{note}', [WorkspaceNoteController::class, 'show']);
    Route::put('/workspaces/{workspace}/notes/{note}', [WorkspaceNoteController::class, 'update']);
    Route::delete('/workspaces/{workspace}/notes/{note}', [WorkspaceNoteController::class, 'destroy']);

    Route::get('/workspaces/{workspace}/attachments', [AttachmentController::class, 'index']);
    Route::post('/workspaces/{workspace}/attachments', [AttachmentController::class, 'store']);
    Route::get('/workspaces/{workspace}/attachments/{path}', [AttachmentController::class, 'show'])->where('path', '.*');
    Route::delete('/workspaces/{workspace}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->where('attachment', '.*');
});
