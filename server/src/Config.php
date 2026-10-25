<?php
// Lecture de /etc/rengaine/rengaine.ini. Les secrets ne sortent jamais de cette classe sous forme
// lisible : describe() les masque.

declare(strict_types=1);

namespace Musique;

final class Config
{
    public const DEFAULT_PATH = '/etc/rengaine/rengaine.ini';
    /** Emplacement des installations antérieures au nom Rengaine, lu si DEFAULT_PATH n'existe pas. */
    public const LEGACY_PATH = '/etc/musique/musique.ini';

    /** Clés dont la valeur ne doit jamais apparaître (sortie, journaux). */
    private const SECRET_KEYS = ['mot_de_passe', 's3_cle', 's3_secret', 'cle_publication', 'cle_abonnement', 'cle'];

    /** @param array<string, array<string, mixed>> $sections */
    private function __construct(private readonly array $sections, public readonly string $path)
    {
    }

    /** Chemin par défaut : DEFAULT_PATH, ou LEGACY_PATH sur une installation antérieure. */
    public static function defaultPath(): string
    {
        return !is_file(self::DEFAULT_PATH) && is_file(self::LEGACY_PATH) ? self::LEGACY_PATH : self::DEFAULT_PATH;
    }

    /** Chemin pris dans la variable RENGAINE_CONFIG, sinon le chemin par défaut. */
    public static function load(?string $path = null): self
    {
        $path ??= getenv('RENGAINE_CONFIG') ?: self::defaultPath();
        if (!is_readable($path)) {
            throw new \RuntimeException("Fichier de configuration illisible : $path");
        }
        $data = parse_ini_file($path, true, INI_SCANNER_TYPED);
        if ($data === false) {
            throw new \RuntimeException("Fichier de configuration invalide : $path");
        }
        // Tests : autre base, autre stockage… sans recopier les secrets dans un second fichier.
        // PHP-FPM vide l'environnement (clear_env) : cette variable n'existe pas en production.
        $overrides = getenv('RENGAINE_CONFIG_OVERRIDES');
        if (is_string($overrides) && $overrides !== '') {
            $extra = json_decode($overrides, true);
            if (!is_array($extra)) {
                throw new \RuntimeException('RENGAINE_CONFIG_OVERRIDES : JSON invalide');
            }
            foreach ($extra as $section => $values) {
                foreach ((array) $values as $k => $v) {
                    $data[$section][$k] = $v;
                }
            }
        }
        return new self($data, $path);
    }

    /** @param array<string, array<string, mixed>> $sections */
    public static function fromArray(array $sections): self
    {
        return new self($sections, '(mémoire)');
    }

    /** Copie avec une valeur remplacée (tests : autre base, autre dossier). */
    public function with(string $section, string $key, mixed $value): self
    {
        $sections = $this->sections;
        $sections[$section][$key] = $value;
        return new self($sections, $this->path);
    }

    public function has(string $section, string $key): bool
    {
        return isset($this->sections[$section][$key]) && $this->sections[$section][$key] !== '';
    }

    public function string(string $section, string $key, ?string $default = null): string
    {
        $value = $this->sections[$section][$key] ?? null;
        if ($value === null || $value === '') {
            if ($default === null) {
                throw new \RuntimeException("Réglage manquant : [$section] $key");
            }
            return $default;
        }
        return (string) $value;
    }

    public function int(string $section, string $key, ?int $default = null): int
    {
        $value = $this->sections[$section][$key] ?? null;
        if ($value === null || $value === '') {
            if ($default === null) {
                throw new \RuntimeException("Réglage manquant : [$section] $key");
            }
            return $default;
        }
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
            throw new \RuntimeException("Réglage [$section] $key : nombre entier attendu");
        }
        return (int) $value;
    }

    /**
     * Toute la config, secrets masqués : seule forme qu'on peut afficher.
     * @return array<string, array<string, mixed>>
     */
    public function describe(): array
    {
        $out = [];
        foreach ($this->sections as $section => $values) {
            foreach ((array) $values as $key => $value) {
                $out[$section][$key] = in_array($key, self::SECRET_KEYS, true)
                    ? ($value === '' || $value === null ? '(vide)' : '(défini, masqué)')
                    : $value;
            }
        }
        return $out;
    }
}
