<?php
// Serveur et authentification : GET /server, connexion, déconnexion, demande de compte, appairage.

declare(strict_types=1);

namespace Musique\Api\Controllers;

use Musique\Account\ServerSettings;
use Musique\Api\Context;
use Musique\Api\Format;
use Musique\Api\Kernel;
use Musique\Audio\Transcoder;
use Musique\Auth\Passwords;
use Musique\Auth\Tokens;
use Musique\Http\ApiError;
use Musique\Http\Input;
use Musique\Http\Response;
use Musique\Mail\Notices;
use Musique\Mail\Outbox;

final class AuthController
{
    /** Échecs avant verrouillage, et durée du verrouillage. */
    public const MAX_FAILURES = 5;
    public const LOCK_MINUTES = 15;

    public function __construct(private readonly Context $ctx)
    {
    }

    /** Texte dans la langue du serveur (WEB-13). */
    private function text(string $fr): string
    {
        return \Musique\Lang::tr($fr);
    }

    public function getServer(array $params): Response
    {
        $settings = new ServerSettings($this->ctx->db);
        $dj = \Musique\Audio\Rhythm::fromConfig($this->ctx->config) !== null; // Essentia installé : BPM et tonalité mesurés
        $info = [
            'name' => (string) $settings->get('server_name'),
            'apiVersion' => Kernel::VERSION,
            'language' => (string) $settings->get('language'),
            'registrationOpen' => (bool) $settings->get('registration_open'),
            'features' => [
                'transcoding' => Transcoder::enabled($this->ctx->config),
                'transcodingFormats' => Transcoder::enabled($this->ctx->config) ? ['opus', 'mp3', 'flac'] : [],
                'continuousPlay' => true,
                'analysis' => ['loudness' => true, 'silence' => true, 'bpm' => $dj, 'key' => $dj, 'energy' => $dj, 'waveform' => true, 'beatGrid' => $dj,
                    'fingerprint' => \Musique\Audio\Fingerprint::fromConfig($this->ctx->config) !== null],
                'favorites' => true,
                'history' => true,
                'pairing' => true,
                'passwordReset' => Outbox::enabled($this->ctx->config),
                'remoteControl' => \Musique\Realtime\Hub::forServer($this->ctx->config, $this->ctx->db) !== null,
            ],
            'limits' => ['maxPageSize' => 500, 'maxQueueLength' => MeController::MAX_QUEUE],
        ];
        if ($this->ctx->principal !== null) {
            $lib = $this->ctx->db->one(
                "SELECT (SELECT COUNT(*) FROM albums WHERE missing_since IS NULL) AS albums,
                        (SELECT COUNT(*) FROM artists) AS artists,
                        (SELECT COUNT(*) FROM tracks WHERE missing_since IS NULL) AS tracks,
                        (SELECT COALESCE(SUM(duration_ms), 0) FROM tracks WHERE missing_since IS NULL) AS duration,
                        (SELECT MAX(finished_at) FROM jobs WHERE type = 'index' AND status = 'done') AS updated"
            );
            $info['library'] = ['albums' => (int) $lib['albums'], 'artists' => (int) $lib['artists'], 'tracks' => (int) $lib['tracks'],
                'durationMs' => (int) $lib['duration']];
            if ($lib['updated'] !== null) {
                $info['library']['updatedAt'] = Format::date($lib['updated']);
            }
        }
        return Response::json($info);
    }

