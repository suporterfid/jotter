<?php

namespace App\Domain\Health;

final class DoctorCheck
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly bool $critical,
        public readonly bool $passed,
        public readonly string $message,
        public readonly array $details = [],
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public static function pass(string $id, string $label, bool $critical, string $message, array $details = []): self
    {
        return new self($id, $label, $critical, true, $message, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function fail(string $id, string $label, bool $critical, string $message, array $details = []): self
    {
        return new self($id, $label, $critical, false, $message, $details);
    }

    /**
     * pass | warn | fail — a failed non-critical check is a warning.
     */
    public function status(): string
    {
        if ($this->passed) {
            return 'pass';
        }

        return $this->critical ? 'fail' : 'warn';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'severity' => $this->critical ? 'critical' : 'warning',
            'status' => $this->status(),
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
