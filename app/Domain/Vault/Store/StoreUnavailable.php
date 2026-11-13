<?php

namespace App\Domain\Vault\Store;

use RuntimeException;

/** The configured store cannot be used (driver disabled, not implemented yet, misconfigured or unreachable). */
final class StoreUnavailable extends RuntimeException {}
