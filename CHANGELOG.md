# Changelog

All notable changes are listed here. The API contract has its own version (`info.version` in
`api/openapi.yaml`, reported by `GET /api/v2/server`).

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