    public function login(array $params): Response
    {
        $ctx = $this->ctx;
        $ip = $ctx->request->ip;
        // Limites par réseau (IPv4, ou /64 en IPv6) : changer d'adresse dans son /64 ne les contourne pas
        $network = Format::network($ip);
        $ctx->limiter->hit('login', $network, $ctx->config->int('limites', 'connexions_par_10min', 20), 600, 'Trop de tentatives de connexion, réessayez dans quelques minutes');
        $in = new Input($ctx->request->json());
        $email = mb_strtolower(trim((string) $in->string('email', 1, 254)));
        $password = (string) $in->string('password', 0, 200);
        $client = (string) $in->enum('client', ['web', 'app']);
        $deviceName = $client === 'app' ? $in->text('deviceName', 1, 100) : $in->text('deviceName', 1, 100, false);

        $user = $ctx->db->one('SELECT id, email, display_name, role, status, password_hash FROM users WHERE email = ?', [$email]);
        // Verrou après plusieurs échecs : seulement pour ce réseau (revue sécurité du 2026-10-24). Depuis
        // ailleurs, le titulaire se connecte normalement ; et la réponse est celle d'un mauvais mot de passe,
        // qui ne dit pas si le compte existe.
        $locked = $user !== null && $ctx->db->value(
            'SELECT 1 FROM login_failures WHERE user_id = ? AND network = ? AND locked_until > CURRENT_TIMESTAMP(3)', [$user['id'], $network]
        ) !== null;
        $valid = Passwords::verify($password, $user['password_hash'] ?? null); // même durée dans tous les cas
        if ($locked) {
            $ctx->security->event('login_locked', $ip, ['user' => $user['id'], 'rid' => $ctx->requestId]);
            throw ApiError::unauthorized('Adresse e-mail ou mot de passe incorrect', 'invalid_credentials');
        }
        if (!$valid || $user['status'] === 'refused') {
            if ($user !== null) {
                $ctx->db->run(
                    'INSERT INTO login_failures (user_id, network, failures) VALUES (?, ?, 1)
                     ON DUPLICATE KEY UPDATE failures = IF(locked_until IS NOT NULL OR updated_at < CURRENT_TIMESTAMP(3) - INTERVAL ' . self::LOCK_MINUTES . ' MINUTE, 1, failures + 1),
                                             locked_until = NULL',
                    [$user['id'], $network]
                );
                if ((int) $ctx->db->value('SELECT failures FROM login_failures WHERE user_id = ? AND network = ?', [$user['id'], $network]) >= self::MAX_FAILURES) {
                    $ctx->db->run('UPDATE login_failures SET failures = 0, locked_until = CURRENT_TIMESTAMP(3) + INTERVAL ' . self::LOCK_MINUTES . ' MINUTE WHERE user_id = ? AND network = ?',
                        [$user['id'], $network]);
                    $ctx->security->event('account_locked', $ip, ['user' => $user['id'], 'rid' => $ctx->requestId]);
                }
            }
            $ctx->security->event('login_failed', $ip, ['user' => $user['id'] ?? null, 'rid' => $ctx->requestId]);
            throw ApiError::unauthorized('Adresse e-mail ou mot de passe incorrect', 'invalid_credentials');
        }
        if ($user['status'] === 'pending') {
            throw ApiError::forbidden('Votre demande de compte attend l’approbation de l’administrateur', 'account_pending');
        }
        if ($user['status'] === 'suspended') {
            throw ApiError::forbidden('Ce compte est suspendu', 'account_suspended');
        }

        $uid = (int) $user['id'];
        $ctx->db->run('UPDATE users SET last_login_at = CURRENT_TIMESTAMP(3) WHERE id = ?', [$uid]);
        $ctx->db->run('DELETE FROM login_failures WHERE user_id = ? AND network = ?', [$uid, $network]);
        if (Passwords::needsRehash((string) $user['password_hash'])) {
            $ctx->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [Passwords::hash($password), $uid]);
        }
        $ctx->limiter->reset('login', $network, 600);
        $ua = $ctx->request->header('user-agent');
        $token = $ctx->tokens()->create($uid, $client, $deviceName ?? self::browserName($ua), $ip, $ua);
        $ctx->audit('auth.login', 'token', $token['id'], null, ['client' => $client], $uid);
        $userOut = ['id' => $uid, 'email' => $user['email'], 'displayName' => $user['display_name'], 'role' => $user['role']];

