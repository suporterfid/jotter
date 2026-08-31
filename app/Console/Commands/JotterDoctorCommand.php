<?php

namespace App\Console\Commands;

use App\Domain\Health\DoctorCheck;
use App\Domain\Health\InstanceDoctor;
use Illuminate\Console\Command;

final class JotterDoctorCommand extends Command
{
    protected $signature = 'jotter:doctor {--json : Emit a machine-readable JSON report}';

    protected $description = 'Diagnose this installation: runtime, configuration, storage, database, and scheduler.';

    public function handle(InstanceDoctor $doctor): int
    {
        $report = $doctor->run();

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report->hasCriticalFailures() ? self::FAILURE : self::SUCCESS;
        }

        $this->line(sprintf(
            'Jotter doctor — instance: %s · version: %s · env: %s',
            $report->instance ?? '(unset)',
            $report->version ?? '(dev)',
            $report->environment,
        ));
        $this->newLine();

        foreach ($report->checks as $check) {
            $this->renderCheck($check);
        }

        $summary = $report->summary();
        $this->newLine();
        $this->line(sprintf(
            '%d passed, %d warning(s), %d failure(s) — status: %s',
            $summary['passed'],
            $summary['warnings'],
            $summary['failures'],
            strtoupper($report->status()),
        ));

        return $report->hasCriticalFailures() ? self::FAILURE : self::SUCCESS;
    }

    private function renderCheck(DoctorCheck $check): void
    {
        $line = sprintf('%-28s %s', $check->label, $check->message);

        match ($check->status()) {
            'pass' => $this->line("<info>[PASS]</info> {$line}"),
            'warn' => $this->line("<comment>[WARN]</comment> {$line}"),
            default => $this->line("<error>[FAIL]</error> {$line}"),
        };
    }
}
