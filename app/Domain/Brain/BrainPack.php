<?php

namespace App\Domain\Brain;

use App\Domain\Vault\VaultStorage;
use App\Models\Workspace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * The `brain` vault convention (brain plan PR 4, R6): `_brain/` router and agent
 * rules, `projetos/`, `decisoes/`, `diario/`, `inbox/`, `pessoas/`. Shipped as
 * Markdown under `resources/brain/<locale>/`; folder names are the same in every
 * locale so agent write paths (`inbox/{client}/**`, `diario/**`) never change.
 */
final class BrainPack
{
    public const LOCALES = ['pt-BR', 'en'];

    public function __construct(
        private readonly VaultStorage $storage = new VaultStorage,
    ) {}

    public static function normalizeLocale(?string $locale): string
    {
        return in_array($locale, self::LOCALES, true) ? $locale : 'pt-BR';
    }

    /**
     * @return array<string, string> relative vault path => Markdown contents
     */
    public function files(string $locale): array
    {
        $root = resource_path('brain/'.self::normalizeLocale($locale));
        $files = [];
        if (! is_dir($root)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * Writes every pack file that does not exist yet. Existing files are never
     * overwritten, so running it again is safe.
     *
     * @return array{written: list<string>, skipped: list<string>}
     */
    public function install(Workspace $workspace, string $locale, ?string $actorId = null, bool $dryRun = false): array
    {
        $written = [];
        $skipped = [];
        foreach ($this->files($locale) as $path => $contents) {
            if ($this->storage->exists($workspace, $path)) {
                $skipped[] = $path;

                continue;
            }
            if (! $dryRun) {
                $this->storage->write($workspace, $path, $contents, $actorId);
            }
            $written[] = $path;
        }

        return ['written' => $written, 'skipped' => $skipped];
    }

    public function writeZip(string $locale, string $zipPath): string
    {
        @mkdir(dirname($zipPath), 0755, true);
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Unable to create brain pack ZIP at {$zipPath}.");
        }
        foreach ($this->files($locale) as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();

        return $zipPath;
    }
}
