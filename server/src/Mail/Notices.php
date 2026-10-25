<?php
// Les mails du site (CPT-06) : textes et destinataires. Tous passent par la file (Outbox).
// Rien n'est mis en file si le serveur n'envoie pas de mails ([smtp] vide) : un lien de
// réinitialisation ne reste pas en base pour rien.
//
// Destinataires des alertes de l'admin : [smtp] admin s'il est rempli, sinon chaque admin actif.

declare(strict_types=1);

namespace Musique\Mail;

use Musique\Config;
use Musique\Db\Database;
use Musique\Lang;

final class Notices
{
    /** Délai minimal entre deux résumés des erreurs des tâches de fond. */
    public const DIGEST_MINUTES = 60;
    /** Durée de validité d'un lien « mot de passe oublié ». */
    public const RESET_MINUTES = 60;

    private readonly Outbox $outbox;

    public function __construct(private readonly Database $db, private readonly Config $config, private readonly string $siteUrl)
    {
        $this->outbox = new Outbox($db);
        Lang::useDatabase($db); // mails dans la langue du serveur (WEB-13)
    }

    public function enabled(): bool
    {
        return Outbox::enabled($this->config);
    }

    /** @return list<string> */
    public function adminRecipients(): array
    {
        if ($this->config->has('smtp', 'admin')) {
            return array_values(array_filter(array_map('trim', explode(',', $this->config->string('smtp', 'admin'))), [Message::class, 'validAddress']));
        }
        return array_values(array_filter(array_column(
            $this->db->all("SELECT email FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id"), 'email'
        ), [Message::class, 'validAddress']));
    }

    private function serverName(): string
    {
        return (string) ($this->db->value("SELECT JSON_UNQUOTE(value) FROM server_settings WHERE name = 'server_name'") ?? 'Rengaine');
    }

    private function signature(): string
    {
        return "\n\n-- \n" . $this->serverName() . ($this->siteUrl !== '' ? "\n" . $this->siteUrl : '')
            . "\n" . Lang::tr('Ce mail est envoyé automatiquement, inutile d’y répondre.') . "\n";
    }

    /** @return array{email: string, display_name: string}|null */
    private function user(int $id): ?array
    {
        $u = $this->db->one('SELECT email, display_name FROM users WHERE id = ?', [$id]);
        return $u === null || !Message::validAddress((string) $u['email']) ? null : $u;
    }

    private function link(string $path): string
    {
        return $this->siteUrl === '' ? '' : $this->siteUrl . $path;
    }

    // ---------------------------------------------------------------- comptes

    /** Nouvelle demande de compte : chaque destinataire de l'admin reçoit l'alerte. */
    public function accountRequested(int $userId): int
    {
        $u = $this->db->one('SELECT email, display_name, request_message FROM users WHERE id = ?', [$userId]);
        if (!$this->enabled() || $u === null) {
            return 0;
        }
        $pending = (int) $this->db->value("SELECT COUNT(*) FROM users WHERE status = 'pending'");
        $body = Lang::tr("Bonjour,\n\n{nom} ({email}) demande un compte sur {serveur}.", ['nom' => $u['display_name'], 'email' => $u['email'], 'serveur' => $this->serverName()]) . "\n";
        if (($u['request_message'] ?? '') !== '') {
            $body .= "\n" . Lang::tr("Son message :\n« {message} »", ['message' => $u['request_message']]) . "\n";
        }
        $body .= "\n" . Lang::tr('Pour approuver ou refuser la demande : {lien}', ['lien' => $this->link('/admin') ?: Lang::tr('Admin > Comptes')]) . "\n";
        if ($pending > 1) {
            $body .= "\n" . Lang::tr('{n} demandes attendent une réponse en tout.', ['n' => $pending]) . "\n";
        }
        $n = 0;
        foreach ($this->adminRecipients() as $to) {
            $this->outbox->queue('register_admin', $to, Lang::tr('Demande de compte : {nom}', ['nom' => $u['display_name']]), $body . $this->signature(), $userId);
            $n++;
        }
        return $n;
    }

    public function accountApproved(int $userId): void
    {
        $u = $this->user($userId);
        if (!$this->enabled() || $u === null) {
            return;
        }
        $vars = ['nom' => $u['display_name'], 'serveur' => $this->serverName(), 'site' => $this->siteUrl, 'email' => $u['email']];
        $body = ($this->siteUrl !== ''
                ? Lang::tr("Bonjour {nom},\n\nVotre compte sur {serveur} est activé. Vous pouvez vous connecter sur {site} avec l’adresse {email} et le mot de passe choisi lors de la demande.", $vars)
                : Lang::tr("Bonjour {nom},\n\nVotre compte sur {serveur} est activé. Vous pouvez vous connecter avec l’adresse {email} et le mot de passe choisi lors de la demande.", $vars))
            . "\n\n" . Lang::tr('Mot de passe oublié ? Le lien « Mot de passe oublié » de la page de connexion vous en fera choisir un autre.');
        $this->outbox->queue('account_approved', (string) $u['email'], Lang::tr('Votre compte sur {serveur} est activé', $vars), $body . $this->signature(), $userId);
    }

    public function accountRefused(int $userId): void
    {
        $u = $this->user($userId);
        if (!$this->enabled() || $u === null) {
            return;
        }
        $vars = ['nom' => $u['display_name'], 'serveur' => $this->serverName()];
        $body = Lang::tr("Bonjour {nom},\n\nVotre demande de compte sur {serveur} n’a pas été acceptée.", $vars);
        $this->outbox->queue('account_refused', (string) $u['email'], Lang::tr('Votre demande de compte sur {serveur}', $vars), $body . $this->signature(), $userId);
    }

