<?php
// Requête HTTP, et lecture validée des paramètres : une valeur hors contrat donne 400 bad_request
// avec le nom du champ, jamais une erreur PHP.

declare(strict_types=1);

namespace Musique\Http;

final class Request
{
    public const MAX_BODY = 1_048_576;
    /** Corps d'une image envoyée telle quelle (images de fond) : 6 Mo au plus. */
    public const MAX_IMAGE = 6_291_456;

    /** @var array<string, string> en minuscules */
    private array $headers;
    private ?array $jsonCache = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        array $headers = [],
        private readonly string $body = '',
        public readonly string $ip = '127.0.0.1',
        public readonly array $cookies = [],
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    /**
     * Requête courante. L'IP est REMOTE_ADDR : derrière un proxy, le serveur web (mod_remoteip pour
     * Apache, real_ip pour Nginx) doit y mettre l'adresse du client prise dans X-Forwarded-For, en ne
     * croyant que ce proxy.
     */
    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr($k, 5)))] = (string) $v;
            } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[str_replace('_', '-', strtolower($k))] = (string) $v;
            }
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $body = '';
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $image = str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'image/');
            $body = (string) file_get_contents('php://input', false, null, 0, ($image ? self::MAX_IMAGE : self::MAX_BODY) + 1);
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $headers,
            $body,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            array_map('strval', $_COOKIE),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isMutating(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * Corps JSON (objet). Corps absent = objet vide.
     * @return array<string, mixed>
     */
    /**
     * Corps brut d'une image (Content-Type image/…). Le type déclaré n'est qu'une indication :
     * l'appelant vérifie le contenu.
     */
    public function image(): string
    {
        if (!str_starts_with(strtolower((string) $this->header('content-type')), 'image/')) {
            throw new ApiError(415, 'bad_request', 'Corps attendu : une image (image/jpeg, image/png ou image/webp)');
        }
        if (strlen($this->body) > self::MAX_IMAGE) {
            throw ApiError::badRequest('Image trop lourde (6 Mo au plus)');
        }
        if ($this->body === '') {
            throw ApiError::badRequest('Image vide');
        }
        return $this->body;
    }

    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        if (strlen($this->body) > self::MAX_BODY) {
            throw new ApiError(413, 'bad_request', 'Corps de requête trop gros (1 Mo au plus)');
        }
        if (trim($this->body) === '') {
            return $this->jsonCache = [];
        }
        $type = strtolower((string) $this->header('content-type'));
        if (!str_starts_with($type, 'application/json')) {
            throw new ApiError(415, 'bad_request', 'Corps attendu en application/json');
        }
        try {
            $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiError::badRequest('JSON invalide');
        }
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            throw ApiError::badRequest('Un objet JSON est attendu');
        }
        return $this->jsonCache = $data;
    }

    // ---------------------------------------------------------------- paramètres de requête

    public function queryString(string $name, int $maxLength = 200): ?string
    {
        $v = $this->query[$name] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_string($v)) {
            throw ApiError::invalid($name, "Paramètre « $name » invalide");
        }
        $v = trim($v);
        if (mb_strlen($v) > $maxLength) {
            throw ApiError::invalid($name, "Paramètre « $name » trop long ($maxLength caractères au plus)");
        }
        return $v === '' ? null : $v;
    }

    public function queryInt(string $name, ?int $default = null, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        $v = $this->query[$name] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        if (!is_string($v) || !preg_match('/^-?\d{1,18}$/', $v)) {
            throw ApiError::invalid($name, "Paramètre « $name » : nombre entier attendu");
        }
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            throw ApiError::invalid($name, "Paramètre « $name » hors limites ($min à $max)");
        }
        return $n;
    }

    public function queryNumber(string $name, float $min = -INF, float $max = INF): ?float
    {
        $v = $this->query[$name] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || !is_numeric($v)) {
            throw ApiError::invalid($name, "Paramètre « $name » : nombre attendu");
        }
        $n = (float) $v;
        if ($n < $min || $n > $max) {
            throw ApiError::invalid($name, "Paramètre « $name » hors limites");
        }
        return $n;
    }

    public function queryBool(string $name): ?bool
    {
        $v = $this->query[$name] ?? null;
        return match ($v) {
            null, '' => null,
            'true', '1' => true,
            'false', '0' => false,
            default => throw ApiError::invalid($name, "Paramètre « $name » : true ou false attendu"),
        };
    }

    /** @param list<string> $allowed */
    public function queryEnum(string $name, array $allowed, ?string $default = null): ?string
    {
        $v = $this->query[$name] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw ApiError::invalid($name, "Paramètre « $name » : valeurs possibles " . implode(', ', $allowed));
        }
        return $v;
    }

    /**
     * Liste d'entiers « 3,12 » (style form, explode=false).
     * @return list<int>
     */
    public function queryIntList(string $name, int $maxItems = 1000): array
    {
        $v = $this->query[$name] ?? null;
        if ($v === null || $v === '') {
            return [];
        }
        if (!is_string($v) || !preg_match('/^\d{1,18}(,\d{1,18})*$/', $v)) {
            throw ApiError::invalid($name, "Paramètre « $name » : liste d'entiers séparés par des virgules attendue");
        }
        $list = array_values(array_unique(array_map('intval', explode(',', $v))));
        if (count($list) > $maxItems) {
            throw ApiError::invalid($name, "Paramètre « $name » : $maxItems valeurs au plus");
        }
        return $list;
    }

    /** @return array{0: int, 1: int} [limit, offset] */
    public function page(int $defaultLimit = 50, int $maxLimit = 500): array
    {
        return [
            (int) $this->queryInt('limit', $defaultLimit, 1, $maxLimit),
            (int) $this->queryInt('offset', 0, 0, 10_000_000),
        ];
    }
}
