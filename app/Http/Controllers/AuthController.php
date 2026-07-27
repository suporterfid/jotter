<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Contracts\IdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $identityProvider
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $subject = $this->identityProvider->authenticate($validated, $request);

        if (! $subject) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        return response()->json([
            'data' => [
                'subject_id' => $subject->subjectId,
                'email' => $subject->email,
                'name' => $subject->name,
                'is_admin' => $subject->isAdmin,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->identityProvider->logout($request);

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $subject = $this->identityProvider->resolveIdentity($request);

        if (! $subject) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json([
            'data' => [
                'subject_id' => $subject->subjectId,
                'email' => $subject->email,
                'name' => $subject->name,
                'is_admin' => $subject->isAdmin,
            ],
        ]);
    }
}
