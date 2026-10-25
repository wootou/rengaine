<?php
// Conservation des journaux (JRN-05) sur une base jetable (rengaine_test) : effacement des IP, des
// vieilles lignes, et rien de récent.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Config;
use Musique\Db\Database;
use Musique\Db\Migrator;
use Musique\Log\Logger;
use Musique\Log\Retention;

final class RetentionTest extends TestCase
{
    private Database $db;
    private Logger $logger;
    private string $log;
    private int $user;

    public function setUp(): void
    {
        if (!is_readable(Config::defaultPath()) && getenv('RENGAINE_CONFIG') === false) {
            $this->skip('configuration illisible (lancer en root)');
        }
        $config = Config::load()->with('base', 'nom', getenv('RENGAINE_TEST_DB') ?: 'rengaine_test');
        $this->db = Database::fromConfig($config);
        try {
            $this->db->pdo();
        } catch (\Throwable $e) {
            $this->skip('base de test inaccessible : ' . $e->getMessage());
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $r) {
            $this->db->exec('DROP TABLE `' . $r['t'] . '`');
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        (new Migrator($this->db, \Musique\REPO_DIR . '/db'))->migrate();
        $this->log = sys_get_temp_dir() . '/musique-retention-' . bin2hex(random_bytes(4)) . '.log';
        $this->logger = new Logger($this->log);

        $this->db->run("INSERT INTO users (email, display_name, status) VALUES ('a@exemple.fr', 'A', 'active')");
        $this->user = (int) $this->db->pdo()->lastInsertId();
    }

    public function tearDown(): void
    {
        @unlink($this->log);
    }

    private function ago(int $days): string
    {
        return (string) $this->db->value("SELECT CURRENT_TIMESTAMP(3) - INTERVAL $days DAY");
    }

    private function token(string $prefix, ?string $usedAt, ?string $revokedAt = null): int
    {
        $this->db->run(
            "INSERT INTO access_tokens (user_id, kind, token_hash, token_prefix, name, created_at, last_used_at, last_ip, revoked_at)
             VALUES (?, 'app', ?, ?, 'Téléphone', ?, ?, '192.0.2.7', ?)",
            [$this->user, random_bytes(32), $prefix, $usedAt ?? $this->ago(0), $usedAt, $revokedAt]
        );
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function testEffaceLesIpEtLesVieuxJournaux(): void
    {
        // Audit : récent, vieux de 100 jours (IP effacée), de 400 jours (ligne effacée)
        foreach ([1, 100, 400] as $d) {
            $this->db->run("INSERT INTO audit_log (created_at, actor_user_id, action, ip) VALUES (?, ?, 'auth.login', '192.0.2.1')", [$this->ago($d), $this->user]);
        }
        $recent = $this->token('recent00', $this->ago(2));
        $idle = $this->token('ancien00', $this->ago(120));
        $this->token('revoque0', $this->ago(500), $this->ago(400));
        // Demande de compte ancienne encore marquée d'une IP
        $this->db->run("INSERT INTO users (email, display_name, status, request_ip, request_network, created_at)
                        VALUES ('b@exemple.fr', 'B', 'refused', '192.0.2.9', '192.0.2.9', ?)", [$this->ago(200)]);
        // Tâches : une finie il y a 400 jours (avec une erreur), une récente, une en cours ancienne
        $this->db->run("INSERT INTO jobs (type, status, created_at, finished_at) VALUES ('index', 'done', ?, ?)", [$this->ago(401), $this->ago(400)]);
        $oldJob = (int) $this->db->pdo()->lastInsertId();
        $this->db->run("INSERT INTO job_errors (job_id, code, message) VALUES (?, 'probe_failed', 'x')", [$oldJob]);
        $this->db->run("INSERT INTO jobs (type, status, created_at, finished_at) VALUES ('index', 'done', ?, ?)", [$this->ago(3), $this->ago(3)]);
        $this->db->run("INSERT INTO jobs (type, status, created_at) VALUES ('analyze', 'running', ?)", [$this->ago(400)]);
        // Mails : un vieux mail envoyé, et le dernier résumé d'erreurs (vieux aussi, mais gardé)
        $this->db->run("INSERT INTO mail_queue (kind, to_address, subject, status, created_at, sent_at) VALUES ('test', 'a@exemple.fr', 's', 'sent', ?, ?)", [$this->ago(400), $this->ago(400)]);
        $this->db->run("INSERT INTO mail_queue (kind, to_address, subject, status, created_at, sent_at) VALUES ('job_errors', 'a@exemple.fr', 's', 'sent', ?, ?)", [$this->ago(399), $this->ago(399)]);
        // Codes expirés
        $this->db->run('INSERT INTO pairing_codes (user_id, code_hash, expires_at) VALUES (?, ?, ?)', [$this->user, random_bytes(32), $this->ago(2)]);
        $this->db->run('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, CURRENT_TIMESTAMP(3) + INTERVAL 1 HOUR)', [$this->user, random_bytes(32)]);

        $r = new Retention($this->db, $this->logger, 365, 90);
        $this->assertNull($r->problem());
        $this->assertTrue($r->due());
        $sim = $r->run(true);
        $expected = ['ip_audit' => 2, 'ip_appareils' => 2, 'ip_demandes' => 1, 'audit' => 1, 'taches' => 1, 'mails' => 1,
            'jetons' => 1, 'codes_qr' => 1, 'liens_mot_de_passe' => 0, 'echecs_connexion' => 0];
        $this->assertSame($expected, $sim, 'simulation');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM jobs WHERE type = 'purge'"), 'la simulation ne laisse pas de trace');

        $done = $r->run();
        $this->assertSame($expected, $done, 'purge');

        // Ce qui reste
        $this->assertSame([[1, true], [100, false]], array_map(
            fn ($row) => [(int) round(((int) $this->db->value('SELECT TIMESTAMPDIFF(HOUR, ?, CURRENT_TIMESTAMP(3))', [$row['created_at']])) / 24), $row['ip'] !== null],
            $this->db->all('SELECT created_at, ip FROM audit_log ORDER BY created_at DESC')
        ), 'audit : la ligne récente garde son IP');
        $this->assertSame([$recent => true, $idle => false], array_map(
            static fn ($ip) => $ip !== null,
            array_column($this->db->all('SELECT id, last_ip FROM access_tokens ORDER BY id'), 'last_ip', 'id')
        ), 'appareils : IP effacée au-delà de 90 jours, jeton révoqué depuis un an supprimé');
        $this->assertNull($this->db->value("SELECT request_ip FROM users WHERE email = 'b@exemple.fr'"));
        $jobs = array_map(static fn ($j) => "{$j['type']}:{$j['status']}", $this->db->all('SELECT type, status FROM jobs'));
        sort($jobs); // ORDER BY sur un ENUM suit l'ordre de déclaration, pas l'alphabet
        $this->assertSame(['analyze:running', 'index:done', 'purge:done'], $jobs, 'tâches : la vieille tâche finie part, pas celle en cours');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM job_errors'));
        $this->assertSame(['job_errors'], array_column($this->db->all('SELECT kind FROM mail_queue'), 'kind'));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM pairing_codes'));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM password_resets'));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM users WHERE email = 'a@exemple.fr'"), 'les comptes restent');

        // Une fois par jour
        $this->assertFalse($r->due());
        $this->assertSame(array_fill_keys(array_keys($expected), 0), $r->run(true), 'rien de plus le lendemain');
    }

    public function testHistoriqueDEcouteGardeParDefaut(): void
    {
        $this->assertSame(false, array_key_exists('ecoutes', (new Retention($this->db, $this->logger))->rules()));
        $this->assertSame(true, array_key_exists('ecoutes', (new Retention($this->db, $this->logger, 365, 90, 180))->rules()));
    }

    public function testReglagesIncoherentsRefuses(): void
    {
        $this->assertSame(null, (new Retention($this->db, $this->logger, 365, 90))->problem());
        $this->assertTrue((new Retention($this->db, $this->logger, 30, 90))->problem() !== null, 'IP plus longtemps que le reste');
        $this->assertTrue((new Retention($this->db, $this->logger, 365, 1))->problem() !== null, 'moins de 7 jours');
        $this->assertTrue((new Retention($this->db, $this->logger, 365, 90, 3))->problem() !== null, 'historique : 3 jours');

        // Une purge refusée laisse une tâche en échec : elle ne repasse pas chaque minute.
        $bad = new Retention($this->db, $this->logger, 30, 90);
        $this->assertThrows(\RuntimeException::class, fn () => $bad->run());
        $this->assertSame('failed', $this->db->value("SELECT status FROM jobs WHERE type = 'purge'"));
        $this->assertFalse($bad->due());
    }
}
