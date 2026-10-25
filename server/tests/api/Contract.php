<?php
// Le contrat OpenAPI vu par les tests : schéma attendu de chaque réponse, et un petit validateur
// JSON Schema (le sous-ensemble utilisé par api/openapi.yaml). Le YAML est converti en JSON par
// Python (python3-yaml), faute de lecteur YAML en PHP sans bibliothèque.

declare(strict_types=1);

namespace Musique\Tests;

final class Contract
{
    private static ?array $spec = null;

    /** @return array<string, mixed> */
    public static function spec(): array
    {
        if (self::$spec !== null) {
            return self::$spec;
        }
        $yaml = \Musique\REPO_DIR . '/api/openapi.yaml';
        $cache = sys_get_temp_dir() . '/musique-openapi-' . md5_file($yaml) . '.json';
        if (!is_file($cache)) {
            $cmd = 'python3 -c ' . escapeshellarg('import sys,json,yaml; json.dump(yaml.safe_load(open(sys.argv[1])), open(sys.argv[2],"w"))')
                . ' ' . escapeshellarg($yaml) . ' ' . escapeshellarg($cache) . ' 2>&1';
            exec($cmd, $out, $code);
            if ($code !== 0) {
                throw new \RuntimeException('Conversion du contrat impossible (python3-yaml ?) : ' . implode(' ', $out));
            }
        }
        return self::$spec = json_decode((string) file_get_contents($cache), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array{method: string, path: string, operationId: string, op: array<string, mixed>}> */
    public static function operations(): array
    {
        $out = [];
        foreach (self::spec()['paths'] as $path => $ops) {
            foreach ($ops as $method => $op) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $out[] = ['method' => strtoupper($method), 'path' => $path, 'operationId' => $op['operationId'], 'op' => $op];
                }
            }
        }
        return $out;
    }

    /** Schéma JSON de la réponse $status de l'opération ; null si la réponse n'a pas de corps JSON. */
    public static function responseSchema(string $operationId, int $status): ?array
    {
        foreach (self::operations() as $o) {
            if ($o['operationId'] !== $operationId) {
                continue;
            }
            $resp = $o['op']['responses'][(string) $status] ?? null;
            if ($resp === null) {
                // « Erreurs : toujours le même format (Error) » : un code d'erreur général (403 anti-CSRF,
                // 429, 501 d'une opération pas encore codée, 503…) n'est pas répété dans chaque opération.
                if ($status >= 400) {
                    return ['$ref' => '#/components/schemas/Error'];
                }
                throw new \RuntimeException("Réponse $status absente du contrat pour $operationId");
            }
            if (isset($resp['$ref'])) {
                $resp = self::resolve($resp['$ref']);
            }
            return $resp['content']['application/json']['schema'] ?? null;
        }
        throw new \RuntimeException("Opération inconnue : $operationId");
    }

    public static function resolve(string $ref): array
    {
        $node = self::spec();
        foreach (explode('/', substr($ref, 2)) as $part) {
            $node = $node[$part] ?? throw new \RuntimeException("Référence introuvable : $ref");
        }
        return $node;
    }

    /**
     * Erreurs de $data (décodé avec des objets stdClass) par rapport à $schema.
     * @return list<string>
     */
    public static function validate(mixed $data, array $schema, string $at = '$', bool $strict = true): array
    {
        if (isset($schema['$ref'])) {
            return self::validate($data, self::resolve($schema['$ref']), $at, $strict);
        }
        $errors = [];
        foreach ($schema['allOf'] ?? [] as $sub) {
            array_push($errors, ...self::validate($data, $sub, $at, false));
        }
        // Champ renvoyé mais absent du contrat (faute de nom) : erreur, sauf objet ouvert
        if ($strict && $data instanceof \stdClass && ($allowed = self::knownProps($schema)) !== null) {
            foreach (array_keys((array) $data) as $k) {
                if (!in_array($k, $allowed, true)) {
                    $errors[] = "$at.$k : champ non prévu par le contrat";
                }
            }
        }
        if (isset($schema['oneOf'])) {
            $ok = false;
            foreach ($schema['oneOf'] as $sub) {
                if (self::validate($data, $sub, $at) === []) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $errors[] = "$at : ne correspond à aucune variante (oneOf)";
            }
        }
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $match = false;
            foreach ($types as $t) {
                $match = $match || match ($t) {
                    'null' => $data === null,
                    'boolean' => is_bool($data),
                    'integer' => is_int($data),
                    'number' => is_int($data) || is_float($data),
                    'string' => is_string($data),
                    'array' => is_array($data),
                    'object' => $data instanceof \stdClass,
                    default => false,
                };
            }
            if (!$match) {
                return [...$errors, "$at : type " . get_debug_type($data) . ', attendu ' . implode('|', $types)];
            }
        }
        if (array_key_exists('const', $schema) && $data !== $schema['const']) {
            $errors[] = "$at : valeur différente de la constante";
        }
        if (isset($schema['enum']) && !in_array($data, $schema['enum'], true)) {
            $errors[] = "$at : « " . json_encode($data) . ' » hors de ' . json_encode($schema['enum']);
        }
        if (is_string($data)) {
            if (isset($schema['pattern']) && !preg_match('~' . str_replace('~', '\~', $schema['pattern']) . '~u', $data)) {
                $errors[] = "$at : « $data » ne suit pas le motif {$schema['pattern']}";
            }
            if (isset($schema['minLength']) && mb_strlen($data) < $schema['minLength']) {
                $errors[] = "$at : trop court";
            }
            if (isset($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) {
                $errors[] = "$at : trop long";
            }
            if (($schema['format'] ?? null) === 'date-time' && !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?Z$/', $data)) {
                $errors[] = "$at : date « $data » pas au format ISO 8601 UTC";
            }
        }
        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = "$at : $data < minimum {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = "$at : $data > maximum {$schema['maximum']}";
            }
        }
        if (is_array($data)) {
            if (isset($schema['maxItems']) && count($data) > $schema['maxItems']) {
                $errors[] = "$at : trop d'éléments";
            }
            if (isset($schema['items'])) {
                foreach ($data as $i => $item) {
                    array_push($errors, ...self::validate($item, $schema['items'], "{$at}[$i]"));
                }
            }
        }
        if ($data instanceof \stdClass) {
            $props = (array) $data;
            foreach ($schema['required'] ?? [] as $req) {
                if (!array_key_exists($req, $props)) {
                    $errors[] = "$at.$req : champ obligatoire absent";
                }
            }
            $known = $schema['properties'] ?? [];
            foreach ($props as $k => $v) {
                if (isset($known[$k])) {
                    array_push($errors, ...self::validate($v, $known[$k], "$at.$k"));
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    array_push($errors, ...self::validate($v, $schema['additionalProperties'], "$at.$k"));
                }
            }
        }
        return $errors;
    }

    /**
     * Noms de champs permis par un schéma d'objet (allOf compris) ; null = objet ouvert.
     * @return list<string>|null
     */
    private static function knownProps(array $schema): ?array
    {
        if (isset($schema['$ref'])) {
            return self::knownProps(self::resolve($schema['$ref']));
        }
        $extra = $schema['additionalProperties'] ?? null;
        if ($extra === true || is_array($extra) || (!isset($schema['properties']) && !isset($schema['allOf']))) {
            return null;
        }
        $names = array_keys($schema['properties'] ?? []);
        foreach ($schema['allOf'] ?? [] as $sub) {
            $more = self::knownProps($sub);
            if ($more === null) {
                return null;
            }
            $names = [...$names, ...$more];
        }
        return $names;
    }
}
