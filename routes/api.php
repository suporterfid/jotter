<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditLogQueryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\WebDavController;
use App\Http\Controllers\WorkspaceExportController;
use App\Http\Controllers\WorkspaceNoteController;
use App\Http\Controllers\WorkspacePublishController;
use App\Http\Controllers\WorkspaceSearchController;
use App\Http\Controllers\WorkspaceSyncController;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [AuthController::class, 'me']);
Route::post('/auth/change-password', [\App\Http\Controllers\AdminUserController::class, 'changePassword']);
Route::post('/mcp', [\App\Http\Controllers\McpController::class, 'handle']);

Route::middleware('workspace.authorization')->group(function (): void {
    Route::get('/admin/users', [\App\Http\Controllers\AdminUserController::class, 'index']);
    Route::post('/admin/users', [\App\Http\Controllers\AdminUserController::class, 'store']);
    Route::post('/admin/users/{user}/deactivate', [\App\Http\Controllers\AdminUserController::class, 'deactivate']);
    Route::post('/admin/users/{user}/reactivate', [\App\Http\Controllers\AdminUserController::class, 'reactivate']);
    Route::post('/admin/users/{user}/reset-password', [\App\Http\Controllers\AdminUserController::class, 'resetPassword']);
    Route::get('/workspaces/{workspace}/llms.txt', [LlmsTxtController::class, 'workspaceLlmsTxt']);
    Route::match(['PROPFIND', 'GET', 'PUT', 'MKCOL', 'DELETE', 'OPTIONS'], '/webdav/{workspace}/{path?}', [WebDavController::class, 'handle'])->where('path', '.*');
    Route::post('/workspaces/{workspace}/publish', [WorkspacePublishController::class, 'publish']);
    Route::post('/workspaces/{workspace}/import', [\App\Http\Controllers\WorkspaceImportController::class, 'import']);
    Route::get('/workspaces', function () {
        return response()->json([
            'data' => Workspace::query()
                ->select(['id', 'tenant_id', 'slug', 'name'])
                ->orderBy('id')
                ->get(),
        ]);
    });

    Route::post('/admin/workspaces', [\App\Http\Controllers\AdminWorkspaceController::class, 'store']);
    Route::put('/admin/workspaces/{workspace}', [\App\Http\Controllers\AdminWorkspaceController::class, 'update']);
    Route::post('/admin/workspaces/{workspace}/archive', [\App\Http\Controllers\AdminWorkspaceController::class, 'archive']);

    Route::get('/admin/workspaces/{workspace}/members', [\App\Http\Controllers\AdminMembershipController::class, 'index']);
    Route::post('/admin/workspaces/{workspace}/members', [\App\Http\Controllers\AdminMembershipController::class, 'store']);
    Route::put('/admin/workspaces/{workspace}/members/{member}', [\App\Http\Controllers\AdminMembershipController::class, 'update']);
    Route::delete('/admin/workspaces/{workspace}/members/{member}', [\App\Http\Controllers\AdminMembershipController::class, 'destroy']);

    Route::get('/workspaces/{workspace}/audit-logs', [AuditLogQueryController::class, 'index']);
    Route::get('/workspaces/{workspace}/export', [WorkspaceExportController::class, 'export']);
    Route::get('/workspaces/{workspace}/sync', [WorkspaceSyncController::class, 'sync']);
    Route::get('/workspaces/{workspace}/search', WorkspaceSearchController::class);
    Route::get('/workspaces/{workspace}/link-report', [\App\Http\Controllers\WorkspaceLinkReportController::class, 'report']);
    Route::get('/workspaces/{workspace}/notes/{note}/unlinked-mentions', [\App\Http\Controllers\WorkspaceUnlinkedMentionsController::class, 'index']);
    Route::get('/workspaces/{workspace}/notes', [WorkspaceNoteController::class, 'index']);
    Route::get('/workspaces/{workspace}/notes/{note}/comments', [\App\Http\Controllers\WorkspaceCommentController::class, 'index']);
    Route::post('/workspaces/{workspace}/notes/{note}/comments', [\App\Http\Controllers\WorkspaceCommentController::class, 'store']);
    Route::delete('/workspaces/{workspace}/notes/{note}/comments/{comment}', [\App\Http\Controllers\WorkspaceCommentController::class, 'destroy']);

    Route::get('/workspaces/{workspace}/notifications', [\App\Http\Controllers\WorkspaceNotificationController::class, 'index']);
    Route::post('/workspaces/{workspace}/notifications/{notification}/read', [\App\Http\Controllers\WorkspaceNotificationController::class, 'markAsRead']);
    Route::delete('/workspaces/{workspace}/notifications/{notification}', [\App\Http\Controllers\WorkspaceNotificationController::class, 'destroy']);

    Route::get('/workspaces/{workspace}/collections', [\App\Http\Controllers\WorkspaceCollectionController::class, 'index']);
    Route::post('/workspaces/{workspace}/notes', [WorkspaceNoteController::class, 'store']);
    Route::post('/workspaces/{workspace}/notes/from-template', [\App\Http\Controllers\WorkspaceTemplateController::class, 'createFromTemplate']);
    Route::match(['get', 'post'], '/workspaces/{workspace}/daily/{date?}', [\App\Http\Controllers\WorkspaceTemplateController::class, 'dailyNote']);
    Route::get('/workspaces/{workspace}/notes/{note}', [WorkspaceNoteController::class, 'show']);
    Route::get('/workspaces/{workspace}/notes/{note}/outgoing-links', [WorkspaceNoteController::class, 'outgoingLinks']);
    Route::put('/workspaces/{workspace}/notes/{note}', [WorkspaceNoteController::class, 'update']);
    Route::post('/workspaces/{workspace}/notes/{note}/move', [WorkspaceNoteController::class, 'move']);
    Route::delete('/workspaces/{workspace}/notes/{note}', [WorkspaceNoteController::class, 'destroy']);

    Route::get('/workspaces/{workspace}/notes/{note}/revisions', [\App\Http\Controllers\WorkspaceNoteRevisionController::class, 'index']);
    Route::get('/workspaces/{workspace}/notes/{note}/revisions/{revision}', [\App\Http\Controllers\WorkspaceNoteRevisionController::class, 'show']);
    Route::post('/workspaces/{workspace}/notes/{note}/revisions/{revision}/restore', [\App\Http\Controllers\WorkspaceNoteRevisionController::class, 'restore']);

    Route::get('/workspaces/{workspace}/properties', [\App\Http\Controllers\WorkspacePropertyController::class, 'index']);
    Route::post('/workspaces/{workspace}/notes/{note}/properties', [\App\Http\Controllers\WorkspacePropertyController::class, 'setProperty']);
    Route::delete('/workspaces/{workspace}/notes/{note}/properties/{key}', [\App\Http\Controllers\WorkspacePropertyController::class, 'deleteProperty']);

    Route::get('/workspaces/{workspace}/attachments', [AttachmentController::class, 'index']);
    Route::post('/workspaces/{workspace}/attachments', [AttachmentController::class, 'store']);
    Route::get('/workspaces/{workspace}/attachments/{path}', [AttachmentController::class, 'show'])->where('path', '.*');
    Route::delete('/workspaces/{workspace}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->where('attachment', '.*');
});
