<?php
// Pilote S3 (STO-02), pensé pour versitygw : path style obligatoire, requêtes Range relayées.
// Aucune bibliothèque : curl + S3Signer.

declare(strict_types=1);

namespace Musique\Storage;

use Musique\Config;

final class S3Storage implements Storage
{
    private readonly S3Signer $signer;
    private readonly string $endpoint;

    public function __construct(
        string $endpoint,
        private readonly string $bucket,
        string $region,
        string $accessKey,
        #[\SensitiveParameter] string $secretKey,
        private readonly int $timeoutSeconds = 30,
    ) {
        if (!preg_match('#^https?://[^/]+$#', rtrim($endpoint, '/'))) {
            throw new StorageException(StorageException::UNAVAILABLE, 'Adresse S3 invalide (attendu : http(s)://hôte[:port])');
        }
        $this->endpoint = rtrim($endpoint, '/');
        $this->signer = new S3Signer($accessKey, $secretKey, $region);
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            $config->string('stockage', 's3_url'),
            $config->string('stockage', 's3_bucket'),
            $config->string('stockage', 's3_region', 'us-east-1'),
            $config->string('stockage', 's3_cle'),
            $config->string('stockage', 's3_secret'),
        );
    }

    public function mode(): string
    {
        return 's3';
    }

    public function list(string $prefix = ''): \Generator
    {
        $token = null;
        do {
            $query = ['list-type' => '2', 'max-keys' => '1000'];
            if ($prefix !== '') {
                $query['prefix'] = $prefix;
            }
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            $res = $this->request('GET', null, $query);
            $this->expect($res, [200], 'liste');

            $xml = @simplexml_load_string($res['body']);
            if ($xml === false) {
                throw new StorageException(StorageException::FAILED, 'Réponse S3 illisible (liste)');
            }
            $page = [];
            foreach ($xml->Contents as $c) {
                $key = (string) $c->Key;
                if ($key === '' || str_ends_with($key, '/') || Keys::isHidden($key)) {
                    continue; // marqueurs de dossier, fichiers cachés
                }
                $page[$key] = new StorageObject(
                    $key,
                    (int) $c->Size,
                    self::parseDate((string) $c->LastModified),
                    trim((string) $c->ETag, '"') ?: null,
                );
            }
            // S3 renvoie déjà l'ordre des octets ; on le garantit quand même.
            ksort($page, SORT_STRING);
            yield from array_values($page);

            $token = ((string) $xml->IsTruncated) === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');
    }

    public function stat(string $key): ?StorageObject
    {
        Keys::assertValid($key);
        $res = $this->request('HEAD', $key);
        if ($res['status'] === 404) {
            return null;
        }
        $this->expect($res, [200], 'infos', $key);
        return new StorageObject(
            $key,
            (int) ($res['headers']['content-length'] ?? 0),
            self::parseDate($res['headers']['last-modified'] ?? ''),
            isset($res['headers']['etag']) ? trim($res['headers']['etag'], '"') : null,
        );
    }

    public function get(string $key): string
    {
        Keys::assertValid($key);
        $res = $this->request('GET', $key);
        $this->expect($res, [200], 'lecture', $key);
        return $res['body'];
    }

    public function stream(string $key, int $offset, ?int $length, callable $sink): void
    {
        Keys::assertValid($key);
        if ($offset < 0 || ($length !== null && $length < 0)) {
            throw new StorageException(StorageException::INVALID_RANGE, 'Plage négative');
        }
        if ($length === 0) {
            return;
        }
        $headers = [];
        if ($offset > 0 || $length !== null) {
            $headers['range'] = 'bytes=' . $offset . '-' . ($length === null ? '' : $offset + $length - 1);
        }
        $res = $this->request('GET', $key, [], $headers, sink: $sink);
        if ($res['status'] === 416) {
            throw new StorageException(StorageException::INVALID_RANGE, "Début de plage ($offset) au-delà du fichier : $key");
        }
        $this->expect($res, [200, 206], 'lecture', $key);
    }

    public function put(string $key, string $localFile, ?string $contentType = null): StorageObject
    {
        Keys::assertValid($key);
        $size = @filesize($localFile);
        $fh = @fopen($localFile, 'rb');
        if ($size === false || $fh === false) {
            throw new StorageException(StorageException::FAILED, "Fichier local illisible pour : $key");
        }
        try {
            $headers = ['content-type' => $contentType ?? 'application/octet-stream'];
            $res = $this->request('PUT', $key, [], $headers, upload: [$fh, $size]);
        } finally {
            fclose($fh);
        }
        $this->expect($res, [200], 'écriture', $key);
        return $this->stat($key) ?? throw new StorageException(StorageException::FAILED, "Fichier absent après écriture : $key");
    }

    public function move(string $from, string $to): StorageObject
    {
        Keys::assertValid($from);
        Keys::assertValid($to);
        $src = $this->stat($from) ?? throw new StorageException(StorageException::NOT_FOUND, "Fichier introuvable : $from");
        if ($this->stat($to) !== null) {
            throw new StorageException(StorageException::FAILED, "Déplacement refusé, le fichier existe déjà : $to");
        }
        $res = $this->request('PUT', $to, [], ['x-amz-copy-source' => '/' . rawurlencode($this->bucket) . '/' . S3Signer::encodeKey($from)]);
        // Une copie peut répondre 200 avec une erreur dans le corps
        if ($res['status'] !== 200 || str_contains($res['body'], '<Error>')) {
            throw new StorageException(StorageException::FAILED, "Copie impossible (HTTP {$res['status']}), original gardé : $from");
        }
        $copy = $this->stat($to);
        if ($copy === null || $copy->size !== $src->size) {
            throw new StorageException(StorageException::FAILED, "Copie incomplète, original gardé : $from");
        }
        $this->delete($from);
        return $copy;
    }

    public function delete(string $key): void
    {
        Keys::assertValid($key);
        $res = $this->request('DELETE', $key);
        $this->expect($res, [200, 204, 404], 'suppression', $key);
    }

    public function mediaSource(string $key, int $ttlSeconds = 3600): string
    {
        Keys::assertValid($key);
        return $this->signer->presign('GET', $this->url($key), $ttlSeconds);
    }

    private function url(?string $key, array $query = []): string
    {
        $url = $this->endpoint . '/' . rawurlencode($this->bucket);
        if ($key !== null) {
            $url .= '/' . S3Signer::encodeKey($key);
        }
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param ?callable(string): void $sink  reçoit le corps si la réponse est un succès
     * @param ?array{0: resource, 1: int} $upload
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, ?string $key, array $query = [], array $headers = [], ?callable $sink = null, ?array $upload = null): array
    {
        $url = $this->url($key, $query);
        $payload = $upload !== null ? S3Signer::UNSIGNED_PAYLOAD : S3Signer::EMPTY_PAYLOAD;
        $signed = $this->signer->sign($method, $url, $headers, $payload);

        $ch = curl_init($url);
        $respHeaders = [];
        $body = '';
        $status = 0;
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_map(static fn ($k, $v) => "$k: $v", array_keys($signed), $signed),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => $this->timeoutSeconds,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$body, &$status, $sink): int {
                $status = $status ?: (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($sink !== null && $status >= 200 && $status < 300) {
                    $sink($data);
                } elseif (strlen($body) < 1048576 || $sink === null) {
                    $body .= $data;
                }
                return strlen($data);
            },
        ];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        if ($upload !== null) {
            $opts[CURLOPT_UPLOAD] = true;
            $opts[CURLOPT_INFILE] = $upload[0];
            $opts[CURLOPT_INFILESIZE] = $upload[1];
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($ok === false) {
            // Le message de curl ne contient ni l'en-tête Authorization ni le secret.
            throw new StorageException(StorageException::UNAVAILABLE, "S3 injoignable ($method) : $error");
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body];
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $res
     * @param list<int> $okStatuses
     */
    private function expect(array $res, array $okStatuses, string $action, ?string $key = null): void
    {
        if (in_array($res['status'], $okStatuses, true)) {
            return;
        }
        $code = '';
        if (preg_match('#<Code>([^<]+)</Code>#', $res['body'], $m)) {
            $code = $m[1];
        }
        $what = $key === null ? $action : "$action de $key";
        $reason = match (true) {
            $res['status'] === 404 => StorageException::NOT_FOUND,
            $res['status'] >= 500 => StorageException::UNAVAILABLE,
            default => StorageException::FAILED,
        };
        throw new StorageException($reason, "S3 : échec de la $what (HTTP {$res['status']}" . ($code !== '' ? ", $code" : '') . ')');
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
