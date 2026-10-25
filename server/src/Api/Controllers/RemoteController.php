<?php
// Télécommande partagée (FUT-02).
//   Propriétaire (compte connecté) : liens (créer, lister, révoquer), canal temps réel du lecteur,
//   état du lecteur.
//   Invité (cookie musique_remote, sans compte) : état, recherche, commandes.
// Les commandes ne modifient pas la file en base : quand deux appareils écrivent, celui qui joue
// l'emporte (MeController::putMyQueue, 412 puis réécriture). Le serveur vérifie donc la commande
// et la relaie au lecteur qui joue (hub Mercure), qui l'applique et enregistre la file ; les
// invités sont prévenus (« changed ») et relisent l'état.

declare(strict_types=1);

namespace Musique\Api\Controllers;

use Musique\Api\Context;
use Musique\Api\Format;
use Musique\Catalog\Serializer;
use Musique\Http\ApiError;
use Musique\Http\Input;
use Musique\Http\Response;
use Musique\Playback\PlayerStates;
use Musique\Playback\Shares;
use Musique\Realtime\Hub;

final class RemoteController
{
    /** Durée des jetons d'abonnement : le hub ferme le flux à l'expiration, le client en redemande un. */
    private const CHANNEL_SECONDS = 900;
    private const SEARCH_LIMIT = 20;

    public function __construct(private readonly Context $ctx)
    {
    }

    // ---------------------------------------------------------------- outils

    private function hub(): Hub
    {
        $hub = Hub::fromConfig($this->ctx->config)
            ?? throw new ApiError(503, 'realtime_unavailable', 'Le serveur n’a pas de hub temps réel : télécommande indisponible');
        if (!Hub::enabled($this->ctx->db)) {
            throw new ApiError(503, 'realtime_unavailable', 'La télécommande a été désactivée par l’administrateur du serveur');
        }
        return $hub;
    }

    private function shares(): Shares
    {
        return new Shares($this->ctx->db);
    }

    /** Sujet du hub : une URL sous l'adresse du site (les essais, sous https://musique.test, ne croisent pas le dev). */
    private function topic(string $path): string
    {
        return $this->ctx->publicUrl() . $path;
    }

    private static function playerTopic(int $userId): string
    {
        return "/users/$userId/player";
    }

    private static function shareTopic(int $shareId): string
    {
        return "/shares/$shareId";
    }

    /** @return array<string, mixed> RealtimeChannel */
    private function channel(Hub $hub, string $topic, int $expiresAt, string $subject): array
    {
        $t = $this->topic($topic);
        return [
            'hubUrl' => $hub->publicUrl, 'topics' => [$t],
            'token' => $hub->subscriberToken([$t], $expiresAt, $subject),
            'expiresAt' => Format::date(gmdate('Y-m-d H:i:s', $expiresAt)),
        ];
    }

