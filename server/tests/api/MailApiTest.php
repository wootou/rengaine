<?php
// Mails (CPT-06) : alerte de demande de compte, compte activé, mot de passe oublié puis changé,
// résumé des erreurs des tâches, file d'envoi (nouvel essai, abandon), message bien formé.
// Les mails arrivent dans un faux serveur SMTP (tests/smtp-capture.php), jamais dans MailCatcher.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Auth\Passwords;
use Musique\Log\Logger;
use Musique\Mail\Message;
use Musique\Mail\Notices;
use Musique\Mail\Outbox;
use Musique\Mail\Smtp;

require_once __DIR__ . '/ApiCase.php';

final class MailApiTest extends ApiCase
{
    /** Sujet décodé d'un mail reçu. */
    private static function subject(array $mail): string
    {
        $head = substr($mail['data'], 0, (int) strpos($mail['data'], "\r\n\r\n"));
        $head = preg_replace("/\r\n[ \t]+/", ' ', $head) ?? $head; // en-têtes repliés
        preg_match('/^Subject: (.*)$/m', $head, $m);
        return mb_decode_mimeheader(str_replace('?= =?', '?==?', trim($m[1] ?? '')));
    }

    private function logger(): Logger
    {
        return new Logger(self::$env['base'] . '/journaux/test.log', 'debug');
    }

