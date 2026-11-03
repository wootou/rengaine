<?php
// Jaquettes trouvées en ligne (IA-06) : candidats (iTunes, Deezer, MusicBrainz / Cover Art Archive)
// pour un artiste et un titre, et téléchargement d'une image proposée par l'agent.
//
// Le serveur va chercher une adresse fournie par un client : tout est fermé par défaut.
//   - désactivé sans `[pochettes] recherche_en_ligne = 1` dans la configuration ;
//   - HTTPS seulement, vers une liste fermée de sites (HOSTS) ; chaque redirection est revérifiée
//     (au plus 3), curl ne suit rien seul ; archive.org seulement derrière coverartarchive.org, sur les
//     chemins des jaquettes MusicBrainz (mbid-…) ;
//   - curl ne reçoit jamais la chaîne donnée : une URL refaite à partir des morceaux vérifiés (les
//     analyseurs d'URL de PHP et de curl peuvent voir des hôtes différents) ; \, @, #, espaces et
//     caractères de contrôle refusés d'emblée ;
//   - le nom est résolu ici et toute adresse non publique est refusée (réseau local, boucle, lien
//     local, plages réservées) ; curl est ensuite forcé sur l'adresse vérifiée (CURLOPT_RESOLVE), pour
//     qu'une seconde résolution ne mène pas ailleurs ; aucun proxy, aucun cookie, aucun en-tête d'auth ;
//   - réponses plafonnées (1 Mo pour du JSON, 5 Mo pour une image), délais courts ;
//   - image acceptée seulement si c'est un JPEG, PNG ou WebP lisible, de 200 à 6000 px de côté.
// Rien n'est appliqué ici : l'image devient une proposition (coverImageHash) que l'admin voit et valide.
// Avec anyHost (capacité cover.anyhost d'un jeton d'agent, choix de Rémy), la liste des sites n'est pas
// appliquée ; tout le reste l'est toujours (https, adresses publiques, redirections revérifiées, chemin
// normalisé, taille, délai, vraie image), plus deux listes d'interdits de la configuration :
// `[pochettes] hotes_interdits` (suffixes de noms) et `ip_interdites` (IP ou CIDR), qui bloquent les propres
// services publics de l'installation (un outil protégé par une liste d'IP serait sinon joint par le serveur
// depuis son adresse publique). Le site lui-même ([site] url) et ses adresses sont toujours interdits.
// Un échec réseau ou une réponse qui n'est pas une image donnent un seul message, sans code HTTP : l'IA ne
// peut pas s'en servir pour sonder des sites (le détail est gardé dans lastError, pour le journal).

declare(strict_types=1);

namespace Musique\Library;

use Musique\Api\Kernel;
use Musique\Config;
use Musique\Http\ApiError;

final class CoverSearch
{
    /** Sites permis : nom exact, ou suffixe commençant par un point. */
    public const HOSTS = [
        'itunes.apple.com', 'api.deezer.com', 'musicbrainz.org', 'coverartarchive.org',
        '.mzstatic.com', '.dzcdn.net',
    ];
    /** archive.org : seulement en redirection depuis coverartarchive.org (ou archive.org), chemins mbid-… */
    private const ARCHIVE_HOST = '/^(?:archive\.org|ia\d+\.us\.archive\.org)$/';
    private const ARCHIVE_PATH = '#^/(?:download|\d+/items)/mbid-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/#';
    private const MAX_JSON = 1 << 20;
    private const MAX_IMAGE = 5 << 20;
    private const MAX_REDIRECTS = 3;
    /** Durée totale d'un téléchargement, redirections comprises (un processus PHP est occupé pendant ce temps). */
    private const BUDGET_SECONDS = 20;

    /** Message unique pour tout échec réseau ou toute réponse inutilisable (pas d'oracle). */
    public const NOT_FOUND = 'Image introuvable à cette adresse';

    /** @var ?\Closure(string, int, int, string, bool): array{status: int, body: string, location: ?string, type: ?string} transport remplacé dans les tests */
    public static ?\Closure $transport = null;
    /** @var ?\Closure(string): list<string> résolution DNS (IPv4) remplacée dans les tests */
    public static ?\Closure $resolver = null;

    /** Détail du dernier échec (pour le journal, jamais pour le client). */
    public ?string $lastError = null;

    /**
     * @param list<string> $blockedHosts noms interdits (un nom et tous ses sous-domaines)
     * @param list<string> $blockedNets IP ou CIDR IPv4 interdits
     */
    public function __construct(
        private readonly bool $anyHost = false,
        private readonly array $blockedHosts = [],
        private readonly array $blockedNets = [],
    ) {
    }