    // ---------------------------------------------------------------- mot de passe

    /** Lien de réinitialisation ; $token est le secret en clair (seule son empreinte est en base). */
    public function passwordReset(int $userId, string $token): void
    {
        $u = $this->user($userId);
        if (!$this->enabled() || $u === null) {
            return;
        }
        // Jeton dans le fragment (#) : il n'est jamais envoyé au serveur, donc absent des journaux d'accès
        $link = $this->link('/mot-de-passe#jeton=' . rawurlencode($token));
        $vars = ['nom' => $u['display_name'], 'serveur' => $this->serverName(), 'lien' => $link];
        $body = Lang::tr("Bonjour {nom},\n\nQuelqu’un (vous, sans doute) a demandé à changer le mot de passe de votre compte sur {serveur}.\n\nPour choisir un nouveau mot de passe, ouvrez ce lien dans l’heure :\n{lien}\n\nLe lien ne sert qu’une fois. Si vous n’avez rien demandé, ignorez ce mail : votre mot de passe ne change pas.", $vars);
        $this->outbox->queue('password_reset', (string) $u['email'], Lang::tr('Nouveau mot de passe pour {serveur}', $vars), $body . $this->signature(), $userId);
    }

    public function passwordChanged(int $userId, bool $byReset): void
    {
        $u = $this->user($userId);
        if (!$this->enabled() || $u === null) {
            return;
        }
        $vars = ['nom' => $u['display_name'], 'serveur' => $this->serverName()];
        $body = $byReset
            ? Lang::tr("Bonjour {nom},\n\nLe mot de passe de votre compte sur {serveur} vient d’être changé par le lien « mot de passe oublié ». Les autres appareils connectés ont été déconnectés.\n\nSi ce n’est pas vous, prévenez l’administrateur du site au plus vite.", $vars)
            : Lang::tr("Bonjour {nom},\n\nLe mot de passe de votre compte sur {serveur} vient d’être changé depuis vos réglages. Les autres appareils connectés ont été déconnectés.\n\nSi ce n’est pas vous, prévenez l’administrateur du site au plus vite.", $vars);
        $this->outbox->queue('password_changed', (string) $u['email'], Lang::tr('Mot de passe changé sur {serveur}', $vars), $body . $this->signature(), $userId);
    }

    // ---------------------------------------------------------------- tâches de fond

    /**
     * Résumé des nouvelles erreurs des tâches de fond (fichiers illisibles…), au plus une fois par
     * heure. Les erreurs prises en compte sont celles apparues depuis le résumé précédent.
     * @return int mails mis en file
     */
    public function jobErrorsDigest(): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $last = $this->db->one("SELECT created_at, CURRENT_TIMESTAMP(3) - INTERVAL " . self::DIGEST_MINUTES . " MINUTE < created_at AS recent
                                  FROM mail_queue WHERE kind = 'job_errors' ORDER BY id DESC LIMIT 1");
        if ($last !== null && (int) $last['recent'] === 1) {
            return 0;
        }
        $now = (string) $this->db->value('SELECT CURRENT_TIMESTAMP(3)');
        $where = 'e.resolved_at IS NULL AND e.created_at <= ?' . ($last === null ? '' : ' AND e.created_at > ?');
        $args = $last === null ? [$now] : [$now, $last['created_at']];
        $total = (int) $this->db->value("SELECT COUNT(*) FROM job_errors e WHERE $where", $args);
        if ($total === 0) {
            return 0;
        }
        $rows = $this->db->all(
            "SELECT e.storage_key, e.code, e.message, j.type FROM job_errors e JOIN jobs j ON j.id = e.job_id WHERE $where ORDER BY e.id LIMIT 30", $args
        );
        $lines = array_map(static fn ($r) => '- ' . ($r['storage_key'] ?? Lang::tr('(sans fichier)')) . "\n  {$r['code']} : " . mb_substr(Lang::message((string) $r['message']), 0, 300), $rows);
        $more = $total - count($rows);
        $body = ($total === 1
                ? Lang::tr("Bonjour,\n\nUne nouvelle erreur est apparue pendant les tâches de fond (scan, analyse) :")
                : Lang::tr("Bonjour,\n\n{n} nouvelles erreurs sont apparues pendant les tâches de fond (scan, analyse) :", ['n' => $total]))
            . "\n\n" . implode("\n", $lines)
            . ($more > 0 ? "\n" . ($more === 1 ? Lang::tr('… et une autre.') : Lang::tr('… et {n} autres.', ['n' => $more])) . "\n" : "\n")
            . "\n" . Lang::tr('Le détail est dans {lien}.', ['lien' => $this->link('/admin/taches') ?: Lang::tr('Admin > Tâches')]);
        $n = 0;
        foreach ($this->adminRecipients() as $to) {
            $this->db->run(
                'INSERT INTO mail_queue (kind, to_address, subject, body, created_at) VALUES (?, ?, ?, ?, ?)',
                ['job_errors', $to, $total === 1
                    ? Lang::tr('{serveur} : une erreur dans les tâches de fond', ['serveur' => $this->serverName()])
                    : Lang::tr('{serveur} : {n} erreurs dans les tâches de fond', ['serveur' => $this->serverName(), 'n' => $total]), $body . $this->signature(), $now]
            );
            $n++;
        }
        return $n;
    }

    /** Mail d'essai (rengaine mail:test). */
    public function test(string $to): int
    {
        $vars = ['serveur' => $this->serverName()];
        return $this->outbox->queue('test', $to, Lang::tr('Essai d’envoi depuis {serveur}', $vars),
            Lang::tr("Bonjour,\n\nCe mail d’essai montre que {serveur} sait envoyer des mails.", $vars) . $this->signature());
    }
}
