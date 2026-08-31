<?php

namespace App\Support;

/**
 * Resolves the release version written by `jt release` into `VERSION` at the
 * application root. Development checkouts have no VERSION file and report null.
 */
final class ReleaseVersion
{
    public static function current(): ?string
    {
        $path = base_path('VERSION');
        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $version = trim($contents);

        return $version === '' ? null : $version;
    }
}
