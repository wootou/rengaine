<?php
// Les opérations du contrat (api/openapi.yaml). Un test (ContractTest) vérifie que cette table
// et le contrat disent la même chose : chemins, méthodes, accès sans authentification, rôles.
// Handler null = pas encore codé → 501 not_implemented.

declare(strict_types=1);

namespace Musique\Api;

use Musique\Api\Controllers\AdminController as Ad;
use Musique\Api\Controllers\AdminLibraryController as L;
use Musique\Api\Controllers\AuthController as A;
use Musique\Api\Controllers\BackgroundController as B;
use Musique\Api\Controllers\CatalogController as C;
use Musique\Api\Controllers\MediaController as M;
use Musique\Api\Controllers\MeController as Me;
use Musique\Api\Controllers\McpController as Mcp;
use Musique\Api\Controllers\PlaybackController as Pb;
use Musique\Api\Controllers\PlaylistController as P;
use Musique\Api\Controllers\RemoteController as R;
use Musique\Http\Router;

final class Routes
{
    public static function build(): Router
    {
        $r = new Router();
        $public = ['public' => true];
        $admin = ['roles' => ['admin']];
        $adminAgent = ['roles' => ['admin', 'agent']];
        // Sans rôle indiqué : admin et user. L'agent (IA-01) n'a que les routes qui le citent, et chacune
        // déclare la capacité qu'elle demande à un jeton d'agent (IA-07, Capabilities) : sans elle, refusée.
        $all = ['roles' => ['admin', 'user', 'agent']];
        $cap = static fn (array $o, string $c) => $o + ['cap' => $c];
        $read = $cap($all, 'library.read');
        $agentRead = $cap($adminAgent, 'library.read');
        $agentAnalysis = $cap($adminAgent, 'library.analysis');
        $agentAdminRead = $cap($adminAgent, 'admin.read');

        // Serveur et authentification
        $r->add('GET', '/server', [A::class, 'getServer'], $public);
        $r->add('POST', '/auth/login', [A::class, 'login'], $public);
        $r->add('POST', '/auth/logout', [A::class, 'logout'], $cap($all, '*'));
        $r->add('POST', '/auth/register', [A::class, 'register'], $public);
        $r->add('POST', '/auth/password/forgot', [A::class, 'forgotPassword'], $public);
        $r->add('POST', '/auth/password/reset', [A::class, 'resetPassword'], $public);
        $r->add('POST', '/auth/pair', [A::class, 'pairDevice'], $public);

        // Compte connecté
        $r->add('GET', '/me', [Me::class, 'getMe'], $cap($all, '*'));
        $r->add('PATCH', '/me', [Me::class, 'updateMe']);
        $r->add('DELETE', '/me', [Me::class, 'deleteMe']);                    // CPT-09
        $r->add('GET', '/me/export', [Me::class, 'exportMyData']);            // CPT-10
        $r->add('POST', '/me/password', [Me::class, 'changePassword']);
        $r->add('GET', '/me/devices', [Me::class, 'listMyDevices']);
        $r->add('DELETE', '/me/devices/{tokenId}', [Me::class, 'revokeMyDevice']);
        $r->add('POST', '/me/pairing-codes', [Me::class, 'createPairingCode']);
        $r->add('GET', '/me/settings', [Me::class, 'getMySettings']);
        $r->add('PUT', '/me/settings', [Me::class, 'putMySettings']);
        $r->add('GET', '/me/queue', [Me::class, 'getMyQueue']);
        $r->add('PUT', '/me/queue', [Me::class, 'putMyQueue']);
        $r->add('GET', '/me/favorites', [Me::class, 'listMyFavorites']);
        $r->add('PUT', '/me/favorites/{type}/{id}', [Me::class, 'addFavorite']);
        $r->add('DELETE', '/me/favorites/{type}/{id}', [Me::class, 'removeFavorite']);
        $r->add('GET', '/playlists', [P::class, 'listPlaylists']);
        $r->add('POST', '/playlists', [P::class, 'createPlaylist']);
        $r->add('GET', '/playlists/{id}', [P::class, 'getPlaylist']);
        $r->add('PATCH', '/playlists/{id}', [P::class, 'updatePlaylist']);
        $r->add('DELETE', '/playlists/{id}', [P::class, 'deletePlaylist']);
        $r->add('POST', '/playlists/{id}/items', [P::class, 'addPlaylistItems']);
        $r->add('PUT', '/playlists/{id}/items', [P::class, 'reorderPlaylistItems']);
        $r->add('DELETE', '/playlists/{id}/items/{itemId}', [P::class, 'removePlaylistItem']);
        $r->add('GET', '/me/history', [Me::class, 'listMyHistory']);
        $r->add('POST', '/me/history', [Me::class, 'recordPlays']);
        $r->add('DELETE', '/me/history', [Me::class, 'clearMyHistory']);
        $r->add('DELETE', '/me/history/{id}', [Me::class, 'deleteMyPlay']);

        // Catalogue
        $r->add('GET', '/albums', [C::class, 'listAlbums'], $read);
        $r->add('GET', '/albums/{id}', [C::class, 'getAlbum'], $read);
        $r->add('GET', '/artists', [C::class, 'listArtists'], $read);
        $r->add('GET', '/artists/{id}', [C::class, 'getArtist'], $read);
        $r->add('GET', '/tracks', [C::class, 'listTracks'], $read);
        $r->add('GET', '/tracks/{id}', [C::class, 'getTrack'], $read);
        $r->add('GET', '/genres', [C::class, 'listGenres'], $read);
        $r->add('GET', '/collections', [C::class, 'listCollections'], $read);
        $r->add('GET', '/collections/{id}', [C::class, 'getCollection'], $read);
        $r->add('GET', '/moods', [C::class, 'listMoods'], $read);
        $r->add('GET', '/decades', [C::class, 'listDecades'], $read);
        $r->add('GET', '/search', [C::class, 'search'], $read);
        $r->add('GET', '/random/tracks', [C::class, 'randomTracks']);

        // Lecture
        $r->add('GET', '/tracks/{id}/stream', [M::class, 'streamTrack']);
        $r->add('GET', '/tracks/{id}/waveform', [C::class, 'getTrackWaveform']); // AUD-07
        $r->add('GET', '/images/{hash}', [M::class, 'getImage'], ['guest' => 'also'] + $read);
        $r->add('GET', '/backgrounds', [B::class, 'listBackgrounds']);
        $r->add('GET', '/backgrounds/{id}/image', [B::class, 'getBackgroundImage']);
        $r->add('POST', '/playback/next', [Pb::class, 'nextTracks']);         // LEC-03

        // Télécommande partagée (FUT-02) : propriétaire, puis invité (cookie musique_remote)
        $guest = ['guest' => 'only'];
        $r->add('GET', '/me/shares', [R::class, 'listMyShares']);
        $r->add('POST', '/me/shares', [R::class, 'createMyShare']);
        $r->add('DELETE', '/me/shares/{id}', [R::class, 'revokeMyShare']);
        $r->add('GET', '/me/realtime', [R::class, 'getMyRealtimeChannel']);
        $r->add('PUT', '/me/player', [R::class, 'reportMyPlayer']);
        $r->add('POST', '/remote/session', [R::class, 'openRemoteSession'], $public);
        $r->add('GET', '/remote', [R::class, 'getRemote'], $guest);
        $r->add('GET', '/remote/search', [R::class, 'searchRemote'], $guest);
        $r->add('POST', '/remote/commands', [R::class, 'sendRemoteCommand'], $guest);

        // Admin : comptes et jetons
        $r->add('GET', '/admin/users', [Ad::class, 'listUsers'], $admin);
        $r->add('POST', '/admin/users', [Ad::class, 'createUser'], $admin);
        $r->add('GET', '/admin/users/{id}', [Ad::class, 'getUser'], $admin);
        $r->add('PATCH', '/admin/users/{id}', [Ad::class, 'updateUser'], $admin);
        $r->add('POST', '/admin/users/{id}/tokens', [Ad::class, 'createToken'], $admin);
        $r->add('DELETE', '/admin/tokens/{tokenId}', [Ad::class, 'revokeToken'], $admin);
        $r->add('PATCH', '/admin/tokens/{tokenId}', [Ad::class, 'updateToken'], $admin);
        $r->add('GET', '/admin/capabilities', [Ad::class, 'listCapabilities'], $admin);
        $r->add('GET', '/admin/server-settings', [Ad::class, 'getServerSettings'], $admin);
        $r->add('PATCH', '/admin/server-settings', [Ad::class, 'updateServerSettings'], $admin);

        // Admin : bibliothèque (genres, corrections, séparation et fusion d'albums, artistes, lots de l'agent)
        $r->add('GET', '/admin/genre-aliases', [L::class, 'listGenreAliases'], $agentAnalysis);
        $r->add('POST', '/admin/genre-aliases', [L::class, 'mapGenreAlias'], $admin);
        $r->add('POST', '/admin/albums', [L::class, 'createAlbum'], $admin);
        $r->add('POST', '/admin/albums/{id}/merge', [L::class, 'mergeAlbum'], $admin);
        $r->add('GET', '/admin/albums/{id}', [L::class, 'getAdminAlbum'], $agentRead);
        $r->add('PATCH', '/admin/albums/{id}', [L::class, 'updateAlbum'], $admin);
        $r->add('PATCH', '/admin/tracks/{id}', [L::class, 'updateTrack'], $admin);
        $r->add('PATCH', '/admin/artists/{id}', [L::class, 'updateArtist'], $admin);
        $r->add('POST', '/admin/artists/{id}/merge', [L::class, 'mergeArtist'], $admin);
        $r->add('GET', '/admin/artist-lookalikes', [L::class, 'listArtistLookalikes'], $agentAnalysis);
        $r->add('GET', '/admin/duplicate-albums', [L::class, 'listDuplicateAlbums'], $agentAnalysis);
        $r->add('GET', '/admin/albums/{id}/cover-candidates', [L::class, 'listCoverCandidates'], $cap($adminAgent, 'cover.online'));
        $r->add('GET', '/admin/title-credit-suggestions', [L::class, 'listTitleCreditSuggestions'], $agentAnalysis);
        $r->add('PUT', '/admin/artists/{id}/image', [L::class, 'setArtistImage'], $admin);
        $r->add('POST', '/admin/collections', [L::class, 'createCollection'], $admin);
        $r->add('PATCH', '/admin/collections/{id}', [L::class, 'updateCollection'], $admin);
        $r->add('DELETE', '/admin/collections/{id}', [L::class, 'deleteCollection'], $admin);
        $r->add('PUT', '/admin/collections/{id}/albums', [L::class, 'setCollectionAlbums'], $admin);
        $r->add('DELETE', '/admin/artists/{id}/image', [L::class, 'deleteArtistImage'], $admin);
        $r->add('POST', '/admin/genres', [L::class, 'createGenre'], $admin);
        $r->add('PATCH', '/admin/genres/{id}', [L::class, 'updateGenre'], $admin);
        $r->add('DELETE', '/admin/genres/{id}', [L::class, 'deleteGenre'], $admin);
        $r->add('GET', '/admin/corrections', [L::class, 'listCorrections'], $agentRead);
        $r->add('POST', '/admin/corrections/{id}/revert', [L::class, 'revertCorrection'], $admin);
        $r->add('GET', '/admin/change-sets', [L::class, 'listChangeSets'], $agentRead);
        $r->add('POST', '/admin/change-sets', [L::class, 'createChangeSet'], $cap($adminAgent, 'propose.*'));
        $r->add('GET', '/admin/change-sets/{id}', [L::class, 'getChangeSet'], $agentRead);
        $r->add('POST', '/admin/change-sets/{id}/{action}', [L::class, 'decideChangeSet'], $admin);

        // Serveur MCP (IA-03) : les outils de l'agent, au-dessus des routes précédentes
        $r->add('POST', '/mcp', [Mcp::class, 'handle'], ['roles' => ['agent'], 'cap' => '*']);

        // Admin : tâches et journaux
        $r->add('GET', '/admin/jobs', [Ad::class, 'listJobs'], $admin);
        $r->add('POST', '/admin/jobs', [Ad::class, 'createJob'], $admin);
        $r->add('GET', '/admin/jobs/{id}', [Ad::class, 'getJob'], $admin);
        $r->add('GET', '/admin/inbox', [Ad::class, 'listInbox'], $agentAdminRead);
        $r->add('GET', '/admin/duplicates', [Ad::class, 'listDuplicates'], $agentAnalysis);
        $r->add('GET', '/admin/same-recordings', [Ad::class, 'listSameRecordings'], $agentAnalysis); // BAS-03
        $r->add('GET', '/admin/damaged-tracks', [Ad::class, 'listDamagedTracks'], $agentAnalysis);
        $r->add('GET', '/admin/duplicates/{id}', [Ad::class, 'getDuplicates'], $agentAnalysis);
        $r->add('GET', '/admin/backgrounds', [B::class, 'adminListBackgrounds'], $admin);
        $r->add('POST', '/admin/backgrounds', [B::class, 'adminCreateBackground'], $admin);
        $r->add('PATCH', '/admin/backgrounds/{id}', [B::class, 'adminUpdateBackground'], $admin);
        $r->add('DELETE', '/admin/backgrounds/{id}', [B::class, 'adminDeleteBackground'], $admin);
        $r->add('GET', '/admin/job-errors', [Ad::class, 'listJobErrors'], $agentAdminRead);
        $r->add('POST', '/admin/job-errors/resolve', [Ad::class, 'resolveJobErrors'], $admin);
        $r->add('GET', '/admin/audit', [Ad::class, 'listAudit'], $admin);
        $r->add('GET', '/admin/logs', [Ad::class, 'listLogs'], $admin);

        return $r;
    }
}
