<?php
// Interface unique des deux modes de stockage (STO-01). Le reste du code ne sait pas lequel est actif.
//
// Une clé est un chemin relatif à la racine du stockage (« Artiste/Album/01 - Titre.flac »),
// identique en mode disque et en mode S3. Voir Keys::assertValid() pour les règles.

declare(strict_types=1);

namespace Musique\Storage;

interface Storage
{
    /** « disque » ou « s3 ». */
    public function mode(): string;

    /**
     * Tous les fichiers dont la clé commence par $prefix, triés par octets (ordre de S3).
     * Les fichiers et dossiers cachés (nom commençant par un point) sont ignorés.
     * @return \Generator<int, StorageObject>
     */
    public function list(string $prefix = ''): \Generator;

    /** null si le fichier n'existe pas. */
    public function stat(string $key): ?StorageObject;

    /** Contenu complet : réservé aux petits fichiers (images, meta.json). */
    public function get(string $key): string;

    /**
     * Envoie une plage d'octets à $sink, morceau par morceau (lecture d'un morceau, Range HTTP).
     * $length = null : jusqu'à la fin du fichier.
     * @param callable(string): void $sink
     */
    public function stream(string $key, int $offset, ?int $length, callable $sink): void;

    /** Écrit (ou remplace) un fichier à partir d'un fichier local. */
    public function put(string $key, string $localFile, ?string $contentType = null): StorageObject;

    /**
     * Déplace un fichier (rangement du dépôt). N'écrase jamais : erreur si $to existe déjà.
     * Disque : renommage. S3 : copie côté serveur, taille vérifiée, puis suppression de l'original.
     */
    public function move(string $from, string $to): StorageObject;

    /** Supprime un fichier ; sans effet s'il n'existe pas. */
    public function delete(string $key): void;

    /**
     * Source lisible par ffprobe / ffmpeg : chemin local (disque) ou adresse signée temporaire (S3).
     * ffprobe ne lit alors que l'en-tête du fichier, sans le télécharger en entier.
     */
    public function mediaSource(string $key, int $ttlSeconds = 3600): string;
}
