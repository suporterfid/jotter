<?php

namespace Tests\Unit;

use App\Domain\Auth\MachineTokenOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MachineTokenWritePathTest extends TestCase
{
    public function test_cli_form_is_parsed(): void
    {
        $this->assertSame(
            ['glob' => 'inbox/{client}/**', 'ops' => ['create', 'append', 'update']],
            MachineTokenOptions::normalizeWritePath('inbox/{client}/**:create, append,UPDATE'),
        );
        $this->assertSame(
            ['glob' => 'diario/**', 'ops' => ['append']],
            MachineTokenOptions::normalizeWritePath(['glob' => 'diario/**', 'ops' => ['append', 'append']]),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejected(): array
    {
        return [
            'no ops separator' => ['inbox/**'],
            'absolute' => ['/etc/**:create'],
            'traversal' => ['inbox/../_brain/x.md:update'],
            'backslash' => ['inbox\\x.md:create'],
            'brain folder' => ['_brain/instructions.md:update'],
            'brain folder itself' => ['_brain:create'],
            'trash' => ['.trash/**:create'],
            'templates' => ['_templates/**:create'],
            'wildcard top level' => ['**:create'],
            'wildcard prefix' => ['*brain/**:create'],
            'single char wildcard' => ['?brain/**:create'],
            'char class' => ['[_]brain/**:create'],
            'unknown op' => ['inbox/**:delete'],
            'empty ops' => ['inbox/**:'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_unsafe_write_paths_are_rejected(string $entry): void
    {
        $this->expectException(InvalidArgumentException::class);
        MachineTokenOptions::normalizeWritePath($entry);
    }
}
