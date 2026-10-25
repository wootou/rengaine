<?php
// Point de départ commun à l'API et aux commandes : chargement des classes, sans Composer.

declare(strict_types=1);

namespace Musique;

/** Racine du dossier server/. */
const SERVER_DIR = __DIR__;

/** Racine du dépôt (contient db/, api/, docs/). */
const REPO_DIR = __DIR__ . '/..';

// Musique\Storage\DiskStorage → server/src/Storage/DiskStorage.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'Musique\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
