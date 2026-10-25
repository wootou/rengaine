# Using Rengaine

Rengaine is a web player (any modern browser, phone included) and an Android app, both talking to your
server. Every account has its own queue, favourites, playlists, history and settings, synchronised
between devices.

## Browsing and playing

- **Albums**, **Artists**: the whole library, sorted and filtered (genre, decade, lossless only…). Albums show
  as a grid of covers or as a list (cover, artist, year, tracks, length): the button next to the title
  switches, and each device remembers its choice.
- **Explore**: genres and sub-genres, moods (Calm, Focus, Energetic, Dance, computed from the audio
  analysis, one tile each: its play button starts it), decades and collections; each one can start an
  endless shuffle.
- **Shuffle**: a fresh random selection, by genre, with albums you can leave out.
- **Search**: as you type, insensitive to accents.
- **Playlists**: private by default, or shared read-only with every account (others can copy them).
- **Favourites**: tracks, albums and artists marked with a heart. **Listening history** is linked from
  there; you can delete one play or your whole history.

## The queue

Playing an album, a playlist or search results replaces the queue; "Play next" and "Add to queue" keep
it. The queue survives page reloads and follows you from one device to another.

- **Endless mode**: when the queue is about to end, it fills itself with tracks of the same genre (or the
  chosen mood, decade or collection).
- **Continuous play** (Settings): the server picks tracks that flow well after the last one (key, tempo,
  favourites, no recent repeats, artists spaced out).

## Sound

- **Volume normalisation**: every track is measured; pick a target loudness, per track or per album.
- **Transitions**: cross-fade length, silences skipped, and on tracks with a beat grid, a fade that lasts
  whole bars and lines up the beats, briefly matching the tempo.
- **Stream quality**: the original file (lossless when available) or a lighter Opus/MP3 version
  converted on the fly, with separate choices for wifi and mobile data.

## Devices and the Android app

**Settings > Connected devices** lists every browser and app signed in to your account; revoke any of
them. To connect the Android app, tap **Pair a device**: scan the QR code from the app, or type the code
and the server address.

## Shared remote control

**Settings > Shared remote control** (or "Share the queue" in the queue panel) creates a link valid from
one hour to a week, with the rights you choose: add tracks, reorder and remove, play/pause/skip, volume.
A guest opens it on their phone without an account, enters a first name, and steers the player that is
playing. Revoke the link at any time; the admin can switch the feature off for the whole server.

## Your data

**Settings > Account**: change your name or password, download everything the server keeps about you
(JSON), delete your listening history, delete your account (immediate and complete).
