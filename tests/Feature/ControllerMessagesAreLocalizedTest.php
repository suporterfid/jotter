<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

final class ControllerMessagesAreLocalizedTest extends TestCase
{
    public function test_no_controller_outside_mcp_hardcodes_a_message_string(): void
    {
        $offenders = [];
        $finder = (new Finder())->files()->in(app_path('Http/Controllers'))->name('*.php');

        foreach ($finder as $file) {
            if ($file->getFilename() === 'McpController.php') {
                continue;
            }

            if (preg_match("/'message'\s*=>\s*['\"]/", $file->getContents())) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'These controllers still hardcode a message string: '.implode(', ', $offenders));
    }
}