        if ($client === 'web') {
            return Response::json(['user' => $userOut, 'csrfToken' => Tokens::csrfFor($token['secret']), 'tokenId' => $token['id']])
                ->cookie(Tokens::sessionCookie($token['secret']));
        }
        return Response::json(['user' => $userOut, 'token' => $token['secret'], 'tokenId' => $token['id']]);
    }

    /** « Firefox sur Linux » : nom lisible d'une session web, pour la liste des appareils. */
    public static function browserName(?string $ua): string
    {
        $ua ??= '';
        $browser = match (true) {
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Navigateur',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };
        return $os === null ? $browser : "$browser sur $os";
    }

    public function logout(array $params): Response
    {
        $p = $this->ctx->user();
        $this->ctx->tokens()->revoke($p->tokenId, $p->userId);
        $this->ctx->audit('auth.logout', 'token', $p->tokenId);
        $r = Response::noContent();
        return $p->isWeb() ? $r->cookie(Tokens::clearCookie()) : $r;
    }

    public function register(array $params): Response
    {
        $ctx = $this->ctx;
        $ip = $ctx->request->ip;
        $settings = new ServerSettings($ctx->db);
        if (!(bool) $settings->get('registration_open')) {
            throw ApiError::forbidden('Les inscriptions sont fermées', 'registration_closed');
        }
        $ctx->limiter->hit('register', Format::network($ip), $ctx->config->int('limites', 'demandes_compte_par_heure', 10), 3600);
        $in = new Input($ctx->request->json());
        $email = $in->email('email');
        $name = (string) $in->text('displayName', 1, 100);
        $password = (string) $in->string('password', 10, 200);
        $message = $in->text('message', 0, 1000, false);

        // Au plus N demandes en attente par IPv4 publique ou par IPv6 /64 (décision de Rémy)
        $network = Format::network($ip);
        $limit = max(1, (int) $settings->get('registration_pending_per_network'));
        $pending = (int) $ctx->db->value("SELECT COUNT(*) FROM users WHERE request_network = ? AND status = 'pending'", [$network]);
        if ($pending >= $limit) {
            $ctx->security->event('register_pending_limit', $ip, ['rid' => $ctx->requestId]);
            throw ApiError::tooManyRequests(3600, 'Trop de demandes de compte en attente depuis votre connexion : attendez que l’administrateur les traite', 'pending_limit');
        }

        $accepted = Response::json(['message' => $this->text('Demande enregistrée. L’administrateur doit l’approuver avant votre première connexion.')], 202);
        // Empreinte calculée avant de savoir si l'adresse existe : le temps de réponse ne le révèle pas
        $hash = Passwords::hash($password);
        if ($ctx->db->value('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            // Même réponse que pour une nouvelle adresse : on ne révèle pas quels comptes existent
            $ctx->security->event('register_existing', $ip, ['rid' => $ctx->requestId]);
            return $accepted;
        }
        $ctx->db->run(
            "INSERT INTO users (email, display_name, password_hash, role, status, request_message, request_ip, request_network)
             VALUES (?, ?, ?, 'user', 'pending', ?, ?, ?)",
            [$email, $name, $hash, $message, $ip, $network]
        );
        $id = (int) $ctx->db->pdo()->lastInsertId();
        $ctx->audit('user.register', 'user', $id, null, ['displayName' => $name]);
        $ctx->logger->info('Demande de compte', ['user' => $id]);
        $ctx->notices()->accountRequested($id);
        return $accepted;
    }

    /**
     * Mot de passe oublié : un lien valable une heure, à usage unique. Même réponse que l'adresse
     * existe ou non ; rien n'est envoyé à un compte en attente, suspendu ou agent.
     */
    public function forgotPassword(array $params): Response
    {
        $ctx = $this->ctx;
        $ip = $ctx->request->ip;
        $ctx->limiter->hit('forgot', Format::network($ip), $ctx->config->int('limites', 'oublis_mdp_par_heure', 10), 3600, 'Trop de demandes, réessayez plus tard');
        $in = new Input($ctx->request->json());
        $email = mb_strtolower(trim((string) $in->string('email', 1, 254)));
        $accepted = Response::json(['message' => $this->text('Si un compte existe avec cette adresse, un lien vient de lui être envoyé. Il est valable une heure.')], 202);

        $notices = $ctx->notices();
        $user = $ctx->db->one("SELECT id FROM users WHERE email = ? AND status = 'active' AND password_hash IS NOT NULL", [$email]);
        if ($user === null || !$notices->enabled()) {
            $ctx->security->event('password_forgot_unknown', $ip, ['rid' => $ctx->requestId]);
            return $accepted;
        }
        $uid = (int) $user['id'];
        // Au plus 3 liens par heure et par compte : pas de mails en rafale vers une boîte
        $recent = (int) $ctx->db->value('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > CURRENT_TIMESTAMP(3) - INTERVAL 1 HOUR', [$uid]);
        if ($recent >= 3) {
            $ctx->security->event('password_forgot_limit', $ip, ['user' => $uid, 'rid' => $ctx->requestId]);
            return $accepted;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ctx->db->run(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, CURRENT_TIMESTAMP(3) + INTERVAL ' . Notices::RESET_MINUTES . ' MINUTE)',
            [$uid, hash('sha256', $token, true)]
        );
        $notices->passwordReset($uid, $token);
        $ctx->audit('user.password_forgot', 'user', $uid, null, null, $uid);
        return $accepted;
    }

    /** Nouveau mot de passe à partir du lien reçu : toutes les sessions et tous les jetons sont révoqués. */
    public function resetPassword(array $params): Response
    {
        $ctx = $this->ctx;
        $ip = $ctx->request->ip;
        $ctx->limiter->hit('reset', Format::network($ip), $ctx->config->int('limites', 'changements_mdp_par_10min', 10), 600, 'Trop d’essais, réessayez dans quelques minutes');
        $in = new Input($ctx->request->json());
        $token = (string) $in->string('token', 1, 200);
        $new = (string) $in->string('newPassword', 10, 200);
        $uid = $ctx->db->transaction(function () use ($ctx, $token, $new): ?int {
            $row = $ctx->db->one(
                "SELECT r.id, r.user_id FROM password_resets r JOIN users u ON u.id = r.user_id
                  WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > CURRENT_TIMESTAMP(3) AND u.status = 'active' AND u.password_hash IS NOT NULL
                  FOR UPDATE",
                [hash('sha256', $token, true)]
            );
            if ($row === null) {
                return null;
            }
            $uid = (int) $row['user_id'];
            $ctx->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [Passwords::hash($new), $uid]);
            $ctx->db->run('DELETE FROM login_failures WHERE user_id = ?', [$uid]); // nouveau mot de passe : plus de verrou
            // Ce lien et tous les autres liens encore valables du compte ne servent plus
            $ctx->db->run('UPDATE password_resets SET used_at = CURRENT_TIMESTAMP(3) WHERE user_id = ? AND used_at IS NULL', [$uid]);
            $revoked = $ctx->tokens()->revokeAll($uid, null, null);
            $ctx->audit('user.password_reset', 'user', $uid, null, ['revokedTokens' => $revoked], $uid);
            return $uid;
        });
        if ($uid === null) {
            $ctx->security->event('password_reset_invalid', $ip, ['rid' => $ctx->requestId]);
            throw new ApiError(400, 'invalid_token', 'Lien inconnu, expiré ou déjà utilisé : demandez-en un nouveau');
        }
        $ctx->notices()->passwordChanged($uid, true);
        return Response::noContent();
    }

    public function pairDevice(array $params): Response
    {
        $ctx = $this->ctx;
        $ip = $ctx->request->ip;
        $ctx->limiter->hit('pair', Format::network($ip), $ctx->config->int('limites', 'appairages_par_10min', 10), 600, 'Trop d’essais de code, réessayez dans quelques minutes');
        $in = new Input($ctx->request->json());
        $code = MeController::normalizeCode((string) $in->string('code', 1, 20));
        $deviceName = (string) $in->text('deviceName', 1, 100);

        $row = $ctx->db->one(
            'SELECT p.id, p.user_id FROM pairing_codes p JOIN users u ON u.id = p.user_id
              WHERE p.code_hash = ? AND p.used_at IS NULL AND p.expires_at > CURRENT_TIMESTAMP(3) AND u.status = \'active\'',
            [hash('sha256', $code, true)]
        );
        if ($row === null) {
            $ctx->security->event('pair_failed', $ip, ['rid' => $ctx->requestId]);
            throw ApiError::unauthorized('Code d’appairage invalide ou expiré', 'invalid_code');
        }
        $uid = (int) $row['user_id'];
        $token = $ctx->db->transaction(function () use ($ctx, $row, $uid, $deviceName, $ip): ?array {
            // Usage unique, même si deux appareils envoient le code en même temps
            if ($ctx->db->run('UPDATE pairing_codes SET used_at = CURRENT_TIMESTAMP(3) WHERE id = ? AND used_at IS NULL', [$row['id']])->rowCount() !== 1) {
                return null;
            }
            $t = $ctx->tokens()->create($uid, 'app', $deviceName, $ip, $ctx->request->header('user-agent'));
            $ctx->db->run('UPDATE pairing_codes SET token_id = ? WHERE id = ?', [$t['id'], $row['id']]);
            return $t;
        }) ?? throw ApiError::unauthorized('Code d’appairage déjà utilisé', 'invalid_code');

        $ctx->audit('token.pair', 'token', $token['id'], null, ['deviceName' => $deviceName], $uid);
        $u = $ctx->db->one('SELECT id, email, display_name, role FROM users WHERE id = ?', [$uid]);
        return Response::json(['token' => $token['secret'], 'tokenId' => $token['id'],
            'user' => ['id' => $uid, 'email' => $u['email'], 'displayName' => $u['display_name'], 'role' => $u['role']]]);
    }
}
