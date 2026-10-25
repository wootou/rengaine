<?php
// Routeur pour le serveur intégré de PHP : sert le lecteur web (web/dist) et l'API (/api/v2) sur une seule
// adresse, sans Apache. Pour essayer Rengaine ou faire des captures, jamais en production.
//
//   RENGAINE_CONFIG=/chemin/rengaine.ini php -S 127.0.0.1:8090 -t web/dist tools/demo-server.php

declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/api/v2' || str_starts_with($path, '/api/v2/')) {
    require __DIR__ . '/../server/public/index.php';
    return true;
}
$dist = realpath(__DIR__ . '/../web/dist');
$file = realpath($dist . $path);
if ($file !== false && str_starts_with($file, $dist . '/') && is_file($file)) {
    return false; // fichier statique : servi tel quel par le serveur intégré
}
// Routes du lecteur (/albums/12, /reglages…) : l'application d'une seule page
header('Content-Type: text/html; charset=utf-8');
readfile($dist . '/index.html');
return true;
