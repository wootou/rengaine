<?php
// Un mail en texte brut UTF-8, prêt pour la commande DATA de SMTP (RFC 5322, RFC 2047).
// Les valeurs d'en-tête viennent parfois des utilisateurs (nom affiché dans le sujet) : les
// retours à la ligne y sont retirés, pour qu'aucun en-tête ne puisse être injecté.

declare(strict_types=1);

namespace Musique\Mail;

final class Message
{
    public function __construct(
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
    ) {
    }

    /** Adresse e-mail plausible et sans caractère de contrôle (rien d'injectable dans MAIL FROM ou To). */
    public static function validAddress(string $a): bool
    {
        return filter_var($a, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\s<>"]/', $a);
    }

    /** Message complet, lignes terminées par CRLF. */
    public function render(?\DateTimeImmutable $date = null): string
    {
        foreach ([$this->fromAddress, $this->to] as $a) {
            if (!self::validAddress($a)) {
                throw new \InvalidArgumentException('Adresse e-mail invalide : ' . preg_replace('/[^\x20-\x7e]/', '?', $a));
            }
        }
        $date ??= new \DateTimeImmutable();
        $domain = substr($this->fromAddress, strrpos($this->fromAddress, '@') + 1);
        $headers = [
            'Date: ' . $date->format(DATE_RFC2822),
            // Nom affiché : encodé dès qu'il contient autre chose que lettres, chiffres et espaces (virgule, guillemets…)
            'From: ' . self::encodeWords(self::headerValue($this->fromName), (bool) preg_match('/[^A-Za-z0-9 ]/', $this->fromName)) . ' <' . $this->fromAddress . '>',
            'To: <' . $this->to . '>',
            'Subject: ' . self::encodeWords(self::headerValue($this->subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            'Auto-Submitted: auto-generated',
        ];
        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $this->body) ?? $this->body;
        return implode("\r\n", $headers) . "\r\n\r\n" . quoted_printable_encode($body) . "\r\n";
    }

    /** Valeur d'en-tête sur une ligne, sans caractère de contrôle. */
    private static function headerValue(string $v): string
    {
        return trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $v) ?? '');
    }

    /** Mots encodés RFC 2047 (« =?UTF-8?B?…?= ») si le texte n'est pas en ASCII, coupés en morceaux de 75 caractères au plus. */
    public static function encodeWords(string $text, bool $force = false): string
    {
        if (!$force && !preg_match('/[^\x20-\x7e]/', $text) && strlen($text) <= 900) {
            return $text;
        }
        $words = [];
        $chunk = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            // 45 octets de texte → 60 caractères en base64, + 12 pour « =?UTF-8?B?…?= »
            if (strlen($chunk . $char) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode("\r\n ", $words);
    }
}
