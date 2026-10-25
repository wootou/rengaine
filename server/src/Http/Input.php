<?php
// Lecture validée d'un corps JSON : chaque erreur donne 400 bad_request avec le nom du champ.

declare(strict_types=1);

namespace Musique\Http;

final class Input
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data, private readonly string $prefix = '')
    {
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    private function name(string $field): string
    {
        return $this->prefix . $field;
    }

    private function missing(string $field): never
    {
        throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » obligatoire");
    }

    public function string(string $field, int $min = 0, int $max = 1000, bool $required = true, bool $nullable = false): ?string
    {
        if (!$this->has($field)) {
            return $required ? $this->missing($field) : null;
        }
        $v = $this->data[$field];
        if ($v === null && $nullable) {
            return null;
        }
        if (!is_string($v)) {
            throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » : texte attendu");
        }
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) {
            throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » : de $min à $max caractères");
        }
        return $v;
    }

    /** Texte affiché : espaces de début et de fin retirés, puis longueur vérifiée. */
    public function text(string $field, int $min = 1, int $max = 100, bool $required = true): ?string
    {
        $v = $this->string($field, 0, $max * 4, $required);
        if ($v === null) {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) {
            throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » : de $min à $max caractères");
        }
        return $v;
    }

    public function email(string $field): string
    {
        $v = trim((string) $this->string($field, 3, 254));
        if (filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiError::invalid($this->name($field), 'Adresse e-mail invalide');
        }
        return mb_strtolower($v);
    }

    public function int(string $field, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX, bool $required = true, bool $nullable = false): ?int
    {
        if (!$this->has($field)) {
            return $required ? $this->missing($field) : null;
        }
        $v = $this->data[$field];
        if ($v === null && $nullable) {
            return null;
        }
        if (!is_int($v)) {
            throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » : nombre entier attendu");
        }
        if ($v < $min || $v > $max) {
            throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » : de $min à $max");
        }
        return $v;
    }

    public function number(string $field, float $min = -INF, float $max = INF, bool $required = true): ?float
    {
        if (!$this->has($field)) {
            return $required ? $this->missing($field) : null;
        }
        $v = $this->data[$field];
        if (!is_int($v) && !is_float($v)) {
            throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » : nombre attendu");
        }
        if ($v < $min || $v > $max) {
            throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » hors limites");
        }
        return (float) $v;
    }

    public function bool(string $field, bool $required = true): ?bool
    {
        if (!$this->has($field)) {
            return $required ? $this->missing($field) : null;
        }
        if (!is_bool($this->data[$field])) {
            throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » : true ou false attendu");
        }
        return $this->data[$field];
    }

    /** @param list<string> $allowed */
    public function enum(string $field, array $allowed, bool $required = true): ?string
    {
        if (!$this->has($field)) {
            return $required ? $this->missing($field) : null;
        }
        $v = $this->data[$field];
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » : valeurs possibles " . implode(', ', $allowed));
        }
        return $v;
    }

    /** @return list<int> */
    public function intList(string $field, int $maxItems, bool $required = true): ?array
    {
        if (!$this->has($field)) {
            return $required ? $this->missing($field) : null;
        }
        $v = $this->data[$field];
        if (!is_array($v) || !array_is_list($v)) {
            throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » : liste attendue");
        }
        if (count($v) > $maxItems) {
            throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » : $maxItems éléments au plus");
        }
        foreach ($v as $i) {
            if (!is_int($i) || $i < 0) {
                throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » : entiers positifs attendus");
            }
        }
        return $v;
    }

    /** Sous-objet, pour valider ses champs avec le préfixe « parent. ». */
    public function object(string $field, bool $required = true): ?self
    {
        if (!$this->has($field)) {
            return $required ? $this->missing($field) : null;
        }
        $v = $this->data[$field];
        if (!is_array($v) || (array_is_list($v) && $v !== [])) {
            throw ApiError::invalid($this->name($field), "Champ « {$this->name($field)} » : objet attendu");
        }
        return new self($v, $this->name($field) . '.');
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
