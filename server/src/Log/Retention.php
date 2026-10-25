<?php
// Conservation des journaux (JRN-05) : une purge par jour, lancée par `jobs:run` (minuteur
// rengaine-jobs) ou à la main (`rengaine journaux:purger`). Elle porte sur la base ; les fichiers de
// /var/log/rengaine/ sont tournés par logrotate.
//
// Réglages, section [journaux] de rengaine.ini :
//   conservation_ip_jours     (90)  au-delà, les IP sont effacées (audit, appareils, demandes de compte) ;
//   conservation_jours        (365) au-delà, on efface l'audit, les tâches finies, les mails envoyés,
//                                   les jetons révoqués ou expirés ;
//   historique_ecoute_jours   (0)   historique d'écoute ; 0 = garder tout (donnée utile, pas un journal).

declare(strict_types=1);

namespace Musique\Log;

use Musique\Config;
use Musique\Db\Database;
use Musique\Jobs\JobLog;

final class Retention
{
    public const MIN_DAYS = 7;
    /** Une purge par jour : on relance si la dernière date de plus de 20 h. */
    private const EVERY_HOURS = 20;
    private const BATCH = 5000;

    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
        public readonly int $days = 365,
        public readonly int $ipDays = 90,
        public readonly int $historyDays = 0,
    ) {
    }

    public static function fromConfig(Config $config, Database $db, Logger $logger): self
    {
        return new self(
            $db, $logger,
            $config->int('journaux', 'conservation_jours', 365),
            $config->int('journaux', 'conservation_ip_jours', 90),
            $config->int('journaux', 'historique_ecoute_jours', 0),
        );
    }

    /** Réglages incohérents : message d'erreur, sinon null. */
    public function problem(): ?string
    {
        if ($this->days < self::MIN_DAYS || $this->ipDays < self::MIN_DAYS) {
            return 'conservation_jours et conservation_ip_jours : ' . self::MIN_DAYS . ' jours au moins';
        }
        if ($this->ipDays > $this->days) {
            return 'conservation_ip_jours ne peut pas dépasser conservation_jours';
        }
        if ($this->historyDays !== 0 && $this->historyDays < self::MIN_DAYS) {
            return 'historique_ecoute_jours : 0 (tout garder) ou ' . self::MIN_DAYS . ' jours au moins';
        }
        return null;
    }

    /** Vrai si aucune purge n'a tourné depuis 20 h. */
    public function due(): bool
    {
        return $this->db->value(
            "SELECT 1 FROM jobs WHERE type = 'purge' AND created_at > CURRENT_TIMESTAMP(3) - INTERVAL " . self::EVERY_HOURS . ' HOUR LIMIT 1'
        ) === null;
    }

    /**
     * Les règles : nom => [table, condition, SET (effacement d'IP) ou null (suppression des lignes)].
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public function rules(): array
    {
        $ip = "CURRENT_TIMESTAMP(3) - INTERVAL {$this->ipDays} DAY";
        $old = "CURRENT_TIMESTAMP(3) - INTERVAL {$this->days} DAY";
        // Le dernier résumé d'erreurs envoyé sert de point de départ au suivant (Notices) : on le garde.
        $lastDigest = (int) ($this->db->value("SELECT MAX(id) FROM mail_queue WHERE kind = 'job_errors'") ?? 0);
        $rules = [
            'ip_audit' => ['audit_log', "ip IS NOT NULL AND created_at < $ip", 'ip = NULL'],
            'ip_appareils' => ['access_tokens', "last_ip IS NOT NULL AND COALESCE(last_used_at, created_at) < $ip", 'last_ip = NULL'],
            'ip_demandes' => ['users', "(request_ip IS NOT NULL OR request_network IS NOT NULL) AND created_at < $ip", 'request_ip = NULL, request_network = NULL'],
            'audit' => ['audit_log', "created_at < $old", null],
            'taches' => ['jobs', "status IN ('done', 'failed', 'cancelled') AND finished_at < $old", null],
            'mails' => ['mail_queue', "status IN ('sent', 'failed') AND COALESCE(sent_at, created_at) < $old AND id <> $lastDigest", null],
            'jetons' => ['access_tokens', "(revoked_at < $old OR expires_at < $old)", null],
            'codes_qr' => ['pairing_codes', 'expires_at < CURRENT_TIMESTAMP(3) - INTERVAL 1 DAY', null],
            'liens_mot_de_passe' => ['password_resets', 'expires_at < CURRENT_TIMESTAMP(3) - INTERVAL 1 DAY', null],
            // Échecs de connexion par réseau : utiles 15 min, gardés un jour pour le journal de sécurité
            'echecs_connexion' => ['login_failures', 'updated_at < CURRENT_TIMESTAMP(3) - INTERVAL 1 DAY', null],
        ];
        if ($this->historyDays > 0) {
            $rules['ecoutes'] = ['play_history', "started_at < CURRENT_TIMESTAMP(3) - INTERVAL {$this->historyDays} DAY", null];
        }
        return $rules;
    }

    /**
     * Compte (simulation) ou applique. Renvoie le nombre de lignes par règle.
     * @return array<string, int>
     */
    public function run(bool $dryRun = false, ?int $requestedBy = null): array
    {
        if ($dryRun) {
            if (($p = $this->problem()) !== null) {
                throw new \RuntimeException("Réglage [journaux] : $p");
            }
            $out = [];
            foreach ($this->rules() as $name => [$table, $where]) {
                $out[$name] = (int) $this->db->value("SELECT COUNT(*) FROM $table WHERE $where");
            }
            return $out;
        }

        $job = new JobLog($this->db, $this->logger, 'purge',
            ['conservation_jours' => $this->days, 'conservation_ip_jours' => $this->ipDays, 'historique_ecoute_jours' => $this->historyDays], $requestedBy);
        try {
            if (($p = $this->problem()) !== null) {
                throw new \RuntimeException("Réglage [journaux] : $p");
            }
            $out = [];
            foreach ($this->rules() as $name => [$table, $where, $set]) {
                $n = 0;
                do {
                    $sql = $set === null ? "DELETE FROM $table WHERE $where LIMIT " . self::BATCH : "UPDATE $table SET $set WHERE $where LIMIT " . self::BATCH;
                    $k = $this->db->run($sql)->rowCount();
                    $n += $k;
                } while ($k === self::BATCH);
                $out[$name] = $n;
                if ($n > 0) {
                    $job->count($name, $n);
                }
            }
            $job->finish();
            return $out;
        } catch (\Throwable $e) {
            $job->fail($e);
            throw $e;
        }
    }
}
