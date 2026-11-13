<?php

namespace Tests\Support;

use App\Domain\Vault\Store\S3\S3StoreFactory;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * In-memory S3 REST API (path-style) behind Symfony's MockHttpClient, enough
 * for the S3 driver: PUT (If-Match / If-None-Match, x-amz-meta-*), GET, HEAD,
 * DELETE, CopyObject, ListObjectsV2 (prefix, start-after, max-keys,
 * continuation-token), GetBucketVersioning and GetBucketLifecycleConfiguration.
 *
 * Switches: down() answers every call with a transport error, deny() with 403
 * AccessDenied; noConditionalWrites() ignores If-Match like GCS would.
 */
final class FakeS3
{
    /** @var array<string, array<string, array{body: string, etag: string, meta: array<string, string>, type: string, mtime: int}>> */
    public array $buckets = [];

    /** @var array<string, string> bucket => Enabled|Suspended */
    public array $versioning = [];

    /** @var array<string, int|null> bucket => noncurrent expiry days (null = rule without it) */
    public array $lifecycle = [];

    /** @var list<array{method: string, path: string, query: array<string, string>}> */
    public array $requests = [];

    private bool $down = false;

    private bool $deny = false;

    private bool $ignoreConditions = false;

    private bool $opaqueEtags = false;

    public static function install(): self
    {
        $fake = new self;
        app(S3StoreFactory::class)->useHttpClient(new MockHttpClient(fn (string $method, string $url, array $options) => $fake->handle($method, $url, $options), 'https://s3.fake.test'));

        return $fake;
    }

    public function createBucket(string $bucket, bool $versioned = false): void
    {
        $this->buckets[$bucket] ??= [];
        if ($versioned) {
            $this->versioning[$bucket] = 'Enabled';
        }
    }

    public function down(bool $down = true): void
    {
        $this->down = $down;
    }

    public function deny(bool $deny = true): void
    {
        $this->deny = $deny;
    }

    public function noConditionalWrites(bool $ignore = true): void
    {
        $this->ignoreConditions = $ignore;
    }

    /** ETags that are not the MD5 of the body (multipart uploads, SSE-KMS). */
    public function opaqueEtags(bool $opaque = true): void
    {
        $this->opaqueEtags = $opaque;
    }

    /** Write an object as another tool would (external change). */
    public function putExternal(string $bucket, string $key, string $body): string
    {
        $etag = md5($body);
        $this->buckets[$bucket][$key] = ['body' => $body, 'etag' => $etag, 'meta' => [], 'type' => 'application/octet-stream', 'mtime' => time()];

        return $etag;
    }

    /** @return list<string> */
    public function keys(string $bucket, string $prefix = ''): array
    {
        $keys = array_values(array_filter(array_keys($this->buckets[$bucket] ?? []), static fn (string $key): bool => str_starts_with($key, $prefix)));
        sort($keys, SORT_STRING);

        return $keys;
    }

    public function object(string $bucket, string $key): ?array
    {
        return $this->buckets[$bucket][$key] ?? null;
    }