    /** Depuis la configuration : listes d'interdits, plus le site lui-même et ses adresses. */
    public static function fromConfig(Config $config, bool $anyHost = false): self
    {
        $split = static fn (string $v) => array_values(array_filter(array_map(static fn ($x) => strtolower(trim($x)), explode(',', $v)), static fn ($x) => $x !== ''));
        $hosts = array_map(static fn ($h) => ltrim($h, '.'), $split($config->has('pochettes', 'hotes_interdits') ? $config->string('pochettes', 'hotes_interdits') : ''));
        $nets = $split($config->has('pochettes', 'ip_interdites') ? $config->string('pochettes', 'ip_interdites') : '');
        $self = $config->has('site', 'url') ? strtolower((string) parse_url($config->string('site', 'url'), PHP_URL_HOST)) : '';
        if ($self !== '') {
            $hosts[] = $self;
            array_push($nets, ...self::resolveHost($self));
        }
        return new self($anyHost, array_values(array_unique($hosts)), array_values(array_unique($nets)));
    }

    public static function enabled(Config $config): bool
    {
        return $config->int('pochettes', 'recherche_en_ligne', 0) === 1;
    }

    /**
     * Candidats pour un album. Une source en panne n'empêche pas les autres (nommée dans errors).
     * @return array{items: list<array{source: string, url: string, artist: string, title: string, size: ?int, exact: bool}>, errors: list<string>}
     */
    public function candidates(string $artist, string $title): array
    {
        $items = [];
        $errors = [];
        $q = trim("$artist $title");
        $sources = [
            'itunes' => fn () => $this->itunes($q),
            'deezer' => fn () => $this->deezer($artist, $title),
            'coverartarchive' => fn () => $this->coverArtArchive($artist, $title),
        ];
        foreach ($sources as $name => $fn) {
            try {
                array_push($items, ...$fn());
            } catch (\Throwable) {
                $errors[] = $name;
            }
        }
        $ka = Text::key($artist);
        $kt = Text::key($title);
        foreach ($items as &$it) {
            $it['exact'] = Text::key($it['artist']) === $ka && Text::key($it['title']) === $kt;
        }
        unset($it);
        usort($items, static fn ($a, $b) => [(int) $b['exact'], $b['size'] ?? 0] <=> [(int) $a['exact'], $a['size'] ?? 0]);
        return ['items' => $items, 'errors' => $errors];
    }

