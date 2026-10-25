<?php
// Point d'entrée unique de l'API v2 (servi par Apache + PHP-FPM sous /api/v2/).
// Pour essayer sans Apache : php -S 127.0.0.1:8080 server/public/index.php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Musique\Api\Kernel;
use Musique\Config;
use Musique\Http\Request;
use Musique\Http\Response;
use Musique\Log\ErrorHandler;
use Musique\Log\Logger;
use Musique\Log\SecurityLog;

ini_set('display_errors', '0');
ini_set('pcre.jit', '0'); // leçon de l'ancien site : PHP 8.5 signalait l'échec du JIT par un avertissement

try {
    $config = Config::load();
    $logger = Logger::fromConfig($config, 'api');
    ErrorHandler::install($logger);
    $kernel = new Kernel($config, $logger, SecurityLog::fromConfig($config));
    $request = Request::fromGlobals();
    $response = $kernel->handle($request);
    if ($request->method === 'HEAD') {
        $response->body = null;
        $response->stream = null;
    }
} catch (Throwable $e) {
    // Configuration illisible : pas encore de journal, on passe par celui de PHP
    error_log('musique api : ' . $e->getMessage());
    $response = Response::json(['error' => ['status' => 500, 'code' => 'internal', 'message' => 'Serveur mal configuré', 'requestId' => '']], 500);
}
$response->send();
if (isset($kernel)) {
    // La réponse est partie (PHP-FPM ferme la connexion) ; ce qui suit ne fait plus attendre le client
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    $kernel->afterResponse();
}
