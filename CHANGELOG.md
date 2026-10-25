# Changelog

All notable changes are listed here. The API contract has its own version (`info.version` in
`api/openapi.yaml`, reported by `GET /api/v2/server`).

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
