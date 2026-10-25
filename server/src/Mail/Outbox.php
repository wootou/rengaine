<?php
// File des mails (table mail_queue). Une requête web met le mail en file ; il part juste après la
// réponse (Kernel::afterResponse), et rengaine-jobs.timer reprend chaque minute ceux qui ont échoué.
//   - Un envoi « prend » ses lignes (claimed_at) : deux processus n'envoient jamais le même mail.
//   - Nouvel essai après 1, 5, 15, 60 puis 240 minutes ; abandon au 6e échec ou sur un refus définitif.
//   - Le texte est effacé dès que le mail est parti ou abandonné (lien de réinitialisation).

declare(strict_types=1);

namespace Musique\Mail;

use Musique\Config;
use Musique\Db\Database;
use Musique\Log\Logger;

final class Outbox
{
    private const RETRY_MINUTES = [1, 5, 15, 60, 240];

    /** Un mail a été mis en file pendant cette requête : à envoyer après la réponse. */
    public static bool $queuedThisRequest = false;

    public function __construct(private readonly Database $db)
    {
    }

    /** Le serveur peut-il envoyer des mails ? (serveur SMTP et expéditeur configurés) */
    public static function enabled(Config $config): bool
    {
        return $config->has('smtp', 'hote') && $config->has('smtp', 'expediteur');
    }

    public function queue(string $kind, string $to, string $subject, string $body, ?int $userId = null): int
    {
        $this->db->run(
            'INSERT INTO mail_queue (kind, to_address, subject, body, user_id) VALUES (?, ?, ?, ?, ?)',
            [$kind, mb_substr($to, 0, 254), mb_substr($subject, 0, 255), $body, $userId]
        );
        self::$queuedThisRequest = true;
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Envoie les mails en attente (au plus $limit).
     * @return array{sent: int, failed: int, retry: int}
     */
    public function flush(Config $config, Logger $logger, int $limit = 50, ?Smtp $smtp = null): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'retry' => 0];
        $smtp ??= Smtp::fromConfig($config);
        if ($smtp === null || !self::enabled($config)) {
            return $out;
        }
        $from = $config->string('smtp', 'expediteur');
        $fromName = (string) ($this->db->value("SELECT JSON_UNQUOTE(value) FROM server_settings WHERE name = 'server_name'") ?? 'Rengaine');

        // Envoi interrompu (processus tué) : repris au bout de 10 minutes
        $this->db->run("UPDATE mail_queue SET status = 'queued', claimed_at = NULL WHERE status = 'sending' AND claimed_at < CURRENT_TIMESTAMP(3) - INTERVAL 10 MINUTE");
        $ids = $this->db->transaction(function () use ($limit): array {
            $ids = array_map('intval', array_column($this->db->all(
                "SELECT id FROM mail_queue WHERE status = 'queued' AND next_attempt_at <= CURRENT_TIMESTAMP(3) ORDER BY id LIMIT $limit FOR UPDATE SKIP LOCKED"
            ), 'id'));
            if ($ids !== []) {
                $this->db->run("UPDATE mail_queue SET status = 'sending', claimed_at = CURRENT_TIMESTAMP(3) WHERE id IN (" . implode(',', $ids) . ')');
            }
            return $ids;
        });

        foreach ($ids as $id) {
            $m = $this->db->one('SELECT id, kind, to_address, subject, body, attempts FROM mail_queue WHERE id = ?', [$id]);
            if ($m === null) {
                continue;
            }
            try {
                $message = new Message($from, $fromName, (string) $m['to_address'], (string) $m['subject'], (string) $m['body']);
                $smtp->send($from, (string) $m['to_address'], $message->render());
                $this->db->run("UPDATE mail_queue SET status = 'sent', sent_at = CURRENT_TIMESTAMP(3), body = NULL, claimed_at = NULL, attempts = attempts + 1, last_error = NULL WHERE id = ?", [$id]);
                $logger->info('Mail envoyé', ['mail' => $id, 'kind' => $m['kind']]);
                $out['sent']++;
            } catch (\Throwable $e) {
                $attempts = (int) $m['attempts'] + 1;
                $permanent = ($e instanceof MailException && $e->permanent()) || $e instanceof \InvalidArgumentException;
                $error = mb_substr($e->getMessage(), 0, 500);
                if ($permanent || $attempts > count(self::RETRY_MINUTES)) {
                    $this->db->run("UPDATE mail_queue SET status = 'failed', body = NULL, claimed_at = NULL, attempts = ?, last_error = ? WHERE id = ?", [$attempts, $error, $id]);
                    $logger->error('Mail abandonné', ['mail' => $id, 'kind' => $m['kind'], 'attempts' => $attempts, 'error' => $error]);
                    $out['failed']++;
                } else {
                    $wait = self::RETRY_MINUTES[$attempts - 1];
                    $this->db->run(
                        "UPDATE mail_queue SET status = 'queued', claimed_at = NULL, attempts = ?, last_error = ?, next_attempt_at = CURRENT_TIMESTAMP(3) + INTERVAL $wait MINUTE WHERE id = ?",
                        [$attempts, $error, $id]
                    );
                    $logger->warning('Mail pas envoyé, nouvel essai prévu', ['mail' => $id, 'kind' => $m['kind'], 'inMinutes' => $wait, 'error' => $error]);
                    $out['retry']++;
                }
            }
        }
        return $out;
    }

    /** @return array<string, int> nombre de mails par statut */
    public function counts(): array
    {
        $out = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM mail_queue GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }
}
