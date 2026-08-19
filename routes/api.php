<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditLogQueryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LlmsTxtController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\WebDavController;
use App\Http\Controllers\WorkspaceExportController;
use App\Http\Controllers\WorkspaceAnalyticsController;
use App\Http\Controllers\NotePdfExportController;
use App\Http\Controllers\WorkspacePdfExportController;
use App\Http\Controllers\WorkspaceNoteController;
use App\Http\Controllers\WorkspaceNoteReviewController;
use App\Http\Controllers\WorkspaceNoteAclController;
use App\Http\Controllers\WorkspaceGroupController;
use App\Http\Controllers\WorkspaceNoteTreeController;
use App\Http\Controllers\WorkspacePublishController;
use App\Http\Controllers\WorkspaceSearchController;
use App\Http\Controllers\WorkspaceSyncController;
use App\Http\Controllers\WorkspaceTrashController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\NoteShareController;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [AuthController::class, 'me']);
Route::get('/auth/config', [AuthController::class, 'config']);
Route::get('/auth/oidc/redirect', [\App\Http\Controllers\OidcController::class, 'redirect']);
Route::get('/auth/oidc/callback', [\App\Http\Controllers\OidcController::class, 'callback']);
Route::post('/auth/change-password', [\App\Http\Controllers\AdminUserController::class, 'changePassword']);
Route::post('/user/locale', [\App\Http\Controllers\UserLocaleController::class, 'update']);
Route::get('/user/notification-preferences', [NotificationPreferenceController::class, 'index']);
Route::put('/user/notification-preferences/{type}', [NotificationPreferenceController::class, 'update']);
Route::post('/mcp', [\App\Http\Controllers\McpController::class, 'handle']);

