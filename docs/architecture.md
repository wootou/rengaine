# Architecture

```
  Storage (folder or S3)  ── audio and image bytes
        │                    ▲
        │ list, ffprobe,     │ one track or one image at a time
        │ analysis           │
        ▼                    │
  Background jobs (systemd) ──▶ MariaDB ◀── API (PHP-FPM) ◀── web player (React)
  indexer, analyser, jobs                       ▲         ◀── Android app
                                                └──────── ◀── AI agents (MCP)
```

**No web request walks the storage.** Browsing, search, shuffle and genres all come from MariaDB; the
storage is only read for the track being played or an image. A scan requested from the admin only queues
a job that a systemd timer runs.

## Server

- PHP 8, no external library. One entry point (`server/public/index.php`), a small router, controllers
  per area (`server/src/Api/Controllers`), plain SQL through PDO.
- **The contract comes first**: `api/openapi.yaml` describes every operation, schema, role and error
  code. A test checks that the routing table and the contract match, and API tests validate every
  response against it. Web types are generated from it.
- Storage: one interface, two drivers (local folder, S3 with Signature V4); the same test suite runs
  against both.
- Catalogue: columns hold the *effective* value (what the indexer read, overlaid by active corrections).
  A scan never overwrites a correction; reverting one restores the previous value.
- Stable identifiers: unchanged files are not re-read; renamed or moved files keep their track; missing
  files are marked, not deleted. Favourites and history survive.
- Analysis: workers take items from a queue with `FOR UPDATE SKIP LOCKED`; ffmpeg measures loudness
  (EBU R128), silences and a waveform; Essentia gives tempo, key and beat grids at the start and end of
  each track; Chromaprint gives fingerprints.
- On-the-fly conversion to Opus or MP3 with ffmpeg, cached; seeking in a conversion still in progress
  restarts it at the requested position.
- Real time (shared remote control): the API publishes to a Mercure hub; browsers subscribe with
  short-lived tokens limited to their topics. A guest never writes the queue: the server checks a command
  and relays it to the player that is playing, which applies and saves it.

## Security

- Passwords: argon2id. Web sessions: HttpOnly, Secure, SameSite=Strict cookie plus an anti-CSRF header.
  Apps and agents: bearer tokens, one per device, only their hash is stored.
- Deny by default: a route without explicit roles is for users and admins; agents only get the routes
  that name them.
- Sign-in failures are counted per account and per network; unknown accounts and wrong passwords get
  the same answer in the same time.
- Rate limits per IPv4 address or IPv6 /64 (APCu). A PHP warning never becomes a 500; every error has a
  stable code and a request id.
- Logs are JSON lines; IPs are erased after a configurable delay; users can export or delete their data.

## Web player

React 19 in plain JavaScript (type-checked with `// @ts-check`), one stylesheet, no CSS framework, no
CDN. Two audio elements cross-fade through Web Audio; gain comes from the server's loudness measures. The
interface is translated by using the French text as the key (`web/src/i18n/en.js`); a test fails if a
text has no translation.
