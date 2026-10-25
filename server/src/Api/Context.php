<?php
// Tout ce dont un contrôleur a besoin pour une requête : config, base, journal, compte connecté.

declare(strict_types=1);

namespace Musique\Api;

use Musique\Auth\Principal;
use Musique\Auth\Tokens;
use Musique\Config;
use Musique\Db\Database;
use Musique\Http\ApiError;
use Musique\Http\RateLimiter;
use Musique\Http\Request;
use Musique\Log\Logger;
use Musique\Log\SecurityLog;
use Musique\Mail\Notices;
use Musique\Storage\Storage;
use Musique\Storage\StorageFactory;

final class Context
{
    public ?Principal $principal = null;
    /**
     * Invité d'un lien de télécommande (FUT-02), sur les routes qui l'acceptent ; voir Playback\Shares::verify().
     * @var array{id: int, userId: int, label: string, ownerName: string, rights: array{add: bool, edit: bool, control: bool}, expiresAt: string, idle: int}|null
     */
    public ?array $share = null;
    public ?string $shareSecret = null;
    private ?Storage $storage = null;
    private ?Tokens $tokens = null;

    public function __construct(
        public readonly Config $config,
        public readonly Database $db,
        public Logger $logger,
        public readonly Request $request,
        public readonly string $requestId,
        public readonly RateLimiter $limiter,
        public readonly SecurityLog $security,
    ) {
    }

    /** Le compte connecté (les routes publiques n'y ont pas forcément accès). */
    public function user(): Principal
    {
        return $this->principal ?? throw ApiError::unauthorized();
    }

    public function userId(): ?int
    {
        return $this->principal?->userId;
    }

    public function storage(): Storage
    {
        return $this->storage ??= StorageFactory::fromConfig($this->config);
    }

    public function tokens(): Tokens
    {
        return $this->tokens ??= new Tokens($this->db);
    }

    public function cacheDir(): string
    {
        return rtrim($this->config->string('cache', 'dossier', '/var/cache/rengaine'), '/');
    }

    /**
     * Adresse publique du site ([site] url) : liens des mails, QR d'appairage, liens de télécommande,
     * sujets du hub. Obligatoire (revue sécurité du 2026-10-24) : la déduire de l'en-tête Host laissait
     * un visiteur choisir l'adresse mise dans un mail.
     */
    public function publicUrl(): string
    {
        $url = $this->config->has('site', 'url') ? rtrim(trim($this->config->string('site', 'url')), '/') : '';
        if (!preg_match('#^https?://[^/\s]+(/\S*)?$#', $url)) {
            throw new \RuntimeException('Réglage manquant ou invalide : [site] url (adresse publique du site)');
        }
        return $url;
    }

    /** Mails du site (mis en file, envoyés après la réponse). */
    public function notices(): Notices
    {
        return new Notices($this->db, $this->config, $this->publicUrl());
    }

    /** Journal d'audit (JRN-02). Jamais de secret dans $before / $after. Jeton 0 = tâche de fond (IA-04), sans jeton. */
    public function audit(string $action, ?string $targetType = null, ?int $targetId = null, mixed $before = null, mixed $after = null, ?int $actorUserId = null): void
    {
        $json = static fn (mixed $v): ?string => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->run(
            'INSERT INTO audit_log (actor_user_id, actor_token_id, action, target_type, target_id, before_value, after_value, ip, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$actorUserId ?? $this->principal?->userId, $actorUserId === null ? ($this->principal?->tokenId ?: null) : null, $action, $targetType, $targetId,
                $json($before), $json($after), $this->request->ip, $this->requestId]
        );
    }
}
