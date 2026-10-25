<?php
// Client SMTP minimal (pas de Composer, décision du 2026-10-19) : EHLO, STARTTLS ou TLS direct,
// AUTH PLAIN ou LOGIN, MAIL / RCPT / DATA. Le certificat du serveur est vérifié. Un mot de passe
// n'est jamais envoyé sur une connexion en clair, sauf vers la machine elle-même (MailCatcher).
// Les messages d'erreur reprennent la réponse du serveur, jamais l'identifiant ni le mot de passe.

declare(strict_types=1);

namespace Musique\Mail;

use Musique\Config;

final class Smtp
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption, // « aucun », « starttls » ou « tls »
        private readonly string $user = '',
        private readonly string $password = '',
        private readonly int $timeout = 15,
        private readonly string $helo = 'localhost',
    ) {
        if (!in_array($encryption, ['aucun', 'starttls', 'tls'], true)) {
            throw new \InvalidArgumentException("[smtp] chiffrement : « aucun », « starttls » ou « tls » attendu");
        }
    }

    /** Client tiré de la section [smtp] ; null si aucun serveur n'est configuré. */
    public static function fromConfig(Config $config): ?self
    {
        if (!$config->has('smtp', 'hote')) {
            return null;
        }
        $from = $config->has('smtp', 'expediteur') ? $config->string('smtp', 'expediteur') : '';
        return new self(
            $config->string('smtp', 'hote'),
            $config->int('smtp', 'port', 587),
            $config->string('smtp', 'chiffrement', 'starttls'),
            $config->has('smtp', 'utilisateur') ? $config->string('smtp', 'utilisateur') : '',
            $config->has('smtp', 'mot_de_passe') ? $config->string('smtp', 'mot_de_passe') : '',
            15,
            str_contains($from, '@') ? substr($from, strrpos($from, '@') + 1) : (gethostname() ?: 'localhost'),
        );
    }

    /** Envoie un message rendu par Message::render(). */
    public function send(string $from, string $to, string $data): void
    {
        $this->open();
        try {
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);
            // Un point en début de ligne est doublé (RFC 5321 § 4.5.2)
            $data = preg_replace('/^\./m', '..', rtrim($data, "\r\n")) . "\r\n.";
            $this->command($data, [250]);
            $this->command('QUIT', [221], false);
        } finally {
            $this->close();
        }
    }

    private function open(): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host, 'SNI_enabled' => true,
        ]]);
        $scheme = $this->encryption === 'tls' ? 'tls' : 'tcp';
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client("$scheme://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($sock === false) {
            throw new MailException("Connexion à {$this->host}:{$this->port} impossible : $errstr");
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $this->expect([220]);
        $ext = $this->ehlo();
        if ($this->encryption === 'starttls') {
            if (!isset($ext['STARTTLS'])) {
                throw new MailException('Le serveur SMTP ne propose pas STARTTLS');
            }
            $this->command('STARTTLS', [220]);
            if (@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                throw new MailException('Échec de la négociation TLS (certificat refusé ?)');
            }
            $ext = $this->ehlo();
        }
        if ($this->user !== '') {
            $local = in_array($this->host, ['127.0.0.1', '::1', 'localhost'], true);
            if ($this->encryption === 'aucun' && !$local) {
                throw new MailException('Refus d’envoyer le mot de passe SMTP sur une connexion en clair : réglez [smtp] chiffrement');
            }
            $this->auth($ext['AUTH'] ?? '');
        }
    }

    /** @return array<string, string> extensions annoncées (« AUTH » => « PLAIN LOGIN »…) */
    private function ehlo(): array
    {
        $lines = $this->command('EHLO ' . $this->helo, [250]);
        $ext = [];
        foreach (array_slice($lines, 1) as $l) {
            $parts = explode(' ', strtoupper(trim(substr($l, 4))), 2);
            $ext[$parts[0]] = $parts[1] ?? '';
        }
        return $ext;
    }

    private function auth(string $methods): void
    {
        $methods = explode(' ', $methods);
        if (in_array('PLAIN', $methods, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0{$this->user}\0{$this->password}"), [235], true, 'AUTH PLAIN');
        } elseif (in_array('LOGIN', $methods, true)) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->user), [334], true, '(identifiant)');
            $this->command(base64_encode($this->password), [235], true, '(mot de passe)');
        } else {
            throw new MailException('Le serveur SMTP ne propose ni AUTH PLAIN ni AUTH LOGIN');
        }
    }

    /**
     * Envoie une commande et vérifie le code de réponse.
     * @param list<int> $codes
     * @param string|null $shown ce qui apparaît dans un message d'erreur à la place de la commande (secrets)
     * @return list<string> lignes de la réponse
     */
    private function command(string $line, array $codes, bool $strict = true, ?string $shown = null): array
    {
        if ($this->sock === null || @fwrite($this->sock, $line . "\r\n") === false) {
            throw new MailException('Connexion SMTP coupée');
        }
        $shown ??= strlen($line) > 60 ? substr($line, 0, 4) : $line;
        return $this->expect($codes, $strict, $shown);
    }

    /** @param list<int> $codes @return list<string> */
    private function expect(array $codes, bool $strict = true, string $after = 'connexion'): array
    {
        $lines = [];
        while (true) {
            $l = $this->sock === null ? false : fgets($this->sock, 2048);
            if ($l === false) {
                if (!$strict) {
                    return $lines;
                }
                throw new MailException("Pas de réponse du serveur SMTP après « $after »");
            }
            $lines[] = rtrim($l, "\r\n");
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($lines[0], 0, 3);
        if ($strict && !in_array($code, $codes, true)) {
            $reply = mb_substr(implode(' ', $lines), 0, 200);
            throw new MailException("Réponse SMTP inattendue après « $after » : $reply", $code);
        }
        return $lines;
    }

    private function close(): void
    {
        if ($this->sock !== null) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }
}
