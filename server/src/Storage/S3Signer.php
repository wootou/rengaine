<?php
// Signature AWS v4 des requêtes S3 (STO-02), écrite ici faute de bibliothèque.
// Référence : https://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-authenticating-requests.html
// Vérifiée par les exemples officiels d'AWS (tests/unit/S3SignerTest.php).

declare(strict_types=1);

namespace Musique\Storage;

final class S3Signer
{
    public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';
    public const EMPTY_PAYLOAD = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function __construct(
        private readonly string $accessKey,
        #[\SensitiveParameter] private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service = 's3',
    ) {
    }

    /**
     * En-têtes à ajouter à la requête (x-amz-date, x-amz-content-sha256, Authorization).
     * Tous les en-têtes passés dans $headers sont signés.
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function sign(string $method, string $url, array $headers, string $payloadHash, ?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');

        $parts = parse_url($url);
        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['host'] = self::hostHeader($parts);
        $headers['x-amz-date'] = $amzDate;
        $headers['x-amz-content-sha256'] = $payloadHash;
        ksort($headers, SORT_STRING);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim(preg_replace('/\s+/', ' ', $value)) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $parts['path'] ?? '/',
            self::canonicalQuery($parts['query'] ?? ''),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = "$date/{$this->region}/{$this->service}/aws4_request";
        $signature = $this->signature($date, $amzDate, $scope, $canonicalRequest);

        unset($headers['host']);
        $headers['authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey, $scope, $signedHeaders, $signature
        );
        return $headers;
    }

    /** Adresse signée valable $expires secondes (lecture par ffprobe, sans en-tête d'authentification). */
    public function presign(string $method, string $url, int $expires, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $scope = "$date/{$this->region}/{$this->service}/aws4_request";

        $parts = parse_url($url);
        $query = ($parts['query'] ?? '') === '' ? '' : $parts['query'] . '&';
        $query .= http_build_query([
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$this->accessKey}/$scope",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ], '', '&', PHP_QUERY_RFC3986);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $parts['path'] ?? '/',
            self::canonicalQuery($query),
            'host:' . self::hostHeader($parts) . "\n",
            'host',
            self::UNSIGNED_PAYLOAD,
        ]);
        $signature = $this->signature($date, $amzDate, $scope, $canonicalRequest);

        $base = $parts['scheme'] . '://' . self::hostHeader($parts) . ($parts['path'] ?? '/');
        return $base . '?' . self::canonicalQuery($query) . '&X-Amz-Signature=' . $signature;
    }

    /** Encode une clé pour l'adresse : chaque segment encodé, les « / » gardés. */
    public static function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private function signature(string $date, string $amzDate, string $scope, string $canonicalRequest): string
    {
        $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);
        $k = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $k = hash_hmac('sha256', $this->region, $k, true);
        $k = hash_hmac('sha256', $this->service, $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        return hash_hmac('sha256', $stringToSign, $k);
    }

    /** Paramètres triés, chacun réencodé selon la RFC 3986. */
    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v))];
        }
        usort($pairs, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return implode('&', array_map(static fn ($p) => $p[0] . '=' . $p[1], $pairs));
    }

    /** @param array<string, mixed> $parts */
    private static function hostHeader(array $parts): string
    {
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? null;
        $default = ($parts['scheme'] ?? 'https') === 'https' ? 443 : 80;
        return $port !== null && $port !== $default ? "$host:$port" : $host;
    }
}
