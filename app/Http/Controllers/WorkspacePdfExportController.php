<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Domain\Jobs\Contracts\JobDispatcher;
use App\Models\PdfExport;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class WorkspacePdfExportController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
        private readonly JobDispatcher $dispatcher,
    ) {}

    public function store(Request $request, Workspace $workspace): Response
    {
        $subject = $this->subject($request);
        if (! $subject) {
            return response(__('messages.unauthenticated'), 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspace->id)) {
            return response(__('messages.forbidden'), 403);
        }

        $noteIds = $this->noteAccess
            ->scopeVisible($workspace->notes()->getQuery(), $subject, $workspace->id)
            ->orderBy('path')
            ->pluck('notes.id')
            ->all();

        $export = PdfExport::create([
            'workspace_id' => $workspace->id,
            'scope' => 'workspace',
            'requested_by_subject' => $subject->subjectId,
            'note_ids' => $noteIds,
        ]);

        $jobId = $this->dispatcher->dispatch(
            \App\Jobs\GeneratePdfExport::class,
            ['export_id' => $export->id],
            $workspace->id,
        );

        $export->forceFill(['dispatcher_job_id' => $jobId])->save();

        return response()->json([
            'id' => $export->id,
            'status' => $export->status,
            'scope' => $export->scope,
        ], 202);
    }

    public function show(Request $request, Workspace $workspace, string $export): Response
    {
        $pdfExport = $this->authorizedExport($request, $workspace, $export);

        return response()->json([
            'id' => $pdfExport->id,
            'status' => $pdfExport->status,
            'scope' => $pdfExport->scope,
            'queued_at' => $pdfExport->queued_at?->toISOString(),
            'started_at' => $pdfExport->started_at?->toISOString(),
            'completed_at' => $pdfExport->completed_at?->toISOString(),
            'expires_at' => $pdfExport->expires_at?->toISOString(),
            'failure_reason' => $pdfExport->status === 'failed' ? $pdfExport->failure_reason : null,
        ]);
    }

    public function download(Request $request, Workspace $workspace, string $export): Response
    {
        $pdfExport = $this->authorizedExport($request, $workspace, $export);
        $path = $pdfExport->getRawOriginal('output_path');

        if ($pdfExport->status !== 'ready' || ! is_string($path) || ! is_file($path)) {
            abort(404);
        }

        return response()->download(
            $path,
            'workspace-'.($workspace->slug ?: $workspace->id).'.pdf',
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    private function authorizedExport(Request $request, Workspace $workspace, string $exportId): PdfExport
    {
        $subject = $this->subject($request);
        if (! $subject) {
            abort(401);
        }

        $export = PdfExport::query()
            ->whereKey($exportId)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();

        if (! $subject->isAdmin && $export->requested_by_subject !== $subject->subjectId) {
            abort(403);
        }

        return $export;
    }

    private function subject(Request $request): ?AuthenticatedSubject
    {
        return $request->attributes->get('authenticated_subject')
            ?? $this->identityProvider->resolveIdentity($request);
    }
}