    public function count(string $method, ?string $operation = null): int
    {
        return count(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method
            && ($operation === null || $operation === 'object' && str_contains($r['path'], '/') && ! str_ends_with($r['path'], '/'))));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function handle(string $method, string $url, array $options): MockResponse
    {
        $parts = parse_url($url);
        $path = ltrim(rawurldecode((string) ($parts['path'] ?? '/')), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);
        $this->requests[] = ['method' => $method, 'path' => $path, 'query' => $query];

        if ($this->down) {
            throw new TransportException('Could not resolve host: s3.fake.test');
        }
        if ($this->deny) {
            return $this->error(403, 'AccessDenied', 'Access Denied');
        }

        [$bucket, $key] = array_pad(explode('/', $path, 2), 2, '');
        if (! isset($this->buckets[$bucket])) {
            if ($method === 'PUT' && $key === '') {
                $this->buckets[$bucket] = [];

                return new MockResponse('', ['http_code' => 200]);
            }

            return $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist');
        }

        $headers = [];
        foreach ((array) ($options['normalized_headers'] ?? []) as $name => $values) {
            $value = (string) ($values[0] ?? '');
            $headers[strtolower((string) $name)] = trim(substr($value, strpos($value, ':') + 1));
        }

        if ($key === '') {
            return match (true) {
                $method === 'GET' && array_key_exists('versioning', $query) => $this->xml('<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                    .(isset($this->versioning[$bucket]) ? '<Status>'.$this->versioning[$bucket].'</Status>' : '').'</VersioningConfiguration>'),
                $method === 'GET' && array_key_exists('lifecycle', $query) => $this->lifecycleXml($bucket),
                $method === 'GET' && ($query['list-type'] ?? '') === '2' => $this->listV2($bucket, $query),
                default => $this->error(400, 'NotImplemented', "FakeS3 does not handle {$method} on a bucket"),
            };
        }

        $object = $this->buckets[$bucket][$key] ?? null;

        switch ($method) {
            case 'GET':
            case 'HEAD':
                if ($object === null) {
                    return $method === 'HEAD' ? new MockResponse('', ['http_code' => 404]) : $this->error(404, 'NoSuchKey', 'The specified key does not exist.');
                }
                $responseHeaders = [
                    'ETag' => '"'.$object['etag'].'"',
                    'Content-Length' => (string) strlen($object['body']),
                    'Content-Type' => $object['type'],
                    'Last-Modified' => gmdate('D, d M Y H:i:s', $object['mtime']).' GMT',
                ];
                foreach ($object['meta'] as $name => $value) {
                    $responseHeaders['x-amz-meta-'.$name] = $value;
                }

                return new MockResponse($method === 'HEAD' ? '' : $object['body'], ['http_code' => 200, 'response_headers' => $responseHeaders]);

            case 'PUT':
                if (isset($headers['x-amz-copy-source'])) {
                    [$sourceBucket, $sourceKey] = explode('/', ltrim(rawurldecode($headers['x-amz-copy-source']), '/'), 2);
                    $source = $this->buckets[$sourceBucket][$sourceKey] ?? null;
                    if ($source === null) {
                        return $this->error(404, 'NoSuchKey', 'The specified key does not exist.');
                    }
                    $this->buckets[$bucket][$key] = ['mtime' => time()] + $source;

                    return $this->xml('<CopyObjectResult><ETag>"'.$source['etag'].'"</ETag><LastModified>'.gmdate('Y-m-d\TH:i:s.000\Z').'</LastModified></CopyObjectResult>');
                }

                if (! $this->ignoreConditions) {
                    if (($headers['if-none-match'] ?? null) === '*' && $object !== null) {
                        return $this->error(412, 'PreconditionFailed', 'At least one of the pre-conditions you specified did not hold');
                    }
                    if (isset($headers['if-match']) && ($object === null || trim($headers['if-match'], '"') !== $object['etag'])) {
                        return $object === null
                            ? $this->error(404, 'NoSuchKey', 'The specified key does not exist.')
                            : $this->error(412, 'PreconditionFailed', 'At least one of the pre-conditions you specified did not hold');
                    }
                }

                $body = $this->body($options);
                $meta = [];
                foreach ($headers as $name => $value) {
                    if (str_starts_with($name, 'x-amz-meta-')) {
                        $meta[substr($name, 11)] = $value;
                    }
                }
                $etag = md5($body).($this->opaqueEtags ? '-1' : '');
                $this->buckets[$bucket][$key] = ['body' => $body, 'etag' => $etag, 'meta' => $meta, 'type' => $headers['content-type'] ?? 'application/octet-stream', 'mtime' => time()];

                return new MockResponse('', ['http_code' => 200, 'response_headers' => ['ETag' => '"'.$etag.'"']]);

            case 'DELETE':
                unset($this->buckets[$bucket][$key]);

                return new MockResponse('', ['http_code' => 204]);
        }

        return $this->error(405, 'MethodNotAllowed', $method);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function listV2(string $bucket, array $query): MockResponse
    {
        $prefix = (string) ($query['prefix'] ?? '');
        $max = min(1000, (int) ($query['max-keys'] ?? 1000));
        $after = isset($query['continuation-token']) ? base64_decode((string) $query['continuation-token']) : (string) ($query['start-after'] ?? '');

        $keys = array_values(array_filter($this->keys($bucket, $prefix), static fn (string $key): bool => $after === '' || strcmp($key, $after) > 0));
        $page = array_slice($keys, 0, $max);
        $truncated = count($keys) > $max;

        $xml = '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Name>'.htmlspecialchars($bucket).'</Name>'
            .'<Prefix>'.htmlspecialchars($prefix).'</Prefix><KeyCount>'.count($page).'</KeyCount><MaxKeys>'.$max.'</MaxKeys>'
            .'<IsTruncated>'.($truncated ? 'true' : 'false').'</IsTruncated>';
        if ($truncated && $page !== []) {
            $xml .= '<NextContinuationToken>'.base64_encode($page[count($page) - 1]).'</NextContinuationToken>';
        }
        foreach ($page as $key) {
            $object = $this->buckets[$bucket][$key];
            $xml .= '<Contents><Key>'.htmlspecialchars($key, ENT_XML1).'</Key><LastModified>'.gmdate('Y-m-d\TH:i:s.000\Z', $object['mtime']).'</LastModified>'
                .'<ETag>"'.$object['etag'].'"</ETag><Size>'.strlen($object['body']).'</Size><StorageClass>STANDARD</StorageClass></Contents>';
        }

        return $this->xml($xml.'</ListBucketResult>');
    }

    private function lifecycleXml(string $bucket): MockResponse
    {
        if (! array_key_exists($bucket, $this->lifecycle)) {
            return $this->error(404, 'NoSuchLifecycleConfiguration', 'The lifecycle configuration does not exist');
        }
        $days = $this->lifecycle[$bucket];

        return $this->xml('<LifecycleConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Rule><ID>expire</ID><Filter><Prefix></Prefix></Filter><Status>Enabled</Status>'
            .($days === null ? '<Expiration><Days>365</Days></Expiration>' : '<NoncurrentVersionExpiration><NoncurrentDays>'.$days.'</NoncurrentDays></NoncurrentVersionExpiration>')
            .'</Rule></LifecycleConfiguration>');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function body(array $options): string
    {
        $body = $options['body'] ?? '';
        if (is_string($body)) {
            return $body;
        }
        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }
        if ($body instanceof \Closure) {
            $out = '';
            while (($chunk = $body(16384)) !== '') {
                $out .= $chunk;
            }

            return $out;
        }
        if (is_iterable($body)) {
            $out = '';
            foreach ($body as $chunk) {
                $out .= $chunk;
            }

            return $out;
        }

        return '';
    }

    private function xml(string $xml): MockResponse
    {
        return new MockResponse('<?xml version="1.0" encoding="UTF-8"?>'.$xml, ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/xml']]);
    }

    private function error(int $status, string $code, string $message): MockResponse
    {
        return new MockResponse(
            '<?xml version="1.0" encoding="UTF-8"?><Error><Code>'.$code.'</Code><Message>'.htmlspecialchars($message).'</Message><RequestId>fake</RequestId></Error>',
            ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/xml']],
        );
    }
}
