<?php

namespace App\Domain\Provisioning;

use App\Domain\Vault\VaultStorage;
use App\Models\Workspace;
use ZipArchive;

/**
 * Starter templates shipped with the engine (`resources/templates/<locale>/_templates`).
 * They are plain Markdown using the TemplateEngine placeholders, installed into a
 * workspace vault as `_templates/<name>.md` or packaged as a ZIP the import
 * endpoint accepts.
 */
final class TemplatePack
{
    public const LOCALES = ['en', 'pt-BR'];

    public const FOLDER = '_templates';

    public function __construct(
        private readonly VaultStorage $storage,
    ) {}

    public static function normalizeLocale(?string $locale): string
    {
        return in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }

    /**
     * @return array<string, string> relative vault path => Markdown contents
     */
    public function files(string $locale): array
    {
        $dir = resource_path('templates/'.self::normalizeLocale($locale).'/'.self::FOLDER);
        $files = [];
        foreach (glob($dir.'/*.md') ?: [] as $path) {
            $files[self::FOLDER.'/'.basename($path)] = (string) file_get_contents($path);
        }
        ksort($files);

        return $files;
    }

    /**
     * Writes every template into the workspace (projecting notes). Existing
     * templates are left untouched unless $overwrite is true.
     *
     * @return list<string> paths written
     */
    public function install(Workspace $workspace, string $locale, bool $overwrite = false, ?string $actorId = null): array
    {
        $written = [];
        foreach ($this->files($locale) as $path => $contents) {
            if (! $overwrite && $this->storage->exists($workspace, $path)) {
                continue;
            }
            $this->storage->write($workspace, $path, $contents, $actorId);
            $written[] = $path;
        }

        return $written;
    }

    /**
     * Builds a ZIP with the same layout the import endpoint expects.
     */
    public function writeZip(string $locale, string $zipPath): string
    {
        @mkdir(dirname($zipPath), 0755, true);
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Unable to create template pack ZIP at {$zipPath}.");
        }
        foreach ($this->files($locale) as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();

        return $zipPath;
    }
}
