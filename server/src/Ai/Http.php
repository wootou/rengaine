<?php
// Appel HTTP JSON vers un fournisseur d'IA, avec reprise sur 429, 5xx et coupure réseau
// (au plus 3 essais, en suivant Retry-After jusqu'à une minute).

declare(strict_types=1);

namespace Musique\Ai;

final class Http
{
    /** @var ?\Closure(int): void attente entre deux essais (remplacée dans les tests) */
    public static ?\Closure $sleep = null;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function post(string $url, array $headers, array $body, int $timeout = 600): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $lines = ['Content-Type: application/json'];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        $last = '';
        for ($try = 1; $try <= 3; $try++) {
            $retryAfter = 0;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                    if (preg_match('/^retry-after:\s*(\d+)/i', $line, $m)) {
                        $retryAfter = (int) $m[1];
                    }
                    return strlen($line);
                },
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            if ($raw === false || $status === 0) {
                $last = "connexion impossible ($err)";
            } elseif ($status === 429 || $status >= 500) {
                $last = "HTTP $status : " . self::message((string) $raw);
            } else {
                $data = json_decode((string) $raw, true);
                if ($status >= 400) {
                    throw new AiException("HTTP $status : " . self::message((string) $raw));
                }
                if (!is_array($data)) {
                    throw new AiException('Réponse illisible du fournisseur');
                }
                return $data;
            }
            if ($try < 3) {
                $wait = min(60, max($retryAfter, 2 ** $try));
                (self::$sleep ?? static fn (int $s) => sleep($s))($wait);
            }
        }
        throw new AiException($last);
    }

    /** Message d'erreur du fournisseur (corps JSON `error.message`), raccourci. */
    private static function message(string $raw): string
    {
        $data = json_decode($raw, true);
        $msg = is_array($data) ? ($data['error']['message'] ?? $data['error'] ?? $data['message'] ?? null) : null;
        return mb_substr(is_string($msg) ? $msg : trim(strip_tags($raw)), 0, 300);
    }
}
