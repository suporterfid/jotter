<?php

namespace App\Domain\Vault\Move;

use RuntimeException;

/** A vault:move preflight, rollback or finalize step refused to run; the message says why. */
final class VaultMoveRefused extends RuntimeException {}
