<?php

namespace App\Domain\Plan;

use Carbon\CarbonInterface;

enum PlanStatus: string
{
    case SELF_HOSTED = 'self_hosted';
    case TRIAL = 'trial';
    case ACTIVE = 'active';
    case PAST_DUE = 'past_due';
    case READ_ONLY = 'read_only';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * Whether a tenant in this status may write, given its trial deadline.
     * `self_hosted` never restricts anything; a trial writes until it ends.
     */
    public function allowsWrites(?CarbonInterface $trialEndsAt, CarbonInterface $now): bool
    {
        return match ($this) {
            self::SELF_HOSTED, self::ACTIVE => true,
            self::TRIAL => $trialEndsAt === null || $trialEndsAt->greaterThan($now),
            self::PAST_DUE, self::READ_ONLY => false,
        };
    }
}
