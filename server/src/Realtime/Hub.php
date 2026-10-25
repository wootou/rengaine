<?php
// Hub Mercure (FUT-02) : PHP publie en local, les navigateurs s'abonnent par <site>/.well-known/mercure (flux SSE).
// Jetons au format Mercure 1.x (RFC 9068) : en-tête « typ: at+jwt », iss, aud, exp, droits dans
// authorization_details. Signés en HS256 avec les clés de [mercure] (les mêmes que celles du service).
// Sans section [mercure], fromConfig() renvoie null : la télécommande est annoncée indisponible.
// Un admin peut aussi la désactiver (réglage remote_control_enabled) : forServer() renvoie alors null,
// et le service lui-même s'arrête par le fichier [mercure] fichier_etat (« on » / « off »), que
// surveille l'unité systemd mercure-etat.path (INSTALL § 6 bis).

declare(strict_types=1);

namespace Musique\Realtime;

use Musique\Account\ServerSettings;
use Musique\Config;
use Musique\Db\Database;

final class Hub
{
    private const DETAIL_TYPE = 'https://mercure.rocks/authorization-detail';
    /** Une publication ne doit jamais faire attendre une requête : le hub est local. */
    private const TIMEOUT_MS = 1500;

    public function __construct(
        private readonly string $internalUrl,
        public readonly string $publicUrl,
        private readonly string $issuer,
        private readonly string $publisherKey,
        private readonly string $subscriberKey,
    ) {
    }

    public static function fromConfig(Config $config): ?self
    {
        foreach (['hub_interne', 'url_publique', 'emetteur', 'cle_publication', 'cle_abonnement'] as $k) {
            if (!$config->has('mercure', $k) || $config->string('mercure', $k) === '') {
                return null;
            }
        }
        return new self(
            $config->string('mercure', 'hub_interne'),
            $config->string('mercure', 'url_publique'),
            rtrim($config->string('mercure', 'emetteur'), '/'),
            $config->string('mercure', 'cle_publication'),
            $config->string('mercure', 'cle_abonnement'),
        );
    }


    /** Hub configuré et télécommande autorisée par l'admin. */
    public static function forServer(Config $config, Database $db): ?self
    {
        $hub = self::fromConfig($config);
        return $hub !== null && self::enabled($db) ? $hub : null;
    }

    public static function enabled(Database $db): bool
    {
        return (bool) (new ServerSettings($db))->get('remote_control_enabled');
    }

    /** Le service répond-il ? (n'importe quelle réponse HTTP : sans jeton, le hub répond 401) */
    public function running(): bool
    {
        $ch = curl_init($this->internalUrl);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 1000, CURLOPT_CONNECTTIMEOUT_MS => 1000]);
        curl_exec($ch);
        return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) > 0;
    }

    /**
     * Demande l'arrêt ou le démarrage du service : écrit « on » ou « off » dans [mercure] fichier_etat,
     * que systemd surveille (le serveur web n'a aucun droit sur les services). false si rien n'est configuré.
     */
    public static function requestService(Config $config, bool $on): bool
    {
        if (!$config->has('mercure', 'fichier_etat') || $config->string('mercure', 'fichier_etat') === '') {
            return false;
        }
        return @file_put_contents($config->string('mercure', 'fichier_etat'), $on ? "on\n" : "off\n") !== false;
    }

    /**
     * Jeton d'abonnement limité à ces sujets.
     * @param list<string> $topics
     */
    public function subscriberToken(array $topics, int $expiresAt, string $subject): string
    {
        return $this->jwt($this->subscriberKey, 'subscribe', $topics, $expiresAt, $subject);
    }

    /**
     * Publie un message privé (seuls les abonnés autorisés sur ce sujet le reçoivent).
     * @param array<string, mixed> $data
     * @return bool false si le hub n'a pas pu être joint ou a refusé
     */
    public function publish(string $topic, array $data): bool
    {
        $token = $this->jwt($this->publisherKey, 'publish', [$topic], time() + 60, 'urn:musique:api');
        $ch = curl_init($this->internalUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['topic' => $topic, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'private' => 'on']),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_MS,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return $status === 200;
    }

    /** @param list<string> $topics */
    private function jwt(string $key, string $action, array $topics, int $expiresAt, string $subject): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $json = static fn (array $v): string => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $now = time();
        $head = $b64($json(['alg' => 'HS256', 'typ' => 'at+jwt']));
        $body = $b64($json([
            'iss' => $this->issuer,
            'sub' => $subject,
            'client_id' => $this->issuer,
            'aud' => $this->publicUrl,
            'iat' => $now,
            'exp' => $expiresAt,
            'jti' => 'urn:uuid:' . self::uuid(),
            'authorization_details' => [[
                'type' => self::DETAIL_TYPE,
                'actions' => [$action],
                'topics' => array_map(static fn (string $t) => ['match' => $t], $topics),
            ]],
        ]));
        return "$head.$body." . $b64(hash_hmac('sha256', "$head.$body", $key, true));
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