    /**
     * Prévient les invités des liens valables de ce compte que la file ou le lecteur ont changé.
     * Appelé après l'enregistrement de la file et après un rapport du lecteur. Ne lève jamais.
     */
    public static function notifyGuests(Context $ctx, int $userId): void
    {
        try {
            $ids = (new Shares($ctx->db))->activeIds($userId);
            $hub = $ids === [] ? null : Hub::forServer($ctx->config, $ctx->db);
            foreach ($ids as $id) {
                $hub?->publish($ctx->publicUrl() . self::shareTopic($id), ['type' => 'changed']);
            }
        } catch (\Throwable $e) {
            $ctx->logger->warning('Télécommande : invités non prévenus', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Télécommande désactivée par l'admin : chaque invité connecté voit le message, chaque lecteur
     * arrête de s'abonner. À appeler avant d'arrêter le service. Ne lève jamais.
     */
    public static function announceDisabled(Context $ctx): void
    {
        try {
            $hub = Hub::fromConfig($ctx->config);
            if ($hub === null) {
                return;
            }
            $shares = $ctx->db->all('SELECT id FROM queue_shares WHERE revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()');
            foreach ($shares as $s) {
                $hub->publish($ctx->publicUrl() . self::shareTopic((int) $s['id']), ['type' => 'disabled']);
            }
            foreach ($ctx->db->all('SELECT id FROM users') as $u) {
                $hub->publish($ctx->publicUrl() . self::playerTopic((int) $u['id']), ['type' => 'disabled']);
            }
        } catch (\Throwable $e) {
            $ctx->logger->warning('Télécommande : désactivation non annoncée', ['message' => $e->getMessage()]);
        }
    }

    /** Annonce au lecteur du compte qu'un lien a été créé ou révoqué. */
    private function notifyOwner(Hub $hub, int $userId): void
    {
        if (!$hub->publish($this->topic(self::playerTopic($userId)), ['type' => 'shares'])) {
            $this->ctx->logger->warning('Télécommande : hub injoignable (annonce au lecteur)');
        }
    }

    // ---------------------------------------------------------------- propriétaire

    public function listMyShares(array $params): Response
    {
        return Response::json(['items' => $this->shares()->listActive($this->ctx->user()->userId)]);
    }

    public function createMyShare(array $params): Response
    {
        $p = $this->ctx->user();
        $hub = $this->hub();
        $in = new Input($this->ctx->request->json());
        $label = trim((string) ($in->string('label', 0, 100, false) ?? ''));
        $hours = (int) $in->int('hours', 1, Shares::MAX_HOURS);
        $r = $in->object('rights');
        $rights = ['add' => (bool) $r->bool('add'), 'edit' => (bool) $r->bool('edit'), 'control' => (bool) $r->bool('control')];
        $shares = $this->shares();
        if ($shares->countActive($p->userId) >= Shares::MAX_ACTIVE) {
            throw new ApiError(409, 'conflict', 'Déjà ' . Shares::MAX_ACTIVE . ' liens valables : révoquez-en un d’abord');
        }
        $created = $shares->create($p->userId, $p->tokenId, $label === '' ? 'Invité' : $label, $hours, $rights);
        $this->ctx->audit('share.create', 'queue_share', $created['id'], null, ['hours' => $hours, 'rights' => $rights]);
        $this->notifyOwner($hub, $p->userId);
        $doc = (array) $shares->get($created['id']);
        $doc['token'] = $created['secret'];
        $doc['url'] = $this->ctx->publicUrl() . '/telecommande#' . $created['secret'];
        return Response::json($doc, 201);
    }

    public function revokeMyShare(array $params): Response
    {
        $p = $this->ctx->user();
        $id = (int) $params['id'];
        if (!$this->shares()->revoke($id, $p->userId)) {
            throw ApiError::notFound('Lien introuvable');
        }
        $this->ctx->audit('share.revoke', 'queue_share', $id);
        $hub = Hub::forServer($this->ctx->config, $this->ctx->db);
        if ($hub !== null) {
            $hub->publish($this->topic(self::shareTopic($id)), ['type' => 'revoked']);
            $this->notifyOwner($hub, $p->userId);
        }
        return Response::noContent();
    }

    public function getMyRealtimeChannel(array $params): Response
    {
        $p = $this->ctx->user();
        $doc = $this->channel($this->hub(), self::playerTopic($p->userId), time() + self::CHANNEL_SECONDS, 'urn:musique:token:' . $p->tokenId);
        $doc['sharesActive'] = $this->shares()->countActive($p->userId) > 0;
        return Response::json($doc);
    }

    public function reportMyPlayer(array $params): Response
    {
        $p = $this->ctx->user();
        $in = new Input($this->ctx->request->json());
        $key = (string) $in->string('deviceKey', 8, 64);
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            throw ApiError::invalid('deviceKey', 'deviceKey : lettres, chiffres, - et _ seulement');
        }
        $report = [
            'deviceKey' => $key,
            'trackId' => $in->int('trackId', 1, PHP_INT_MAX, true, true),
            'playing' => (bool) $in->bool('playing'),
            'positionMs' => (int) $in->int('positionMs', 0, 2_000_000_000),
            'queueVersion' => (int) $in->int('queueVersion', 0, PHP_INT_MAX),
            'volume' => $in->int('volume', 0, 100, false),
        ];
        if ((new PlayerStates($this->ctx->db))->report($p->userId, $report)) {
            self::notifyGuests($this->ctx, $p->userId);
        }
        return Response::noContent();
    }

    // ---------------------------------------------------------------- invité

    /** @return array{id: int, userId: int, label: string, ownerName: string, rights: array{add: bool, edit: bool, control: bool}, expiresAt: string, idle: int} */
    private function share(): array
    {
        return $this->ctx->share ?? throw ApiError::unauthorized('Lien de télécommande absent ou expiré');
    }

    /** @param array{id: int, userId: int, label: string, ownerName: string, rights: array<string, bool>, expiresAt: string} $share @return array<string, mixed> RemoteState */
    private function state(array $share, string $secret): array
    {
        $db = $this->ctx->db;
        $q = $db->one('SELECT track_ids, current_index, version FROM play_queues WHERE user_id = ?', [$share['userId']]);
        $ids = $q === null ? [] : array_map('intval', (array) json_decode((string) $q['track_ids'], true));
        $byId = [];
        foreach ((new Serializer($db, $share['userId']))->tracks($ids) as $t) {
            $byId[$t['id']] = $t;
        }
        $player = (new PlayerStates($db))->get($share['userId']);
        unset($player['deviceKey']);
        $expires = (int) strtotime($share['expiresAt'] . ' UTC');
        return [
            'share' => ['label' => $share['label'], 'ownerName' => $share['ownerName'], 'rights' => $share['rights'], 'expiresAt' => Format::date($share['expiresAt'])],
            'player' => $player,
            'queue' => [
                'version' => $q === null ? 0 : (int) $q['version'],
                'currentIndex' => $q === null ? null : Format::int($q['current_index']),
                'tracks' => array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $ids))),
            ],
            'csrfToken' => Shares::csrfFor($secret),
            'channel' => $this->channel($this->hub(), self::shareTopic($share['id']), min($expires, time() + self::CHANNEL_SECONDS), 'urn:musique:share:' . $share['id']),
        ];
    }

    public function openRemoteSession(array $params): Response
    {
        $in = new Input($this->ctx->request->json());
        $secret = (string) $in->string('token', 1, 200);
        $share = $this->shares()->verify($secret);
        if ($share === null) {
            $this->ctx->security->event('remote_token_invalid', $this->ctx->request->ip, ['rid' => $this->ctx->requestId]);
            $this->ctx->limiter->hit('badtoken', $this->ctx->request->ip, $this->ctx->config->int('limites', 'jetons_invalides_par_10min', 30), 600);
            throw new ApiError(401, 'invalid_token', 'Lien de télécommande inconnu, expiré ou révoqué', [], ['Set-Cookie' => Shares::clearCookie()]);
        }
        $this->shares()->touch($share);
        $maxAge = (int) strtotime($share['expiresAt'] . ' UTC') - time();
        return Response::json($this->state($share, $secret))->cookie(Shares::cookie($secret, $maxAge));
    }

    public function getRemote(array $params): Response
    {
        return Response::json($this->state($this->share(), (string) $this->ctx->shareSecret));
    }

    public function searchRemote(array $params): Response
    {
        $share = $this->share();
        if (!$share['rights']['add']) {
            throw ApiError::forbidden('Ce lien ne permet pas d’ajouter des pistes');
        }
        $this->hub(); // télécommande désactivée : 503
        $q = $this->ctx->request->queryString('q') ?? throw ApiError::invalid('q', 'Paramètre « q » obligatoire');
        if (trim($q) === '') {
            throw ApiError::invalid('q', 'Paramètre « q » vide');
        }
        $this->ctx->limiter->hit('remote-search', (string) $share['id'], $this->ctx->config->int('limites', 'recherches_par_minute', 600), 60);
        $db = $this->ctx->db;
        $like = Format::like($q);
        $prefix = addcslashes($q, '%_\\') . '%';
        $n = self::SEARCH_LIMIT;
        // Titre, artiste ou album : un invité cherche souvent « Daft Punk » plutôt qu'un titre
        $tracks = array_column($db->all(
            "SELECT t.id FROM tracks t JOIN albums a ON a.id = t.album_id LEFT JOIN artists aa ON aa.id = a.album_artist_id
              WHERE t.missing_since IS NULL AND (t.title LIKE ? OR t.artist_credit LIKE ? OR aa.name LIKE ? OR a.title LIKE ?)
              ORDER BY t.title LIKE ? DESC, t.title LIKE ? DESC, a.sort_key, t.disc_number, t.track_number, t.id LIMIT $n",
            [$like, $like, $like, $like, $prefix, $like]
        ), 'id');
        $albums = array_column($db->all(
            "SELECT a.id FROM albums a LEFT JOIN artists aa ON aa.id = a.album_artist_id
              WHERE a.missing_since IS NULL AND (a.title LIKE ? OR aa.name LIKE ?)
              ORDER BY a.title LIKE ? DESC, a.sort_key LIMIT $n",
            [$like, $like, $prefix]
        ), 'id');
        $out = new Serializer($db, $share['userId']);
        return Response::json(['tracks' => $out->tracks(array_map('intval', $tracks)), 'albums' => $out->albums(array_map('intval', $albums))]);
    }

    public function sendRemoteCommand(array $params): Response
    {
        $share = $this->share();
        $this->ctx->limiter->hit('remote-cmd', (string) $share['id'], 120, 60);
        $in = new Input($this->ctx->request->json());
        $type = (string) $in->enum('type', ['add', 'move', 'remove', 'play', 'pause', 'next', 'jump', 'volume']);
        $right = match ($type) {
            'add' => 'add',
            'move', 'remove' => 'edit',
            default => 'control',
        };
        if (!$share['rights'][$right]) {
            throw ApiError::forbidden(match ($right) {
                'add' => 'Ce lien ne permet pas d’ajouter des pistes',
                'edit' => 'Ce lien ne permet pas de modifier la file',
                default => 'Ce lien ne permet pas de commander la lecture',
            });
        }
        $command = ['type' => $type];
        if ($type === 'add') {
            $where = $in->enum('where', ['next', 'end', 'now'], false) ?? 'end';
            if ($where === 'now' && !$share['rights']['control']) {
                throw ApiError::forbidden('Ce lien ne permet pas de commander la lecture');
            }
            $command += ['trackIds' => $this->tracksToAdd($in), 'where' => $where];
        } elseif ($type === 'move') {
            $command += ['from' => (int) $in->int('from', 0, MeController::MAX_QUEUE), 'to' => (int) $in->int('to', 0, MeController::MAX_QUEUE),
                'trackId' => (int) $in->int('trackId', 1)];
        } elseif ($type === 'jump') {
            $command += ['index' => (int) $in->int('index', 0, MeController::MAX_QUEUE), 'trackId' => (int) $in->int('trackId', 1)];
        } elseif ($type === 'volume') {
            $command += ['volume' => (int) $in->int('volume', 0, 100)];
        } elseif ($type === 'remove') {
            $command += ['index' => (int) $in->int('index', 0, MeController::MAX_QUEUE), 'trackId' => (int) $in->int('trackId', 1)];
        }

        $hub = $this->hub();
        $player = (new PlayerStates($this->ctx->db))->get($share['userId']);
        if (!$player['online'] || $player['deviceKey'] === null) {
            throw new ApiError(409, 'player_offline', 'Aucun lecteur allumé : la musique doit jouer (ou être en pause) sur un appareil');
        }
        // Prénom de l'invité (plusieurs personnes par lien), sinon le nom du lien
        $name = trim((string) preg_replace('/[\p{C}]+/u', ' ', (string) ($in->string('name', 0, 40, false) ?? '')));
        $message = ['type' => 'command', 'target' => $player['deviceKey'], 'by' => $name !== '' ? $name : $share['label'], 'command' => $command];
        if ($type === 'add') {
            // Les pistes complètes, dans l'ordre : le lecteur les ajoute sans rien redemander
            $byId = [];
            foreach ((new Serializer($this->ctx->db, $share['userId']))->tracks(array_values(array_unique($command['trackIds']))) as $t) {
                $byId[$t['id']] = $t;
            }
            $message['tracks'] = array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $command['trackIds'])));
        }
        $sent = $hub->publish($this->topic(self::playerTopic($share['userId'])), $message);
        if (!$sent) {
            $this->ctx->logger->error('Télécommande : hub injoignable', ['share' => $share['id']]);
            throw new ApiError(503, 'realtime_unavailable', 'Le lecteur ne peut pas être joint pour l’instant, réessayez');
        }
        return Response::json(['message' => \Musique\Lang::tr('Commande envoyée au lecteur')], 202);
    }

    /** @return list<int> pistes à ajouter (vérifiées), dans l'ordre */
    private function tracksToAdd(Input $in): array
    {
        $db = $this->ctx->db;
        if ($in->has('albumId')) {
            $ids = array_map('intval', array_column($db->all(
                'SELECT id FROM tracks WHERE album_id = ? AND missing_since IS NULL ORDER BY disc_number, track_number IS NULL, track_number, title, id',
                [(int) $in->int('albumId', 1)]
            ), 'id'));
            if ($ids === []) {
                throw ApiError::invalid('albumId', 'Album introuvable ou vide');
            }
            return array_slice($ids, 0, 200);
        }
        $ids = (array) $in->intList('trackIds', 200);
        if ($ids === []) {
            throw ApiError::invalid('trackIds', 'trackIds ou albumId obligatoire');
        }
        $unique = array_values(array_unique($ids));
        $known = (int) $db->value('SELECT COUNT(*) FROM tracks WHERE missing_since IS NULL AND id IN (' . implode(',', array_fill(0, count($unique), '?')) . ')', $unique);
        if ($known !== count($unique)) {
            throw ApiError::invalid('trackIds', 'Pistes inconnues');
        }
        return array_map('intval', $ids);
    }
}
