# Changelog

All notable changes are listed here. The API contract has its own version (`info.version` in
`api/openapi.yaml`, reported by `GET /api/v2/server`).

## 1.0.3 (2026-11-03)

Upgrading from 1.0.2: no database migration. Rebuild the web player. Remote control links of an account
are now revoked when its password changes or when it is suspended: guests need a new link afterwards.

- Security: changing or resetting a password, or suspending the account, now also revokes every remote
  control link of the account; a guest who is connected is told at once (API contract 2.28.1). A link
  created from a stolen session no longer outlives the new password, and reactivating an account does not
  bring its links back.
- MCP and admin API (contract 2.29.0), after a first real tidy-up of a 1,135-album library by an AI:
  - an album can be switched between `album` and `loose` (tracks in bulk), by `PATCH /admin/albums/{id}`
    or in a proposal batch; the choice survives scans;
  - a proposal can merge two albums (`album.mergedInto`): it becomes one `albumId` move per track, as
    `POST /admin/albums/{id}/merge` already does;
  - `GET /albums` filters: `kind`, `compilation`, `missing=artist,year,genre`;
  - `GET /admin/albums/{id}?compact=true`: a short form for a model (tracks reduced to numbers, title,
    credit and duration, raw tags kept);
  - `GET /admin/artist-lookalikes` and the MCP tool `list_artist_lookalikes`: artists spelled several
    ways ("Ska P" / "Ska-P", "Frances Cabrel" / "Francis Cabrel"), to check before proposing a merge;
  - the server reports its own version (`serverVersion` in `GET /server`, `serverInfo.version` for MCP),
    apart from the API contract version.
- MCP: raw file tags (`get_album`) are always JSON objects; an empty or numbered tag set came out as a
  list and could crash a client.
- Web player: after a beat-matched crossfade, the playback rate could stay a hair away from 1 for the
  whole track, leaving the browser's time-stretching on. It now always returns to exactly 1.
- Website: screenshots open in a viewer over the page (close with ×, Escape or a click beside; arrows
  move between screenshots) instead of the bare image. Android app links point to 1.0.0-rc.5.
- Sound test page (`/essai-son.html`): compares the direct output with several Web Audio settings,
  switchable while playing; a track can be picked by its number.

## 1.0.2 (2026-10-28)

Upgrading from 1.0.1: no database migration. Rebuild the web player. Long tracks that failed with 1.0.1
can be put back in the queue with `rengaine analyze --retry`. Capping the analyses with `MemoryMax` is
recommended (see the configuration guide, "Audio analysis").

- Audio analysis: long tracks whose file announces a wrong duration (variable bitrate MP3 without a Xing
  header) were loaded whole, up to 8.5 GB for a 3-hour mix. At most 20 minutes are now loaded, and the
  duration of a long track is measured by decoding it in blocks: an analysis stays below 1 GB.
- Audio analysis: excerpts of long tracks were read 6 dB too loud, which raised their energy a little.
- No further analysis starts in parallel while less than 1.2 GB of memory is left (machine, or the
  `MemoryMax` of the service). Documented: capping the analyses with `MemoryMax`.

## 1.0.1 (2026-10-26)

Fixes from the first large installations.

Upgrading from 1.0.0: no database migration. Rebuild the web player, then install the updated analysis
unit: `cp deploy/systemd/rengaine-analyze.service /etc/systemd/system/ && systemctl daemon-reload` (or add
`OOMPolicy=continue` to your own copy).

- Audio analysis: tracks longer than 20 minutes (mixes, DJ sets) are analysed on excerpts. Essentia used
  to need several GB of memory for them, then failed anyway.
- A killed analysis (out of memory) only puts its own track in error: `OOMPolicy=continue` in
  `rengaine-analyze.service`, and the tracks of an interrupted pass are retried one at a time, right away.
  A failing track waits for the next pass instead of using its three attempts at once.
- Jobs whose process has disappeared no longer stay "running" forever: they are marked as failed.
- File errors: no duplicate while an error is open (one line per file, not one per scan); they close by
  themselves when the file is read again, analysed, filed or removed; **Mark as resolved** in
  **Admin > Jobs** (`POST /admin/job-errors/resolve`, API contract 2.28.0).
- Web player: with the queue open on a wide screen, the page stays centred.
- Documentation: memory and local disk needed by the analysis (`[analyse] processus`).

## 1.0.0 (2026-10-25)

First public release.

- Music server: folder or S3 storage, inbox filing, incremental scans, stable identifiers.
- Audio analysis: loudness, silences, waveform; tempo, key and beat grids (Essentia); fingerprints (Chromaprint).
- Web player: queue synchronised between devices, endless mode, continuous play, volume normalisation,
  beat-matched cross-fades, on-the-fly conversion, playlists, favourites, listening history, albums as a
  grid or a list.
- Accounts: requests approved by an admin, roles (user, admin, agent), device pairing by QR code, data
  export and account deletion.
- Shared remote control through a link (Mercure).
- Library administration: genres, tag mapping, collections, reversible corrections, duplicates, damaged files.
- AI: MCP server for agents (proposals only), optional reorganisation from the admin with an API key.
- French and English interface. API contract 2.27.0.
- Installation names: `/etc/rengaine/rengaine.ini`, `rengaine-*` systemd units, `server/bin/rengaine`
  (`/etc/musique/musique.ini` is still read when the new file is absent).
