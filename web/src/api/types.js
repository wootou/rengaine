// @ts-check
// Types du contrat, générés depuis api/openapi.yaml (npm run types). Jamais écrits à la main.

/** @typedef {import('./api.generated').components['schemas']} Schemas */
/** @typedef {Schemas['Track']} Track */
/** @typedef {Schemas['AlbumSummary']} AlbumSummary */
/** @typedef {Schemas['AlbumDetail']} AlbumDetail */
/** @typedef {Schemas['ArtistSummary']} ArtistSummary */
/** @typedef {Schemas['ArtistDetail']} ArtistDetail */
/** @typedef {Schemas['Genre']} Genre */
/** @typedef {Schemas['User']} User */
/** @typedef {Schemas['MeResponse']} MeResponse */
/** @typedef {Schemas['UserSettings']} UserSettings */
/** @typedef {Schemas['UserSettingsDocument']} UserSettingsDocument */
/** @typedef {Schemas['PlayQueue']} PlayQueue */
/** @typedef {Schemas['PlayQueueInput']} PlayQueueInput */
/** @typedef {Schemas['QueueMode']} QueueMode */
/** @typedef {Schemas['SearchResults']} SearchResults */
/** @typedef {Schemas['ServerInfo']} ServerInfo */
/** @typedef {Schemas['Device']} Device */
/** @typedef {Schemas['PlayInput']} PlayInput */
/** @typedef {Schemas['Error']['error']} ErrorBody */
/** @typedef {Schemas['QueueShare']} QueueShare */
/** @typedef {Schemas['QueueShareCreated']} QueueShareCreated */
/** @typedef {Schemas['QueueShareRights']} QueueShareRights */
/** @typedef {Schemas['RealtimeChannel']} RealtimeChannel */
/** @typedef {Schemas['RemoteCommand']} RemoteCommand */
/** @typedef {Schemas['RemoteState']} RemoteState */
/** @typedef {Schemas['PlayerReport']} PlayerReport */
/**
 * Message du hub temps réel (voir RealtimeChannel dans le contrat).
 * @typedef {{ type: 'command' | 'shares' | 'changed' | 'revoked' | 'disabled', target?: string, by?: string, command?: RemoteCommand, tracks?: Track[] }} RealtimeEvent
 */

export {};