    public function testDemandeDeCompteEtApprobation(): void
    {
        $this->clearMails();
        $email = 'nouveau-' . bin2hex(random_bytes(3)) . '@test.fr';
        $r = $this->call('POST', '/api/v2/auth/register', ['email' => $email, 'displayName' => 'Zoé Nouvelle', 'password' => self::PASSWORD, 'message' => 'Je suis la cousine']);
        $this->assertContract($r, 'register', 202);
        $mails = $this->mails(1);
        $this->assertSame(1, count($mails), 'un seul admin actif');
        $this->assertSame(['admin@test.fr'], $mails[0]['to']);
        $this->assertSame('musique@musique.test', $mails[0]['from']);
        $this->assertSame('Demande de compte : Zoé Nouvelle', self::subject($mails[0]));
        $body = self::mailBody($mails[0]);
        $this->assertTrue(str_contains($body, '« Je suis la cousine »') && str_contains($body, 'https://musique.test/admin'), 'message et lien');

        // Une adresse déjà connue : même réponse, aucun mail
        $this->clearMails();
        $this->assertContract($this->call('POST', '/api/v2/auth/register', ['email' => 'user@test.fr', 'displayName' => 'X', 'password' => self::PASSWORD]), 'register', 202);
        usleep(300_000);
        $this->assertSame(0, count($this->mails()));

        $id = (int) $this->db->value('SELECT id FROM users WHERE email = ?', [$email]);
        $this->assertContract($this->as($this->token('admin'), 'PATCH', "/api/v2/admin/users/$id", ['status' => 'active']), 'adminUpdateUser', 200);
        $mails = $this->mails(1);
        $this->assertSame([[$email], 'Votre compte sur Rengaine est activé'], [$mails[0]['to'], self::subject($mails[0])]);
        $this->db->run('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function testMotDePasseOubliePuisChange(): void
    {
        $this->db->run("INSERT INTO users (email, display_name, password_hash, role, status) VALUES ('oubli@test.fr', 'Oubli', ?, 'user', 'active')", [Passwords::hash(self::PASSWORD)]);
        $id = (int) $this->db->pdo()->lastInsertId();
        try {
            $login = $this->call('POST', '/api/v2/auth/login', ['email' => 'oubli@test.fr', 'password' => self::PASSWORD, 'client' => 'app', 'deviceName' => 'Essai']);
            $oldToken = (string) $login->data()['token'];
            $this->assertTrue($this->call('GET', '/api/v2/server')->data()['features']['passwordReset'], 'le serveur annonce la fonction');

            $this->clearMails();
            $r = $this->call('POST', '/api/v2/auth/password/forgot', ['email' => 'Oubli@test.fr']);
            $this->assertContract($r, 'forgotPassword', 202);
            $unknown = $this->call('POST', '/api/v2/auth/password/forgot', ['email' => 'personne@test.fr']);
            $this->assertSame([202, $r->data()['message']], [$unknown->status, $unknown->data()['message']], 'même réponse pour une adresse inconnue');
            $this->call('POST', '/api/v2/auth/password/forgot', ['email' => 'attente@test.fr']); // compte en attente : rien
            $mails = $this->mails(1);
            usleep(300_000);
            $mails = $this->mails();
            $this->assertSame(1, count($mails), 'un seul lien envoyé');
            $this->assertSame(['oubli@test.fr'], $mails[0]['to']);
            $this->assertTrue(preg_match('#https://musique\.test/mot-de-passe\#jeton=([A-Za-z0-9_-]{43})#', self::mailBody($mails[0]), $m) === 1, 'lien dans le mail');
            $token = $m[1];
            $this->assertNull($this->db->value('SELECT body FROM mail_queue WHERE kind = ? ORDER BY id DESC LIMIT 1', ['password_reset']), 'texte effacé une fois envoyé');
            $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND token_hash = ?', [$id, hash('sha256', $token, true)]), 'seule l’empreinte est en base');

            $this->assertError($this->call('POST', '/api/v2/auth/password/reset', ['token' => $token, 'newPassword' => 'court']), 400, 'bad_request', 'resetPassword');
            $this->assertError($this->call('POST', '/api/v2/auth/password/reset', ['token' => 'faux', 'newPassword' => 'un-nouveau-mot-de-passe']), 400, 'invalid_token', 'resetPassword');
            $this->db->run("INSERT INTO login_failures (user_id, network, locked_until) VALUES (?, '127.0.0.1', CURRENT_TIMESTAMP(3) + INTERVAL 10 MINUTE)", [$id]);
            $this->clearMails();
            $this->assertContract($this->call('POST', '/api/v2/auth/password/reset', ['token' => $token, 'newPassword' => 'un-nouveau-mot-de-passe']), 'resetPassword', 204);
            $this->assertError($this->call('POST', '/api/v2/auth/password/reset', ['token' => $token, 'newPassword' => 'encore-un-autre-mdp']), 400, 'invalid_token', 'resetPassword');
            $this->assertSame(401, $this->as($oldToken, 'GET', '/api/v2/me')->status, 'anciens jetons révoqués');
            $this->assertSame(200, $this->call('POST', '/api/v2/auth/login', ['email' => 'oubli@test.fr', 'password' => 'un-nouveau-mot-de-passe', 'client' => 'web'])->status, 'verrou levé, nouveau mot de passe');
            $mails = $this->mails(1);
            $this->assertSame('Mot de passe changé sur Rengaine', self::subject($mails[0]));
            $this->assertTrue(str_contains(self::mailBody($mails[0]), 'mot de passe oublié'), 'confirmation');

            // Au plus 3 liens par heure et par compte
            $this->clearMails();
            for ($i = 0; $i < 3; $i++) {
                $this->call('POST', '/api/v2/auth/password/forgot', ['email' => 'oubli@test.fr']);
            }
            usleep(500_000);
            $this->assertSame(2, count($this->mails(2)), '3 liens par heure (un déjà envoyé)');
        } finally {
            $this->db->run('DELETE FROM users WHERE id = ?', [$id]);
        }
    }

    public function testResumeDesErreursDesTaches(): void
    {
        $db = $this->db;
        $db->run("DELETE FROM mail_queue WHERE kind = 'job_errors'");
        $db->run("INSERT INTO jobs (type, status) VALUES ('index', 'done')");
        $job = (int) $db->pdo()->lastInsertId();
        $db->run("INSERT INTO job_errors (job_id, storage_key, code, message) VALUES (?, 'Artiste/Album/03 - Abîmé.mp3', 'probe_failed', 'ffprobe : fichier tronqué')", [$job]);
        try {
            $notices = new Notices($db, $this->testConfig(), 'https://musique.test');
            $this->assertSame(1, $notices->jobErrorsDigest());
            $this->assertSame(0, $notices->jobErrorsDigest(), 'au plus un résumé par heure');
            $this->clearMails();
            (new Outbox($db))->flush($this->testConfig(), $this->logger());
            $mails = $this->mails(1);
            $this->assertSame('Rengaine : une erreur dans les tâches de fond', self::subject($mails[0]));
            $body = self::mailBody($mails[0]);
            $this->assertTrue(str_contains($body, '03 - Abîmé.mp3') && str_contains($body, 'https://musique.test/admin/taches'), 'fichier et lien');
        } finally {
            $db->run('DELETE FROM jobs WHERE id = ?', [$job]);
            $db->run("DELETE FROM mail_queue WHERE kind = 'job_errors'");
        }
    }

    public function testFileDEnvoiNouvelEssaiEtAbandon(): void
    {
        $db = $this->db;
        $outbox = new Outbox($db);
        $config = $this->testConfig();
        $db->run("UPDATE mail_queue SET status = 'sent', body = NULL WHERE status = 'queued'"); // file vide pour ce test

        // Serveur injoignable : le mail reste en file, réessayé plus tard
        $id = $outbox->queue('test', 'quelquun@test.fr', 'Essai', 'Texte');
        $closed = new Smtp('127.0.0.1', 1, 'aucun', '', '', 2);
        $this->assertSame(['sent' => 0, 'failed' => 0, 'retry' => 1], $outbox->flush($config, $this->logger(), 10, $closed));
        $row = $db->one('SELECT status, attempts, body, next_attempt_at > CURRENT_TIMESTAMP(3) AS later FROM mail_queue WHERE id = ?', [$id]);
        $this->assertSame(['queued', 1, 'Texte', 1], [$row['status'], (int) $row['attempts'], $row['body'], (int) $row['later']]);

        // Adresse refusée (550) : abandon aussitôt, texte effacé
        $db->run('UPDATE mail_queue SET next_attempt_at = CURRENT_TIMESTAMP(3), to_address = ? WHERE id = ?', ['refuse@test.fr', $id]);
        $dir = self::$env['base'] . '/mails-refus';
        @mkdir($dir);
        $port = 20000 + random_int(0, 20000);
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../smtp-capture.php', (string) $port, $dir, '--refuse-rcpt', 'refuse@test.fr', '--auth', 'moi:secret'], [], $pipes);
        try {
            usleep(300_000);
            $smtp = new Smtp('127.0.0.1', $port, 'aucun', 'moi', 'secret', 2);
            $this->assertSame(['sent' => 0, 'failed' => 1, 'retry' => 0], $outbox->flush($config, $this->logger(), 10, $smtp));
            $row = $db->one('SELECT status, body, last_error FROM mail_queue WHERE id = ?', [$id]);
            $this->assertSame(['failed', null], [$row['status'], $row['body']]);
            $this->assertTrue(str_contains((string) $row['last_error'], '550') && !str_contains((string) $row['last_error'], 'secret'), 'réponse du serveur, sans le mot de passe');

            // Authentification PLAIN, point en début de ligne, sujet accentué, tentative d'injection d'en-tête
            $outbox->queue('test', 'ok@test.fr', "Été\r\nBcc: espion@test.fr", ".ligne qui commence par un point\nfin");
            $this->assertSame(1, $outbox->flush($config, $this->logger(), 10, $smtp)['sent']);
            $got = json_decode((string) file_get_contents((string) (glob("$dir/*.json") ?: [''])[0]), true);
            $this->assertSame(['moi', ['ok@test.fr']], [$got['auth'], $got['to']]);
            $this->assertSame('Été Bcc: espion@test.fr', self::subject($got), 'sujet sur une seule ligne');
            $this->assertTrue(!preg_match('/^Bcc:/mi', $got['data']), 'aucun en-tête injecté');
            $this->assertSame(".ligne qui commence par un point\r\nfin", rtrim(self::mailBody($got)), 'point en début de ligne intact');
            $smtp = new Smtp('127.0.0.1', $port, 'aucun', 'moi', 'faux', 2);
            $outbox->queue('test', 'ok@test.fr', 'Essai', 'Texte');
            $this->assertSame(1, $outbox->flush($config, $this->logger(), 10, $smtp)['retry'], 'identifiants refusés (535) : on réessaie');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            $db->run("DELETE FROM mail_queue WHERE kind = 'test'");
        }
        $this->assertTrue(!Message::validAddress("a@b.fr>\r\nRCPT TO:<x@y.fr"), 'adresse avec retour à la ligne refusée');
    }
}
