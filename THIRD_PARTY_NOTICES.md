# Third-party notices

Rengaine is released under the MIT License (see `LICENSE`). It includes or relies on the following
third-party work.

## Included in the web player

| Component | License | Use |
|---|---|---|
| [React](https://react.dev/) and React DOM | MIT | User interface |
| [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) | MIT | Pairing QR code |
| [Material Icons](https://github.com/google/material-design-icons) (Google) | Apache License 2.0 | Several icon paths in `web/src/components/Icon.jsx` are taken from or adapted from Material Icons |
| [Bricolage Grotesque](https://github.com/ateliertriay/bricolage) (The Bricolage Grotesque Project Authors) | SIL Open Font License 1.1 | Typeface of the web player (`web/src/fonts/`, license in `web/src/fonts/OFL.txt`) |

Build and test tools (Vite, Vitest, Playwright, TypeScript, Redocly, openapi-typescript…) are only used
during development; their licenses are listed in `web/package-lock.json`.

## Separate programs (not included)

Rengaine calls these programs as separate processes or over HTTP. They are not part of Rengaine and keep
their own licenses; installing them is optional unless stated.

| Program | License | Use |
|---|---|---|
| PHP, MariaDB, Apache or Nginx | PHP License, GPL-2.0, Apache-2.0 / BSD-2-Clause | Required runtime |
| [FFmpeg](https://ffmpeg.org/) (ffmpeg, ffprobe) | LGPL-2.1+ or GPL-2.0+ depending on the build | Required: reading tags, loudness, on-the-fly conversion |
| [Essentia](https://essentia.upf.edu/) | AGPL-3.0 | Optional: tempo, key and beat analysis |
| [Chromaprint](https://acoustid.org/chromaprint) (fpcalc) | LGPL-2.1+ (MIT for parts) | Optional: acoustic fingerprints |
| [Mercure](https://mercure.rocks/) | AGPL-3.0 | Optional: real-time hub for the shared remote control |
