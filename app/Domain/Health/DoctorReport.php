<?php

namespace App\Domain\Health;

final class DoctorReport
{
    /**
     * @param  list<DoctorCheck>  $checks
     */
    public function __construct(
        public readonly ?string $instance,
        public readonly ?string $version,
        public readonly string $environment,
        public readonly array $checks,
    ) {}

    public function hasCriticalFailures(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->status() === 'fail') {
                return true;
            }
        }

        return false;
    }

    /**
     * ok | warn | fail
     */
    public function status(): string
    {
        if ($this->hasCriticalFailures()) {
            return 'fail';
        }

        foreach ($this->checks as $check) {
            if ($check->status() === 'warn') {
                return 'warn';
            }
        }

        return 'ok';
    }

    /**
     * @return array{passed: int, warnings: int, failures: int}
     */
    public function summary(): array
    {
        $summary = ['passed' => 0, 'warnings' => 0, 'failures' => 0];
        foreach ($this->checks as $check) {
            match ($check->status()) {
                'pass' => $summary['passed']++,
                'warn' => $summary['warnings']++,
                default => $summary['failures']++,
            };
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'instance' => $this->instance,
            'version' => $this->version,
            'environment' => $this->environment,
            'status' => $this->status(),
            'summary' => $this->summary(),
            'checks' => array_map(static fn (DoctorCheck $check): array => $check->toArray(), $this->checks),
        ];
    }
}
