<?php
// Noyau de l'API v2 (API-01, API-02, API-07) : pour chaque requête,
//   identifiant de requête → route → débit → authentification (jeton ou session + anti-CSRF)
//   → rôle → contrôleur → réponse JSON ou flux, en-têtes de sécurité.
// Toute erreur sort au format `Error` du contrat, avec le requestId. Un avertissement PHP ne
// devient jamais un 500 (voir Log\ErrorHandler).

declare(strict_types=1);

namespace Musique\Api;

use Musique\Auth\Tokens;
use Musique\Config;
use Musique\Db\Database;
use Musique\Http\ApiError;
use Musique\Http\RateLimiter;
use Musique\Http\Request;
use Musique\Http\Response;
use Musique\Http\Router;
use Musique\Log\Logger;
use Musique\Log\RequestId;
use Musique\Log\SecurityLog;
use Musique\Mail\Outbox;
use Musique\Playback\Shares;
use Musique\Storage\StorageException;

final class Kernel
{
    public const BASE = '/api/v2';
    public const VERSION = '2.28.0';

    private Router $router;
    private ?Database $db = null;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly SecurityLog $security,
    ) {
        $this->router = Routes::build();
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): Response
    {
        $rid = RequestId::generate();
        \Musique\Lang::forget();
        $logger = $this->logger->withRequest($rid);
        $start = microtime(true);
        $userId = null;
        try {
            $response = $this->dispatch($request, $rid, $logger, $userId);
        } catch (ApiError $e) {
            $response = $this->error($e, $rid);
            if ($e->status >= 500 && $e->status !== 501) {
                $logger->error('Erreur API', ['status' => $e->status, 'code' => $e->errorCode, 'path' => $request->path]);
            }
        } catch (StorageException $e) {
            $logger->error('Stockage indisponible', ['reason' => $e->reason, 'message' => $e->getMessage(), 'path' => $request->path]);
            $response = $e->reason === StorageException::NOT_FOUND
                ? $this->error(ApiError::notFound('Fichier absent du stockage'), $rid)
                : $this->error(new ApiError(503, 'storage_unavailable', 'Le stockage ne répond pas, réessayez dans un instant', [], ['Retry-After' => '30']), $rid);
        } catch (\Throwable $e) {
            $logger->error('Erreur interne', ['exception' => $e, 'path' => $request->path, 'method' => $request->method]);
            $response = $this->error(new ApiError(500, 'internal', 'Erreur interne du serveur'), $rid);
        }

        $response->headers['X-Request-Id'] = $rid;
        $response->headers += [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($response->status >= 500 || $ms > 2000) {
            $logger->withRequest($rid, $userId)->warning('Requête lente ou en erreur', [
                'method' => $request->method, 'path' => $request->path, 'status' => $response->status, 'ms' => $ms,
            ]);
        }
        return $response;
    }

    /**
     * Après l'envoi de la réponse : les mails mis en file par la requête partent tout de suite
     * (le client n'attend pas le serveur SMTP). Un échec est repris par rengaine-jobs.timer.
     */
    public function afterResponse(): void
    {
        if (!Outbox::$queuedThisRequest) {
            return;
        }
        Outbox::$queuedThisRequest = false;
        try {
            (new Outbox($this->db()))->flush($this->config, $this->logger, 10);
        } catch (\Throwable $e) {
            $this->logger->warning('Envoi des mails après la réponse impossible', ['message' => $e->getMessage()]);
        }
    }

    private function db(): Database
    {
        if ($this->db === null) {
            $this->db = Database::fromConfig($this->config);
            \Musique\Lang::useDatabase($this->db); // langue lue au premier texte à traduire (WEB-13)
        }
        return $this->db;
    }

    /** Requête d'image ou de flux audio (compteur de débit à part). */
    public static function isMedia(string $method, string $path): bool
    {
        return ($method === 'GET' || $method === 'HEAD')
            && preg_match('#^/(images/[^/]+|tracks/\d+/stream|backgrounds/\d+/image)$#', $path) === 1;
    }

    private function dispatch(Request $request, string $rid, Logger $logger, ?int &$userId): Response
    {
        $path = $request->path;
        if ($path !== self::BASE && !str_starts_with($path, self::BASE . '/')) {
            throw ApiError::notFound('Adresse inconnue');
        }
        $path = substr($path, strlen(self::BASE)) ?: '/';
        if ($request->method === 'OPTIONS') {
            // Pas de CORS : seuls le site (même origine) et l'appli (hors navigateur) appellent l'API
            return Response::noContent()->header('Allow', 'GET, POST, PUT, PATCH, DELETE');
        }
        $route = $this->router->match($request->method, $path);
        $options = $route['options'];

        $limiter = new RateLimiter(fn () => $logger->warning('APCu indisponible : pas de limitation du débit'));
        // Images (immuables, en cache) et flux audio : un compteur à part, plus large. Une page d'albums
        // charge des dizaines de vignettes d'un coup, et une famille partage la même adresse publique.
        if (self::isMedia($request->method, $path)) {
            $limiter->hit('media', $request->ip, $this->config->int('limites', 'medias_par_minute', 6000), 60);
        } else {
            $limiter->hit('ip', $request->ip, $this->config->int('limites', 'requetes_par_minute', 1200), 60);
        }

        $ctx = new Context($this->config, $this->db(), $logger, $request, $rid, $limiter, $this->security);
        $this->authenticate($ctx, $options);
        if ($ctx->principal !== null) {
            $userId = $ctx->principal->userId;
            $ctx->logger = $logger->withRequest($rid, $userId);
        }

        $handler = $route['handler'];
        if ($handler === null) {
            throw ApiError::notImplemented();
        }
        [$class, $method] = $handler;
        $params = array_map(static fn (string $v) => ctype_digit($v) ? (int) $v : $v, $route['params']);
        return (new $class($ctx))->$method($params);
    }

    /**
     * Invité d'un lien de télécommande : cookie musique_remote, et pour une requête qui modifie,
     * le jeton anti-CSRF dérivé du secret du lien. Renvoie false sans cookie valable.
     */
    private function authenticateGuest(Context $ctx): bool
    {
        $request = $ctx->request;
        $secret = (string) ($request->cookies[Shares::COOKIE] ?? '');
        if ($secret === '') {
            return false;
        }
        $shares = new Shares($ctx->db);
        $share = $shares->verify($secret);
        if ($share === null) {
            $ctx->security->event('remote_token_invalid', $request->ip, ['rid' => $ctx->requestId]);
            $ctx->limiter->hit('badtoken', $request->ip, $this->config->int('limites', 'jetons_invalides_par_10min', 30), 600);
            throw new ApiError(401, 'unauthorized', 'Lien de télécommande expiré ou révoqué', [], ['Set-Cookie' => Shares::clearCookie()]);
        }
        if ($request->isMutating()) {
            $sent = (string) $request->header('x-csrf-token');
            if ($sent === '' || !hash_equals(Shares::csrfFor($secret), $sent)) {
                $ctx->security->event('csrf_invalid', $request->ip, ['share' => $share['id'], 'rid' => $ctx->requestId]);
                throw ApiError::forbidden('En-tête X-CSRF-Token absent ou invalide', 'csrf_invalid');
            }
        }
        $ctx->limiter->hit('share', (string) $share['id'], $this->config->int('limites', 'requetes_par_minute_compte', 2400), 60);
        $shares->touch($share);
        $ctx->share = $share;
        $ctx->shareSecret = $secret;
        return true;
    }

    /** @param array<string, mixed> $options */
    private function authenticate(Context $ctx, array $options): void
    {
        $request = $ctx->request;
        // Routes de l'invité seulement : une éventuelle session du même navigateur ne compte pas
        if (($options['guest'] ?? null) === 'only') {
            if (!$this->authenticateGuest($ctx)) {
                throw ApiError::unauthorized('Lien de télécommande absent ou expiré');
            }
            return;
        }
        $public = (bool) ($options['public'] ?? false);
        $tokens = $ctx->tokens();
        $presented = null;
        $principal = null;

        $auth = $request->header('authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            $presented = 'bearer';
            $principal = $tokens->verify($m[1], 'bearer');
        } elseif (isset($request->cookies[Tokens::COOKIE]) && $request->cookies[Tokens::COOKIE] !== '') {
            $presented = 'cookie';
            $principal = $tokens->verify($request->cookies[Tokens::COOKIE], 'web');
        }

        if ($presented !== null && $principal === null) {
            $ctx->security->event('token_invalid', $request->ip, ['kind' => $presented, 'rid' => $ctx->requestId]);
            // Essais de jetons au hasard : bloqués au-delà de 30 en 10 minutes
            $ctx->limiter->hit('badtoken', $request->ip, $this->config->int('limites', 'jetons_invalides_par_10min', 30), 600);
            if (!$public) {
                $e = ApiError::unauthorized($presented === 'cookie' ? 'Session expirée, reconnectez-vous' : 'Jeton invalide ou révoqué');
                throw $presented === 'cookie'
                    ? new ApiError(401, 'unauthorized', $e->getMessage(), [], ['Set-Cookie' => Tokens::clearCookie()])
                    : $e;
            }
        }
        if ($principal === null) {
            // Routes ouvertes aussi à l'invité (pochettes de la file)
            if (!$public && !(($options['guest'] ?? null) === 'also' && $presented === null && $this->authenticateGuest($ctx))) {
                throw ApiError::unauthorized();
            }
            return;
        }
        $ctx->principal = $principal;

        // Session web : toute requête qui modifie doit porter le jeton anti-CSRF
        if ($principal->isWeb() && $request->isMutating() && !$public) {
            $sent = (string) $request->header('x-csrf-token');
            if ($sent === '' || !hash_equals(Tokens::csrfFor((string) $principal->sessionSecret), $sent)) {
                $ctx->security->event('csrf_invalid', $request->ip, ['user' => $principal->userId, 'rid' => $ctx->requestId]);
                throw ApiError::forbidden('En-tête X-CSRF-Token absent ou invalide', 'csrf_invalid');
            }
        }

        $roles = self::roles($options);
        if ($roles !== null && !in_array($principal->role, $roles, true)) {
            throw ApiError::forbidden('Votre rôle ne permet pas cette action');
        }
        $ctx->limiter->hit('user', (string) $principal->userId, $this->config->int('limites', 'requetes_par_minute_compte', 2400), 60);
        $tokens->touch($principal, $request->ip, $request->header('user-agent'));
    }

    /**
     * Rôles admis sur une route, null = tous. Sans rôle indiqué : admin et user ; l'agent n'a que les
     * routes qui le citent (IA-01). Une route publique reste ouverte à tous, jeton présenté ou non.
     * @param array<string, mixed> $options
     * @return list<string>|null
     */
    public static function roles(array $options): ?array
    {
        return $options['roles'] ?? (($options['public'] ?? false) ? null : ['admin', 'user']);
    }

    private function error(ApiError $e, string $rid): Response
    {
        // Message dans la langue du serveur (WEB-13) ; le code ne change jamais
        try {
            $this->db();
        } catch (\Throwable) {
            // base injoignable : message en français
        }
        $body = ['status' => $e->status, 'code' => $e->errorCode, 'message' => \Musique\Lang::message($e->getMessage()), 'requestId' => $rid];
        if ($e->details !== []) {
            $body['details'] = $e->details;
        }
        $r = Response::json(['error' => $body], $e->status);
        foreach ($e->headers as $k => $v) {
            if ($k === 'Set-Cookie') {
                $r->cookie($v);
            } else {
                $r->header($k, $v);
            }
        }
        return $r;
    }
}