    /** Télécharge une image proposée et la vérifie. @return string octets de l'image */
    public function image(string $url): string
    {
        $r = $this->fetch($url, self::MAX_IMAGE);
        $info = @getimagesizefromstring($r['body']);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $this->lastError = 'pas une image JPEG, PNG ou WebP';
            throw new ApiError(400, 'bad_request', self::NOT_FOUND);
        }
        if (min($info[0], $info[1]) < 200 || max($info[0], $info[1]) > 6000) {
            throw new ApiError(400, 'bad_request', 'Image trop petite ou trop grande (200 à 6000 pixels de côté)');
        }
        return $r['body'];
    }

    /** @return list<array{source: string, url: string, artist: string, title: string, size: ?int}> */
    private function itunes(string $q): array
    {
        $d = $this->json('https://itunes.apple.com/search?' . http_build_query(['term' => $q, 'entity' => 'album', 'limit' => 5]));
        $out = [];
        foreach ((array) ($d['results'] ?? []) as $r) {
            if (is_array($r) && is_string($r['artworkUrl100'] ?? null)) {
                $out[] = ['source' => 'itunes', 'url' => str_replace('100x100bb', '1200x1200bb', $r['artworkUrl100']),
                    'artist' => (string) ($r['artistName'] ?? ''), 'title' => (string) ($r['collectionName'] ?? ''), 'size' => 1200];
            }
        }
        return $out;
    }

    /** @return list<array{source: string, url: string, artist: string, title: string, size: ?int}> */
    private function deezer(string $artist, string $title): array
    {
        $q = $artist === '' ? $title : sprintf('artist:"%s" album:"%s"', str_replace('"', '', $artist), str_replace('"', '', $title));
        $d = $this->json('https://api.deezer.com/search/album?' . http_build_query(['q' => $q, 'limit' => 5]));
        $out = [];
        foreach ((array) ($d['data'] ?? []) as $r) {
            if (is_array($r) && is_string($r['cover_xl'] ?? null)) {
                $out[] = ['source' => 'deezer', 'url' => $r['cover_xl'], 'artist' => (string) ($r['artist']['name'] ?? ''), 'title' => (string) ($r['title'] ?? ''), 'size' => 1000];
            }
        }
        return $out;
    }

    /** @return list<array{source: string, url: string, artist: string, title: string, size: ?int}> */
    private function coverArtArchive(string $artist, string $title): array
    {
        $esc = static fn (string $s) => '"' . str_replace(['\\', '"'], ['', ''], $s) . '"';
        $query = 'releasegroup:' . $esc($title) . ($artist === '' ? '' : ' AND artist:' . $esc($artist));
        $d = $this->json('https://musicbrainz.org/ws/2/release-group/?' . http_build_query(['query' => $query, 'fmt' => 'json', 'limit' => 3]));
        $out = [];
        foreach ((array) ($d['release-groups'] ?? []) as $g) {
            if (is_array($g) && is_string($g['id'] ?? null) && preg_match('/^[0-9a-f-]{36}$/', $g['id'])) {
                $credit = implode('', array_map(static fn ($c) => is_array($c) ? (string) ($c['name'] ?? '') . (string) ($c['joinphrase'] ?? '') : '', (array) ($g['artist-credit'] ?? [])));
                $out[] = ['source' => 'coverartarchive', 'url' => "https://coverartarchive.org/release-group/{$g['id']}/front-1200",
                    'artist' => $credit, 'title' => (string) ($g['title'] ?? ''), 'size' => 1200];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function json(string $url): array
    {
        $d = json_decode($this->fetch($url, self::MAX_JSON)['body'], true);
        if (!is_array($d)) {
            throw new \RuntimeException('Réponse illisible');
        }
        return $d;
    }

    /**
     * GET contrôlé : site permis, adresse publique, redirections revérifiées, taille plafonnée.
     * @return array{status: int, body: string, location: ?string, type: ?string}
     */
    public function fetch(string $url, int $max): array
    {
        $from = null;
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            // Vérifications sur le lien lui-même : refus explicite (rien n'est contacté)
            $url = self::normalize($url, $from, $this->anyHost);
            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($this->hostBlocked($host)) {
                throw new ApiError(400, 'bad_request', 'Lien refusé : site interdit sur ce serveur');
            }
            // Le reste dépend du réseau : un seul message, le détail dans lastError
            $left = (int) ceil($deadline - microtime(true));
            if ($left < 1) {
                throw $this->failed('délai dépassé');
            }
            $ips = self::resolveHost($host);
            if ($ips === []) {
                throw $this->failed("$host : nom introuvable");
            }
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) === false || $this->ipBlocked($ip)) {
                    throw $this->failed("$host : adresse $ip refusée");
                }
            }
            try {
                $r = (self::$transport ?? self::curl(...))($url, $max, $left, $ips[0], $this->anyHost);
            } catch (\RuntimeException $e) {
                throw $this->failed("$host : " . $e->getMessage());
            }
            if ($r['status'] >= 300 && $r['status'] < 400 && $r['location'] !== null) {
                $from = $host;
                $url = self::resolve($url, $r['location']);
                continue;
            }
            if ($r['status'] !== 200) {
                throw $this->failed("$host : HTTP {$r['status']}");
            }
            return $r;
        }
        throw $this->failed('trop de redirections');
    }

    private function failed(string $detail): ApiError
    {
        $this->lastError = $detail;
        return new ApiError(400, 'bad_request', self::NOT_FOUND);
    }

    /** Nom interdit : lui-même ou un sous-domaine d'un nom de la liste. */
    private function hostBlocked(string $host): bool
    {
        foreach ($this->blockedHosts as $b) {
            if ($host === $b || str_ends_with($host, '.' . $b)) {
                return true;
            }
        }
        return false;
    }

    /** Adresse interdite : égale à une IP de la liste, ou dans un de ses CIDR (IPv4). */
    private function ipBlocked(string $ip): bool
    {
        $n = ip2long($ip);
        foreach ($this->blockedNets as $net) {
            [$base, $bits] = array_pad(explode('/', $net, 2), 2, '32');
            $b = ip2long($base);
            $bits = (int) $bits;
            if ($n === false || $b === false || $bits < 0 || $bits > 32) {
                continue;
            }
            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
            if ((($n & $mask) === ($b & $mask))) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function resolveHost(string $host): array
    {
        return array_values(self::$resolver !== null ? (self::$resolver)($host) : (gethostbynamel($host) ?: []));
    }

    /** Lien acceptable ? Renvoie le nom du site (voir normalize). */
    public static function allowed(string $url, ?string $from = null): string
    {
        return (string) parse_url(self::normalize($url, $from), PHP_URL_HOST);
    }

    /**
     * Lien vérifié et refait : https, port 443, sans identifiants ni fragment, site de la liste (archive.org
     * seulement depuis coverartarchive.org), chemin et requête ré-encodés. C'est cette chaîne-là, et elle
     * seule, que reçoit curl.
     */
    public static function normalize(string $url, ?string $from = null, bool $anyHost = false): string
    {
        $refuse = static fn () => $anyHost
            ? new ApiError(400, 'bad_request', 'Lien refusé : une adresse https vers un site public est attendue')
            : new ApiError(400, 'bad_request', 'Lien refusé : seulement les jaquettes d’iTunes, de Deezer et de Cover Art Archive, en https');
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f\\\\@#]/', $url) === 1) {
            throw $refuse();
        }
        $p = parse_url($url);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
            || (($p['port'] ?? 443) !== 443)) {
            throw $refuse();
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $host) !== 1 || filter_var($host, FILTER_VALIDATE_IP)) {
            throw $refuse();
        }
        // Chemin décodé segment par segment : « . », « .. » (curl les retirerait avant d'envoyer, et le chemin
        // vérifié ne serait plus celui demandé) et « / » encodé (%2F) sont refusés
        $decoded = array_map('rawurldecode', explode('/', (string) ($p['path'] ?? '/')));
        foreach ($decoded as $seg) {
            if ($seg === '.' || $seg === '..' || str_contains($seg, '/') || str_contains($seg, '\\') || preg_match('/[\x00-\x1f\x7f]/', $seg) === 1) {
                throw $refuse();
            }
        }
        $path = implode('/', array_map('rawurlencode', $decoded)) ?: '/';
        $ok = false;
        foreach (self::HOSTS as $h) {
            if ($h[0] === '.' ? str_ends_with($host, $h) : $host === $h) {
                $ok = true;
                break;
            }
        }
        if (!$ok && preg_match(self::ARCHIVE_HOST, $host) === 1 && ($from === 'coverartarchive.org' || ($from !== null && preg_match(self::ARCHIVE_HOST, $from) === 1))) {
            $ok = preg_match(self::ARCHIVE_PATH, $path) === 1;
        }
        if (!$ok && !$anyHost) {
            throw $refuse();
        }
        // C'est ce chemin normalisé, celui qui part chez curl, que ARCHIVE_PATH a vérifié
        $out = 'https://' . $host . $path;
        if (isset($p['query']) && $p['query'] !== '') {
            $pairs = [];
            foreach (explode('&', $p['query']) as $kv) {
                [$k, $v] = array_pad(explode('=', $kv, 2), 2, null);
                $pairs[] = rawurlencode(rawurldecode($k)) . ($v === null ? '' : '=' . rawurlencode(rawurldecode(str_replace('+', ' ', $v))));
            }
            $out .= '?' . implode('&', $pairs);
        }
        return $out;
    }

    /** Location d'une redirection, rendue absolue par rapport au lien courant (vérifiée ensuite par normalize). */
    public static function resolve(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;                                   // absolue (un autre schéma sera refusé)
        }
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;                        // « //hôte/x » : même schéma, autre hôte
        }
        $p = parse_url($base);
        $host = (string) ($p['host'] ?? '');
        if (str_starts_with($location, '/')) {
            return "https://$host$location";
        }
        $dir = preg_replace('#/[^/]*$#', '/', (string) ($p['path'] ?? '/')) ?: '/';
        return "https://$host$dir$location";
    }

    /** @return array{status: int, body: string, location: ?string, type: ?string} */
    private static function curl(string $url, int $max, int $timeout, string $ip, bool $imageOnly): array
    {
        // $url vient de normalize() ; $ip est l'une des adresses que fetch() vient de vérifier
        $host = (string) parse_url($url, PHP_URL_HOST);
        $body = '';
        $location = null;
        $type = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => ["$host:443:$ip"],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_MAXFILESIZE => $max,
            CURLOPT_USERAGENT => 'Rengaine/' . Kernel::RELEASE . ' (+https://rengaine.org)',
            CURLOPT_HTTPHEADER => [$imageOnly ? 'Accept: image/*' : 'Accept: application/json, image/*'],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$location, &$type): int {
                if (preg_match('/^location:\s*(\S+)/i', $line, $m)) {
                    $location = $m[1];
                } elseif (preg_match('/^content-type:\s*([^;\s]+)/i', $line, $m)) {
                    $type = strtolower($m[1]);
                }
                return strlen($line);
            },
            // Plafond même sans Content-Length : on coupe dès que la réponse dépasse
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $max): int {
                if (strlen($body) + strlen($chunk) > $max) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($ok === false) {
            throw new \RuntimeException($status === 0 ? 'injoignable ou réponse trop lourde' : 'réponse trop lourde');
        }
        return ['status' => $status, 'body' => $body, 'location' => $location, 'type' => $type];
    }
}
