<?php
// Outils du serveur MCP (IA-03). Chaque outil est une route de l'API, appelée en interne avec les
// droits du jeton : l'agent n'obtient rien de plus que par l'API. Un seul outil écrit
// (propose_changes) : il crée un lot en attente, que l'admin applique ou refuse.
//
// Les descriptions s'adressent au modèle qui utilise les outils : elles restent en français, quelle
// que soit la langue du serveur.

declare(strict_types=1);

namespace Musique\Api;

final class McpTools
{
    /** Consignes données au modèle (initialize, et réorganisation lancée depuis l'admin). */
    public const INSTRUCTIONS = <<<'TXT'
        Serveur de la bibliothèque musicale Rengaine. Vous pouvez tout lire (albums, artistes, pistes, genres, tags bruts, doublons, fichiers abîmés, historique des corrections) et proposer des changements avec propose_changes.
        Rien ne change sans l'admin : un lot proposé attend sa décision (appliquer ou refuser), et reste annulable.
        Méthode : chercher (search_library, list_albums), lire le détail (get_album) et l'historique (list_corrections) avant de proposer ; regrouper les changements par thème, un motif court par changement. Les genres se choisissent parmi list_genres.
        TXT;

    /**
     * @return array<string, array{title: string, description: string, route: array{0: string, 1: string},
     *   query?: array<string, string>, body?: list<string>, props: array<string, array<string, mixed>>,
     *   required?: list<string>, write?: bool}>
     */
    public static function all(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $limit = static fn (int $max, int $default) => ['type' => 'integer', 'minimum' => 1, 'maximum' => $max, 'default' => $default];
        $offset = ['type' => 'integer', 'minimum' => 0, 'default' => 0];
        $order = ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'];
        $ids = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => 50];
        $page = ['limit' => 'limit', 'offset' => 'offset'];

