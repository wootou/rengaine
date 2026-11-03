<?php
// Droits par fonction des jetons d'agent (IA-07, cahier des charges de la revue de sécurité) : chaque
// fonction qu'une IA peut appeler a son interrupteur, porté par le jeton (access_tokens.capabilities).
// Pas de profils : chaque client (Claude Code, une autre IA…) a son jeton, donc ses propres droits.
//   - Chaque route ouverte au rôle agent déclare sa capacité dans Routes ('cap') ; une route agent sans
//     capacité est refusée (fermé par défaut), et un test parcourt toutes les routes.
//   - Le contrôle se fait dans le Kernel pour toute requête d'un agent (API directe comme MCP), et de
//     nouveau dans McpExecutor, qui appelle les contrôleurs sans repasser par le Kernel.
//   - propose_changes : la route demande l'une des capacités « propose.* », puis chaque changement la
//     sienne (forChange).
//   - Une capacité ajoutée par une version future est désactivée pour les jetons existants.
// Les libellés et descriptions sont dans l'interface (traduits) ; ici, identifiant, défaut et risque.

declare(strict_types=1);

namespace Musique\Auth;

use Musique\Http\ApiError;

final class Capabilities
{
    /**
     * Catalogue, dans l'ordre d'affichage. risk : null, « paths » (voit des chemins de fichiers),
     * « structure » (changements lourds), « internet » (le serveur se connecte à Internet).
     */
    public const ALL = [
        'library.read' => ['default' => true, 'risk' => null],
        'library.analysis' => ['default' => true, 'risk' => null],
        'admin.read' => ['default' => false, 'risk' => 'paths'],
        'propose.tags' => ['default' => true, 'risk' => null],
        'propose.structure' => ['default' => false, 'risk' => 'structure'],
        'propose.cover.library' => ['default' => true, 'risk' => null],
        'cover.online' => ['default' => false, 'risk' => 'internet'],
        // Avec cover.online : jaquettes de n'importe quel site https (la liste des sites connus n'est plus
        // appliquée, les autres protections restent). Ne sert à rien seule.
        'cover.anyhost' => ['default' => false, 'risk' => 'anyhost'],
    ];

    /**
     * Capacité de chaque champ qu'un agent peut proposer (noms de l'API, comme dans un lot). Un champ absent
     * est refusé aux agents : un champ ajouté plus tard à ChangeRules n'est pas permis d'office.
     */
    public const FIELDS = [
        'album' => [
            'title' => 'propose.tags', 'year' => 'propose.tags', 'albumArtist' => 'propose.tags', 'isCompilation' => 'propose.tags',
            'genreIds' => 'propose.tags', 'coverImageHash' => 'propose.cover.library', 'coverUrl' => 'cover.online',
            'kind' => 'propose.structure', 'removed' => 'propose.structure', 'mergedInto' => 'propose.structure',
        ],
        'track' => [
            'title' => 'propose.tags', 'artistCredit' => 'propose.tags', 'discNumber' => 'propose.tags', 'trackNumber' => 'propose.tags',
            'bpm' => 'propose.tags', 'key' => 'propose.tags', 'genreIds' => 'propose.tags', 'albumId' => 'propose.structure',
        ],
        'artist' => ['name' => 'propose.tags', 'sortName' => 'propose.tags', 'mergedInto' => 'propose.structure'],
        'genreAlias' => ['genreId' => 'propose.tags'],
    ];

    /** Toute requête d'agent sur une route qui déclare « * » : rien à vérifier (déconnexion, /me). */
    public const ANY = '*';
    /** La route accepte l'une des capacités de proposition ; chaque changement est vérifié ensuite. */
    public const PROPOSE = 'propose.*';

    /** @return list<string> */
    public static function defaults(): array
    {
        return array_keys(array_filter(self::ALL, static fn ($c) => $c['default']));
    }

    /**
     * Ce que pouvait faire un agent en 1.0.3 : tout, sauf les pochettes en ligne. Pour les jetons d'avant la
     * migration 0020 et pour l'organisation lancée depuis l'admin (sans jeton).
     * @return list<string>
     */
    public static function legacy(): array
    {
        return array_values(array_diff(array_keys(self::ALL), ['cover.online', 'cover.anyhost']));
    }

    /** Le principal peut-il proposer une jaquette de n'importe quel site ? (agent avec cover.online et cover.anyhost) */
    public static function anyCoverHost(Principal $p): bool
    {
        $c = $p->capabilities ?? [];
        return $p->role === 'agent' && in_array('cover.online', $c, true) && in_array('cover.anyhost', $c, true);
    }

    /** @return list<array{id: string, default: bool, risk: ?string}> */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::ALL as $id => $c) {
            $out[] = ['id' => $id, 'default' => $c['default'], 'risk' => $c['risk']];
        }
        return $out;
    }

    /** Liste reçue d'un client : identifiants connus, sans doublons, dans l'ordre du catalogue. @param list<mixed> $ids @return list<string> */
    public static function clean(array $ids, string $field): array
    {
        foreach ($ids as $id) {
            if (!is_string($id) || !isset(self::ALL[$id])) {
                throw ApiError::invalid($field, 'Capacité inconnue : ' . (is_scalar($id) ? (string) $id : '?'));
            }
        }
        return array_values(array_filter(array_keys(self::ALL), static fn ($id) => in_array($id, $ids, true)));
    }

    /** Capacité déclarée par une route ('cap'), null si aucune. @param array<string, mixed> $options */
    public static function ofRoute(array $options): ?string
    {
        $cap = $options['cap'] ?? null;
        return is_string($cap) ? $cap : null;
    }

    /**
     * Le principal peut-il appeler cette route ? Seuls les agents sont concernés. Renvoie la capacité
     * qui manque, ou null si c'est permis.
     * @param array<string, mixed> $options
     */
    public static function missing(Principal $p, array $options): ?string
    {
        if ($p->role !== 'agent') {
            return null;
        }
        $cap = self::ofRoute($options);
        if ($cap === null) {
            return 'route'; // route ouverte aux agents sans capacité déclarée : refusée
        }
        if ($cap === self::ANY) {
            return null;
        }
        $granted = $p->capabilities ?? [];
        if ($cap === self::PROPOSE) {
            foreach ($granted as $g) {
                if (str_starts_with($g, 'propose.') || $g === 'cover.online') {
                    return null;
                }
            }
            return 'propose.tags';
        }
        return in_array($cap, $granted, true) ? null : $cap;
    }

    /** Refus clair, pour l'IA comme pour l'admin. */
    public static function denied(string $cap): ApiError
    {
        return match ($cap) {
            'route' => ApiError::forbidden('Fonction non déclarée pour les agents', 'capability_disabled'),
            'field' => ApiError::forbidden('Champ non déclaré pour les agents', 'capability_disabled'),
            default => new ApiError(403, 'capability_disabled', "Fonction désactivée pour ce jeton : $cap", ['capability' => $cap]),
        };
    }

    /** Capacité qu'exige un changement proposé (entityType + field, noms de l'API) ; null : champ non déclaré. */
    public static function forChange(string $entityType, string $field): ?string
    {
        return self::FIELDS[$entityType][$field] ?? null;
    }

    /** Le principal (agent) peut-il proposer ce changement ? Sinon, la capacité qui manque (« field » : champ non déclaré). */
    public static function missingForChange(Principal $p, string $entityType, string $field): ?string
    {
        if ($p->role !== 'agent') {
            return null;
        }
        $cap = self::forChange($entityType, $field);
        if ($cap === null) {
            return 'field';
        }
        return in_array($cap, $p->capabilities ?? [], true) ? null : $cap;
    }
}
