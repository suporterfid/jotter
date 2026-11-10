<?php

namespace App\Domain\Auth\TwoFactor;

use RuntimeException;

/**
 * The password was right and the account has two-step verification: the
 * session now holds a pending challenge (TwoFactorChallenge) and nobody is
 * signed in until a code completes it.
 */
final class TwoFactorRequired extends RuntimeException
{
}