        return [
            'search_library' => [
                'title' => 'Rechercher',
                'description' => 'Recherche dans la bibliothèque (albums, artistes, pistes), insensible aux accents. Point de départ pour trouver un identifiant.',
                'route' => ['GET', '/search'],
                'query' => ['q' => 'q', 'limit' => 'limit'],
                'props' => ['q' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'limit' => $limit(50, 8) + ['description' => 'Résultats par groupe']],
                'required' => ['q'],
            ],
            'list_albums' => [
                'title' => 'Lister des albums',
                'description' => 'Albums filtrés et triés. Utile pour parcourir un genre, un artiste, une décennie, ou les ajouts récents (sort=added, order=desc).',
                'route' => ['GET', '/albums'],
                'query' => ['q' => 'q', 'genreIds' => 'genre', 'artistId' => 'artist', 'collectionId' => 'collection', 'yearFrom' => 'yearFrom',
                    'yearTo' => 'yearTo', 'lossless' => 'lossless', 'sort' => 'sort', 'order' => 'order'] + $page,
                'props' => [
                    'q' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Texte cherché dans le titre et l’artiste'],
                    'genreIds' => $ids + ['description' => 'Albums ayant l’un de ces genres (un genre parent comprend ses sous-genres)'],
                    'artistId' => $id, 'collectionId' => $id,
                    'yearFrom' => ['type' => 'integer'], 'yearTo' => ['type' => 'integer'],
                    'lossless' => ['type' => 'boolean', 'description' => 'true : seulement les albums disponibles sans perte'],
                    'sort' => ['type' => 'string', 'enum' => ['name', 'added', 'year', 'artist'], 'default' => 'name'],
                    'order' => $order, 'limit' => $limit(500, 50), 'offset' => $offset,
                ],
            ],
            'get_album' => [
                'title' => 'Lire un album',
                'description' => 'Détail complet d’un album : pistes, genres, chemins et tags bruts des fichiers, corrections actives. À lire avant de proposer un changement sur cet album.',
                'route' => ['GET', '/admin/albums/{id}'],
                'props' => ['id' => $id],
                'required' => ['id'],
            ],
            'list_artists' => [
                'title' => 'Lister des artistes',
                'description' => 'Artistes, avec leur nombre d’albums. Pour repérer des doublons d’orthographe avant une fusion.',
                'route' => ['GET', '/artists'],
                'query' => ['q' => 'q', 'sort' => 'sort', 'order' => 'order'] + $page,
                'props' => [
                    'q' => ['type' => 'string', 'maxLength' => 200],
                    'sort' => ['type' => 'string', 'enum' => ['name', 'albumCount', 'added'], 'default' => 'name'],
                    'order' => $order, 'limit' => $limit(500, 50), 'offset' => $offset,
                ],
            ],
            'get_artist' => [
                'title' => 'Lire un artiste',
                'description' => 'Un artiste et ses albums.',
                'route' => ['GET', '/artists/{id}'],
                'props' => ['id' => $id],
                'required' => ['id'],
            ],
            'list_tracks' => [
                'title' => 'Lister des pistes',
                'description' => 'Pistes filtrées (album, artiste principal ou invité, genre, BPM).',
                'route' => ['GET', '/tracks'],
                'query' => ['q' => 'q', 'albumId' => 'album', 'artistId' => 'artist', 'genreIds' => 'genre', 'bpmMin' => 'bpmMin', 'bpmMax' => 'bpmMax',
                    'sort' => 'sort', 'order' => 'order'] + $page,
                'props' => [
                    'q' => ['type' => 'string', 'maxLength' => 200], 'albumId' => $id, 'artistId' => $id, 'genreIds' => $ids,
                    'bpmMin' => ['type' => 'number', 'minimum' => 0], 'bpmMax' => ['type' => 'number', 'minimum' => 0],
                    'sort' => ['type' => 'string', 'enum' => ['name', 'added', 'bpm', 'duration'], 'default' => 'name'],
                    'order' => $order, 'limit' => $limit(500, 50), 'offset' => $offset,
                ],
            ],
            'get_track' => [
                'title' => 'Lire une piste',
                'description' => 'Une piste : artistes, album, genres, BPM, clé, analyse.',
                'route' => ['GET', '/tracks/{id}'],
                'props' => ['id' => $id],
                'required' => ['id'],
            ],
            'list_genres' => [
                'title' => 'Lister les genres',
                'description' => 'Tous les genres du serveur (arborescence parent / sous-genres), avec leur nombre d’albums. Les identifiants servent dans genreIds.',
                'route' => ['GET', '/genres'],
                'props' => [],
            ],
            'list_genre_tags' => [
                'title' => 'Valeurs de genre des tags',
                'description' => 'Valeurs de genre lues dans les tags des fichiers, rattachées ou non à un genre du serveur. mapped=false liste celles qui restent à rattacher (changement genreAlias).',
                'route' => ['GET', '/admin/genre-aliases'],
                'query' => ['mapped' => 'mapped', 'q' => 'q'] + $page,
                'props' => ['mapped' => ['type' => 'boolean'], 'q' => ['type' => 'string', 'maxLength' => 200], 'limit' => $limit(500, 50), 'offset' => $offset],
            ],
            'list_collections' => [
                'title' => 'Lister les collections',
                'description' => 'Catégories et collections d’albums créées par l’admin.',
                'route' => ['GET', '/collections'],
                'query' => ['kind' => 'kind'],
                'props' => ['kind' => ['type' => 'string', 'enum' => ['category', 'collection']]],
            ],
            'list_duplicates' => [
                'title' => 'Albums en double format',
                'description' => 'Albums dont des pistes existent à la fois en MP3 et sans perte (FLAC…).',
                'route' => ['GET', '/admin/duplicates'],
                'props' => [],
            ],
            'get_duplicates' => [
                'title' => 'Détail d’un double format',
                'description' => 'Pour un album de list_duplicates : les fichiers de chaque format, piste par piste.',
                'route' => ['GET', '/admin/duplicates/{id}'],
                'props' => ['id' => $id],
                'required' => ['id'],
            ],
            'list_same_recordings' => [
                'title' => 'Mêmes enregistrements',
                'description' => 'Pistes qui contiennent le même enregistrement (empreinte acoustique), souvent sur des albums différents.',
                'route' => ['GET', '/admin/same-recordings'],
                'query' => ['minScore' => 'minScore'] + $page,
                'props' => ['minScore' => ['type' => 'number', 'minimum' => 0.5, 'maximum' => 1, 'default' => 0.8], 'limit' => $limit(500, 50), 'offset' => $offset],
            ],
            'list_damaged_tracks' => [
                'title' => 'Fichiers abîmés',
                'description' => 'Pistes dont le fichier a des trames illisibles.',
                'route' => ['GET', '/admin/damaged-tracks'],
                'query' => $page,
                'props' => ['limit' => $limit(500, 50), 'offset' => $offset],
            ],
            'list_inbox' => [
                'title' => 'Dépôt',
                'description' => 'Albums déposés dans inbox/ : en attente de rangement, rangés, ou refusés avec le motif.',
                'route' => ['GET', '/admin/inbox'],
                'props' => [],
            ],
            'list_job_errors' => [
                'title' => 'Erreurs d’indexation',
                'description' => 'Fichiers que l’indexation ou l’analyse n’a pas pu lire.',
                'route' => ['GET', '/admin/job-errors'],
                'query' => ['open' => 'open'] + $page,
                'props' => ['open' => ['type' => 'boolean', 'default' => true, 'description' => 'true : seulement les erreurs non résolues'], 'limit' => $limit(500, 50), 'offset' => $offset],
            ],
            'list_corrections' => [
                'title' => 'Historique des corrections',
                'description' => 'Corrections déjà faites ou proposées. Avant de proposer un changement, vérifier qu’il n’a pas déjà été refusé.',
                'route' => ['GET', '/admin/corrections'],
                'query' => ['entityType' => 'entityType', 'entityId' => 'entityId', 'status' => 'status'] + $page,
                'props' => [
                    'entityType' => ['type' => 'string', 'enum' => ['album', 'track', 'artist', 'genreAlias']], 'entityId' => $id,
                    'status' => ['type' => 'string', 'enum' => ['proposed', 'active', 'rejected', 'reverted']],
                    'limit' => $limit(500, 50), 'offset' => $offset,
                ],
            ],
            'list_change_sets' => [
                'title' => 'Lots de changements',
                'description' => 'Lots proposés (pending), appliqués, refusés ou annulés, les plus récents d’abord.',
                'route' => ['GET', '/admin/change-sets'],
                'query' => ['status' => 'status'] + $page,
                'props' => ['status' => ['type' => 'string', 'enum' => ['pending', 'applied', 'rejected', 'reverted']], 'limit' => $limit(500, 50), 'offset' => $offset],
            ],
            'get_change_set' => [
                'title' => 'Lire un lot',
                'description' => 'Un lot avec chacun de ses changements, la valeur actuelle et l’état de chaque correction.',
                'route' => ['GET', '/admin/change-sets/{id}'],
                'props' => ['id' => $id],
                'required' => ['id'],
            ],
            'propose_changes' => [
                'title' => 'Proposer des changements',
                'description' => <<<'TXT'
                    Propose un lot de changements à l'admin. Rien ne change dans la bibliothèque tant que l'admin ne l'a pas appliqué (Admin > Propositions) ; il peut aussi le refuser, ou l'annuler après coup.
                    Un lot = un thème (« Genres des albums de jazz », « Fusion des doublons d'artistes ») ; donner un motif court à chaque changement.
                    Chaque changement : entityType + entityId + field + value. Champs possibles :
                    - album : title, year (ou null), albumArtist (nom, ou null), isCompilation, genreIds (liste d'identifiants de list_genres) ;
                    - track : title, artistCredit, discNumber, trackNumber, bpm, key (notation anglaise : C, Am, F#m), genreIds (null = ceux de l'album), albumId (déplacer la piste) ;
                    - artist : name, sortName, mergedInto (identifiant de l'artiste qui le remplace : fusion) ;
                    - genreAlias : alias (valeur de tag, à la place de entityId) + field genreId (ou null pour détacher).
                    Chaque changement est vérifié tout de suite : une erreur nomme le changement fautif (changes[3].value) et aucun lot n'est créé.
                    TXT,
                'route' => ['POST', '/admin/change-sets'],
                'body' => ['title', 'description', 'changes'],
                'props' => [
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'description' => ['type' => 'string', 'maxLength' => 10000, 'description' => 'Ce que fait le lot et pourquoi'],
                    'changes' => [
                        'type' => 'array', 'minItems' => 1, 'maxItems' => 2000,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'entityType' => ['type' => 'string', 'enum' => ['album', 'track', 'artist', 'genreAlias']],
                                'entityId' => ['type' => 'integer', 'minimum' => 1],
                                'alias' => ['type' => 'string', 'description' => 'Seulement pour genreAlias'],
                                'field' => ['type' => 'string'],
                                'value' => ['description' => 'Nouvelle valeur (type selon le champ)'],
                                'reason' => ['type' => 'string', 'maxLength' => 1000],
                            ],
                            'required' => ['entityType', 'field', 'value'],
                        ],
                    ],
                ],
                'required' => ['title', 'changes'],
                'write' => true,
            ],
        ];
    }

    /**
     * Description MCP d'un outil (tools/list).
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    public static function describe(string $name, array $tool): array
    {
        $schema = ['type' => 'object', 'properties' => $tool['props'] === [] ? new \stdClass() : $tool['props']];
        if (($tool['required'] ?? []) !== []) {
            $schema['required'] = $tool['required'];
        }
        $schema['additionalProperties'] = false;
        $write = (bool) ($tool['write'] ?? false);
        return [
            'name' => $name,
            'title' => $tool['title'],
            'description' => $tool['description'],
            'inputSchema' => $schema,
            'annotations' => ['title' => $tool['title'], 'readOnlyHint' => !$write, 'destructiveHint' => false, 'idempotentHint' => !$write, 'openWorldHint' => false],
        ];
    }
}
