# Changelog

All notable changes are listed here. The API contract has its own version (`info.version` in
`api/openapi.yaml`, reported by `GET /api/v2/server`).

## 1.0.4 (2026-11-03)

Upgrading from 1.0.3: database migration 0020 (`rengaine db:migrate`). Rebuild
the web player. Run `rengaine index --force` once so that titles stored with HTML entities (`&amp;`) are
decoded (it reads every file again: long on a large library, run it at a quiet time). Covers found online stay off unless `[pochettes] recherche_en_ligne = 1` is set.

**Breaking change for MCP clients:** the MCP endpoint (`/api/v2/mcp`) now only accepts **agent** tokens. A
client that connected with an admin token gets `403`: before upgrading, create an agent account in
**Admin > Accounts** (no email needed), give it a token and use that one.

- AI permissions per function (API contract 2.31.0): each agent token has one switch per function (read the
  library, analyse it, see the inbox and errors, propose tag corrections, propose structural changes,
  propose a known cover, covers found online, and under it "known sites only", ticked by default: unticked,
  with a warning, a proposed cover may come from any https site while every other check stays), set in
  **Admin > Accounts** and applied at the next request.
  The server checks them on every request made with the token, through MCP or directly on the API
  (`403 capability_disabled`); a disabled tool is not listed. **Existing agent tokens keep what they could
  do (everything except covers found online), including "see the inbox and errors" and "structural changes",
  which are off for new tokens: check their switches after upgrading.** A proposed change whose field is not
  declared for agents is refused. An agent token stops working if its account is given another role. `PATCH /admin/tokens/{id}`, `GET /admin/capabilities`; an agent
  reads its own in `GET /me`. Changes are in the audit log.
- An agent account no longer needs an email address (`rengaine user:create - "<name>" --role agent` on the
  command line). `email` can be `null` for such an account.

- Admin API and MCP: the artist lookalikes list (`GET /admin/artist-lookalikes`, `list_artist_lookalikes`)
  failed with an error 500 when an artist's name is made only of digits ("1975").
- After a second tidy-up by an AI (API contract 2.30.0):
  - tracks tell where their artist credit comes from (`artistCreditSource`: tag, artists, album, none); a track
    with no known artist was shown as "Unknown artist" with nothing to tell it from a real tag;
  - `GET /tracks` filters: `missing=artist,genre`, `albumKind`, `compilation`;
  - `GET /admin/duplicate-albums` and the MCP tool `list_duplicate_albums`: albums present in two folders;
  - a correction can remove an album (`removed`, for a duplicate copy): hidden with its tracks, files left in
    place, back when the correction is reverted;
  - `GET /admin/title-credit-suggestions` and `suggest_credits_from_titles`: artist and title read from
    "Artist - Title" track titles, cleaned, with swapped ones detected;
  - covers found online (off by default, `[pochettes] recherche_en_ligne = 1`): candidates from iTunes, Deezer
    and Cover Art Archive (`find_cover`), and a cover proposed by its link (`coverUrl`), downloaded and
    checked when proposed, shown in Admin > Proposals; `GET /albums?missing=cover`;
  - HTML entities left in tags (`&amp;`) are decoded;
  - a proposal batch can hold up to 5,000 changes (was 2,000).

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