Route::middleware('workspace.authorization')->group(function (): void {
    Route::get('/admin/users', [\App\Http\Controllers\AdminUserController::class, 'index']);
    Route::post('/admin/users', [\App\Http\Controllers\AdminUserController::class, 'store']);
    Route::post('/admin/users/{user}/deactivate', [\App\Http\Controllers\AdminUserController::class, 'deactivate']);
    Route::post('/admin/users/{user}/reactivate', [\App\Http\Controllers\AdminUserController::class, 'reactivate']);
    Route::post('/admin/users/{user}/reset-password', [\App\Http\Controllers\AdminUserController::class, 'resetPassword']);
    Route::get('/workspaces/{workspace}/llms.txt', [LlmsTxtController::class, 'workspaceLlmsTxt']);
    Route::match(['PROPFIND', 'GET', 'PUT', 'MKCOL', 'DELETE', 'OPTIONS'], '/webdav/{workspace}/{path?}', [WebDavController::class, 'handle'])->where('path', '.*');
    Route::post('/workspaces/{workspace}/publish', [WorkspacePublishController::class, 'publish'])->middleware('workspace.write');
    Route::post('/workspaces/{workspace}/import', [\App\Http\Controllers\WorkspaceImportController::class, 'import'])->middleware('workspace.write');
    Route::get('/workspaces', [\App\Http\Controllers\WorkspaceController::class, 'index']);
    Route::get('/tenants', [TenantController::class, 'index']);

    Route::post('/admin/workspaces', [\App\Http\Controllers\AdminWorkspaceController::class, 'store']);
    Route::put('/admin/workspaces/{workspace}', [\App\Http\Controllers\AdminWorkspaceController::class, 'update'])->middleware('workspace.write');
    Route::post('/admin/workspaces/{workspace}/archive', [\App\Http\Controllers\AdminWorkspaceController::class, 'archive'])->middleware('workspace.write');

    Route::get('/admin/workspaces/{workspace}/members', [\App\Http\Controllers\AdminMembershipController::class, 'index']);
    Route::post('/admin/workspaces/{workspace}/members', [\App\Http\Controllers\AdminMembershipController::class, 'store'])->middleware('workspace.write');
    Route::put('/admin/workspaces/{workspace}/members/{member}', [\App\Http\Controllers\AdminMembershipController::class, 'update'])->middleware('workspace.write');
    Route::delete('/admin/workspaces/{workspace}/members/{member}', [\App\Http\Controllers\AdminMembershipController::class, 'destroy'])->middleware('workspace.write');

    Route::get('/workspaces/{workspace}/audit-logs', [AuditLogQueryController::class, 'index']);
    Route::get('/workspaces/{workspace}/analytics', [WorkspaceAnalyticsController::class, 'index']);
    Route::get('/workspaces/{workspace}/export', [WorkspaceExportController::class, 'export']);
    Route::get('/workspaces/{workspace}/notes/{note}/export.pdf', [NotePdfExportController::class, 'export']);
    Route::post('/workspaces/{workspace}/pdf-exports', [WorkspacePdfExportController::class, 'store']);
    Route::get('/workspaces/{workspace}/pdf-exports/{export}', [WorkspacePdfExportController::class, 'show']);
    Route::get('/workspaces/{workspace}/pdf-exports/{export}/download', [WorkspacePdfExportController::class, 'download']);
    Route::get('/workspaces/{workspace}/sync', [WorkspaceSyncController::class, 'sync']);
    Route::get('/workspaces/{workspace}/search', WorkspaceSearchController::class);
    Route::get('/workspaces/{workspace}/link-report', [\App\Http\Controllers\WorkspaceLinkReportController::class, 'report']);
    Route::get('/workspaces/{workspace}/notes/{note}/unlinked-mentions', [\App\Http\Controllers\WorkspaceUnlinkedMentionsController::class, 'index']);
    Route::get('/workspaces/{workspace}/notes', [WorkspaceNoteController::class, 'index']);
    Route::get('/workspaces/{workspace}/trash', [WorkspaceTrashController::class, 'index']);
    Route::get('/workspaces/{workspace}/groups', [WorkspaceGroupController::class, 'index']);
    Route::post('/workspaces/{workspace}/groups', [WorkspaceGroupController::class, 'store']);
    Route::put('/workspaces/{workspace}/groups/{group}', [WorkspaceGroupController::class, 'update']);
    Route::delete('/workspaces/{workspace}/groups/{group}', [WorkspaceGroupController::class, 'destroy']);
    Route::get('/workspaces/{workspace}/notes/{note}/comments', [\App\Http\Controllers\WorkspaceCommentController::class, 'index']);
    Route::post('/workspaces/{workspace}/notes/{note}/comments', [\App\Http\Controllers\WorkspaceCommentController::class, 'store'])->middleware('workspace.write');
    Route::delete('/workspaces/{workspace}/notes/{note}/comments/{comment}', [\App\Http\Controllers\WorkspaceCommentController::class, 'destroy'])->middleware('workspace.write');
    Route::get('/workspaces/{workspace}/notes/{note}/checklist-items', [\App\Http\Controllers\NoteChecklistItemController::class, 'index']);
    Route::post('/workspaces/{workspace}/notes/{note}/checklist-items', [\App\Http\Controllers\NoteChecklistItemController::class, 'store'])->middleware('workspace.write');
    Route::put('/workspaces/{workspace}/notes/{note}/checklist-items/{item}', [\App\Http\Controllers\NoteChecklistItemController::class, 'update'])->middleware('workspace.write');
    Route::delete('/workspaces/{workspace}/notes/{note}/checklist-items/{item}', [\App\Http\Controllers\NoteChecklistItemController::class, 'destroy'])->middleware('workspace.write');
    Route::get('/workspaces/{workspace}/notes/{note}/activity', [\App\Http\Controllers\WorkspaceNoteActivityController::class, 'index']);
    Route::get('/workspaces/{workspace}/notes/{note}/share', [NoteShareController::class, 'show']);
    Route::post('/workspaces/{workspace}/notes/{note}/share', [NoteShareController::class, 'store'])->middleware('workspace.write');
    Route::delete('/workspaces/{workspace}/notes/{note}/share', [NoteShareController::class, 'destroy'])->middleware('workspace.write');

    Route::get('/workspaces/{workspace}/notifications', [\App\Http\Controllers\WorkspaceNotificationController::class, 'index']);
    Route::post('/workspaces/{workspace}/notifications/{notification}/read', [\App\Http\Controllers\WorkspaceNotificationController::class, 'markAsRead']);
    Route::delete('/workspaces/{workspace}/notifications/{notification}', [\App\Http\Controllers\WorkspaceNotificationController::class, 'destroy']);

    Route::get('/workspaces/{workspace}/collections', [\App\Http\Controllers\WorkspaceCollectionController::class, 'index']);
    Route::get('/workspaces/{workspace}/boards', [\App\Http\Controllers\BoardController::class, 'index']);
    Route::post('/workspaces/{workspace}/boards', [\App\Http\Controllers\BoardController::class, 'store'])->middleware('workspace.write');
    Route::put('/workspaces/{workspace}/boards/{board}', [\App\Http\Controllers\BoardController::class, 'update'])->middleware('workspace.write');
    Route::delete('/workspaces/{workspace}/boards/{board}', [\App\Http\Controllers\BoardController::class, 'destroy'])->middleware('workspace.write');
    Route::post('/workspaces/{workspace}/notes', [WorkspaceNoteController::class, 'store'])->middleware('workspace.write');
    Route::post('/workspaces/{workspace}/notes/from-template', [\App\Http\Controllers\WorkspaceTemplateController::class, 'createFromTemplate'])->middleware('workspace.write');
    Route::match(['get', 'post'], '/workspaces/{workspace}/daily/{date?}', [\App\Http\Controllers\WorkspaceTemplateController::class, 'dailyNote'])->middleware('workspace.write');
    Route::get('/workspaces/{workspace}/notes/{note}', [WorkspaceNoteController::class, 'show']);
    Route::get('/workspaces/{workspace}/notes/{note}/review', [WorkspaceNoteReviewController::class, 'show']);
    Route::put('/workspaces/{workspace}/notes/{note}/reviewer', [WorkspaceNoteReviewController::class, 'assignReviewer']);
    Route::post('/workspaces/{workspace}/notes/{note}/review/submit', [WorkspaceNoteReviewController::class, 'submit']);
    Route::post('/workspaces/{workspace}/notes/{note}/review/approve', [WorkspaceNoteReviewController::class, 'approve']);
    Route::post('/workspaces/{workspace}/notes/{note}/review/request-changes', [WorkspaceNoteReviewController::class, 'requestChanges']);
    Route::get('/workspaces/{workspace}/notes/{note}/acl', [WorkspaceNoteAclController::class, 'show']);
    Route::put('/workspaces/{workspace}/notes/{note}/watch', [\App\Http\Controllers\WorkspaceNoteWatchController::class, 'update']);
    Route::put('/workspaces/{workspace}/notes/{note}/acl', [WorkspaceNoteAclController::class, 'replace']);
    Route::get('/workspaces/{workspace}/notes/{note}/outgoing-links', [WorkspaceNoteController::class, 'outgoingLinks']);
    Route::put('/workspaces/{workspace}/notes/{note}', [WorkspaceNoteController::class, 'update'])->middleware('workspace.write');
    Route::post('/workspaces/{workspace}/notes/{note}/move', [WorkspaceNoteController::class, 'move'])->middleware('workspace.write');
    Route::get('/workspaces/{workspace}/note-tree/order', [WorkspaceNoteTreeController::class, 'index']);
    Route::put('/workspaces/{workspace}/note-tree/order', [WorkspaceNoteTreeController::class, 'update'])->middleware('workspace.write');
    Route::delete('/workspaces/{workspace}/notes/{note}', [WorkspaceNoteController::class, 'destroy'])->middleware('workspace.write');
    Route::post('/workspaces/{workspace}/trash/{note}/restore', [WorkspaceTrashController::class, 'restore'])->middleware('workspace.write');
    Route::delete('/workspaces/{workspace}/trash/{note}', [WorkspaceTrashController::class, 'destroy'])->middleware('workspace.write');

    Route::get('/workspaces/{workspace}/notes/{note}/revisions', [\App\Http\Controllers\WorkspaceNoteRevisionController::class, 'index']);
    Route::get('/workspaces/{workspace}/notes/{note}/revisions/{revision}', [\App\Http\Controllers\WorkspaceNoteRevisionController::class, 'show']);
    Route::post('/workspaces/{workspace}/notes/{note}/revisions/{revision}/restore', [\App\Http\Controllers\WorkspaceNoteRevisionController::class, 'restore'])->middleware('workspace.write');

    Route::get('/workspaces/{workspace}/properties', [\App\Http\Controllers\WorkspacePropertyController::class, 'index']);
    Route::post('/workspaces/{workspace}/notes/{note}/properties', [\App\Http\Controllers\WorkspacePropertyController::class, 'setProperty'])->middleware('workspace.write');
    Route::delete('/workspaces/{workspace}/notes/{note}/properties/{key}', [\App\Http\Controllers\WorkspacePropertyController::class, 'deleteProperty'])->middleware('workspace.write');

    Route::get('/workspaces/{workspace}/attachments', [AttachmentController::class, 'index']);
    Route::post('/workspaces/{workspace}/attachments', [AttachmentController::class, 'store'])->middleware('workspace.write');
    Route::get('/workspaces/{workspace}/attachments/{path}', [AttachmentController::class, 'show'])->where('path', '.*');
    Route::delete('/workspaces/{workspace}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->where('attachment', '.*')->middleware('workspace.write');
});
