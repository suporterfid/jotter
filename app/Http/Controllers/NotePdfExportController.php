<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AuthenticatedSubject;
use App\Domain\Auth\Contracts\IdentityProvider;
use App\Domain\Auth\NoteAccess;
use App\Domain\Export\PdfDocumentRenderer;
use App\Domain\Vault\Exceptions\VaultNoteNotFound;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class NotePdfExportController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider,
        private readonly NoteAccess $noteAccess,
        private readonly PdfDocumentRenderer $renderer,
    ) {}

    public function export(Request $request, Workspace $workspace, int $note): Response
    {
        $subject = $this->subject($request);
        if (! $subject) {
            return response(__('messages.unauthenticated'), 401);
        }

        if (! $this->identityProvider->isAuthorizedForWorkspace($subject, $workspace->id)) {
            return response(__('messages.forbidden'), 403);
        }

        $noteModel = $workspace->notes()->findOrFail($note);
        $this->noteAccess->assertView($subject, $noteModel);

        try {
            $pdf = $this->renderer->renderNote($workspace, $noteModel);
        } catch (VaultNoteNotFound) {
            abort(404);
        }

        $baseName = Str::slug((string) ($noteModel->title ?: pathinfo($noteModel->path, PATHINFO_FILENAME)));
        $fileName = ($baseName !== '' ? $baseName : 'note-'.$noteModel->id).'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename='.$fileName,
            'Content-Length' => (string) strlen($pdf),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function subject(Request $request): ?AuthenticatedSubject
    {
        return $request->attributes->get('authenticated_subject')
            ?? $this->identityProvider->resolveIdentity($request);
    }
}
