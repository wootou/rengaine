# Rengaine

**English** | [Français](README.fr.md)

**Your music library, self-hosted.** Rengaine is a music server and web player for the albums you own: point it at a folder (or an S3 bucket) of MP3 and FLAC files, and listen from any browser or from the Android app, at home or on the go.

🌐 **Website and documentation: [rengaine.org](https://rengaine.org)**

**Status:** version 1.0.1 (see [CHANGELOG.md](CHANGELOG.md)). **Android app:** source and APK at [rengaine-android](https://github.com/wootou/rengaine-android/releases), test version 1.0.0-rc.1.


<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://rengaine.org/images/en/albums-dark.webp">
  <img alt="Album library in Rengaine" src="https://rengaine.org/images/en/albums-light.webp">
</picture>


## Features

- **Your files, untouched.** Tags are read, never rewritten; new albums are filed automatically.
- **Sound that flows.** Loudness normalisation, silences skipped, beat-matched cross-fades.
- **Endless listening.** By genre, mood or decade, with tracks that follow well.
- **The whole household.** Several accounts, synced between the web player and the Android app.
- **Shared remote control.** One link lets a guest control the music from their phone.
- **A tidy library.** Genres, reversible corrections, duplicate detection.
- **AI-assisted, never AI-driven.** An AI proposes corrections; you decide.
- **Respectful by design.** No tracker; your data stays with you.

## Preview

<table>
  <tr>
    <td>
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://rengaine.org/images/en/album-dark.webp">
        <img alt="Album page" src="https://rengaine.org/images/en/album-light.webp">
      </picture>
    </td>
    <td>
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://rengaine.org/images/en/explore-dark.webp">
        <img alt="Explore: moods and genres" src="https://rengaine.org/images/en/explore-light.webp">
      </picture>
    </td>
  </tr>
</table>

## Requirements

A Linux server with PHP 8 (PHP-FPM), MariaDB, Apache (or Nginx) and FFmpeg. Optional: Essentia (tempo, key), Chromaprint (fingerprints), Mercure (real-time remote control), an SMTP relay. No Composer, no Docker required.

## Getting started

Read the installation guide, then configure the server and add your music. All documentation is in `docs/`; the API is described by an OpenAPI contract in `api/openapi.yaml`.

## Contributing

Bug reports and pull requests are welcome: see CONTRIBUTING.md. To report a security issue privately, see SECURITY.md.

## License

MIT. Third-party components and the separate programs Rengaine can use are listed in THIRD\_PARTY\_NOTICES.md.
