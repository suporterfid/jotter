<?php

namespace App\Domain\Vault\Store;

use Symfony\Component\HttpFoundation\Response;

/**
 * Serves one vault file over HTTP. Local files keep BinaryFileResponse (Range,
 * Last-Modified); other drivers stream from VaultStore::readStream().
 */
final class VaultFileResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public static function make(VaultStore $store, string $path, array $headers = []): Response
    {
        if ($store instanceof LocalVaultStore) {
            return response()->file($store->absolutePath($path), $headers);
        }

        $stat = $store->stat($path);
        if ($stat !== null && ! isset($headers['Content-Length'])) {
            $headers['Content-Length'] = (string) $stat->size;
        }

        return response()->stream(function () use ($store, $path): void {
            $stream = $store->readStream($path);
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }
}
