<?php

namespace App\Domain\Vault\Store;

use Symfony\Component\HttpFoundation\Response;

/**
 * Serves one vault file over HTTP. Local files keep BinaryFileResponse (Range,
 * Last-Modified); other drivers stream from VaultStore::readStream(). Large
 * files on S3 (over `jotter.s3.presign_over_bytes`) redirect to a 60-second
 * pre-signed URL whose signature forces the response Content-Type and
 * Content-Disposition (#446), so PHP does not proxy them.
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
        $presignOver = (int) config('jotter.s3.presign_over_bytes', 0);
        if ($store instanceof S3\S3VaultStore && $stat !== null && $presignOver > 0 && $stat->size > $presignOver) {
            $url = $store->temporaryUrl(
                $path,
                $headers['Content-Type'] ?? 'application/octet-stream',
                $headers['Content-Disposition'] ?? 'attachment',
                (int) config('jotter.s3.presign_seconds', 60),
            );

            return redirect()->away($url, 302, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        }
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
