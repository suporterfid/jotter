<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Mail\SignupRequestedEmail;
use App\Models\User;
use App\Support\LegalTerms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * Self sign-up with administrator approval (#390). The account is created
 * inactive (`signup_status=pending`) and administrators are e-mailed; nobody
 * can sign in until one approves. The answer is the same whether or not the
 * e-mail already has an account, so the form cannot be used to probe accounts.
 */
final class SignupController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit = new AuditRecorder) {}

    public static function enabled(): bool
    {
        return (bool) config('jotter.signup.enabled', false) && config('jotter.auth_provider', 'local') === 'local';
    }

    public function store(Request $request): JsonResponse
    {
        if (! self::enabled()) {
            return response()->json(['message' => __('messages.signup_disabled')], 404);
        }

        $key = 'signup:'.$request->ip();
        $max = max(1, (int) config('jotter.signup.requests_per_hour', 5));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return response()->json(['message' => __('messages.too_many_attempts')], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        $termsRequired = LegalTerms::linksConfigured();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'string', 'email', 'max:191'],
            'password' => ['required', 'string', 'confirmed', Password::default()],
            'accept_terms' => [$termsRequired ? 'accepted' : 'nullable'],
            'locale' => ['nullable', 'string', 'in:en,pt-BR'],
        ], ['accept_terms.accepted' => __('messages.signup_terms_required')]);
        RateLimiter::hit($key, 3600);

        $email = Str::lower(trim($validated['email']));
        $allowed = (array) config('jotter.signup.allowed_domains', []);
        if ($allowed !== [] && ! in_array(Str::after($email, '@'), $allowed, true)) {
            return response()->json(['message' => __('messages.signup_domain_not_allowed'), 'errors' => ['email' => [__('messages.signup_domain_not_allowed')]]], 422);
        }

        $received = response()->json(['message' => __('messages.signup_received')], 202);
        if (User::query()->where('email', $email)->exists()) {
            return $received;
        }

        $user = User::query()->create([
            'name' => trim($validated['name']),
            'email' => $email,
            'password' => Hash::make($validated['password']),
            'is_admin' => false,
            'is_active' => false,
            'locale' => $validated['locale'] ?? app()->getLocale(),
            'signup_status' => 'pending',
            'signup_requested_at' => now(),
            'terms_accepted_at' => $termsRequired ? now() : null,
            'terms_version' => $termsRequired ? LegalTerms::currentVersion() : null,
        ]);

        $this->audit->record(event: AuditEvent::SIGNUP_REQUESTED, actorId: (string) $user->id, metadata: ['user_id' => $user->id, 'ip' => $request->ip()]);

        foreach (User::query()->where('is_admin', true)->where('is_active', true)->get() as $administrator) {
            try {
                Mail::to($administrator->email)->send(new SignupRequestedEmail($administrator, $user));
            } catch (Throwable $exception) {
                Log::warning('Sign-up notification e-mail failed', ['admin_id' => $administrator->id, 'error' => $exception->getMessage()]);
            }
        }

        return $received;
    }
}
