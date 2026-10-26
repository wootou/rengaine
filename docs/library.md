# Your music library

## Where the files go

Rengaine reads music from a folder or an S3 bucket (`[stockage]`). It never modifies a music file. It
recognises MP3, FLAC, M4A/AAC, Ogg/Opus, WAV, AIFF, WMA, APE and WavPack.

**One folder is one album.** Disc sub-folders (`CD1/`, `Disc 2/`) and format sub-folders (`flac/`,
`mp3/`) belong to their parent. Title, artist and year come from a majority vote over the tags of the
album's tracks, and from the folder name (`Artist - Album`) when tags are missing. A folder whose tracks
do not share an album becomes a "loose" folder: its tracks are still searchable and playable.

When the same track exists as MP3 and FLAC in one album, it is a single track with two files: the
lossless one is played, and **Admin > Duplicates** can move the redundant MP3s to a recycle folder.

Covers: `cover`, `folder` or `front` images first, then any single image of the folder, then the image
embedded in the files.

## The inbox

Drop a new album into `inbox/` at the root of the storage (copy, rsync, rclone, a synced folder…). Once
nothing has changed in that folder for 10 minutes, the next scan files it under a clean name taken from
its tags:

```
Artist/Album (year)/01 - Title.flac
Artist/Album (year)/CD2/01 - Title.flac
Compilations/Album (year)/01 - Artist - Title.mp3
```

Nothing is ever overwritten. Doubtful cases (several albums mixed, missing artist, file already present)
stay in `inbox/` with their reason, shown in **Admin > Jobs**. Preview without moving anything:
`rengaine inbox --essai`.

### Why an album stays in `inbox/`

| Reason shown | What to do |
|---|---|
| Artist not found (no “artist” tag) | Fill in the tag, or name the folder “Artist - Album”. |
| No audio file in this folder | It only holds a cover or extra files: delete it, or add the tracks. |
| The tracks belong to several albums | Drop one folder per album. For a selection of tracks taken from several albums, name the folder “VA - Name” (or “Various Artists - Name”, “Compilation - Name”…): it is filed as is under `Compilations/`. |
| A file already has this name in the library | The album is probably already there: check, then delete the duplicate. |

### Large imports

Rengaine files complete albums while the upload goes on. A tool that restarts a full pass after any error
can then send files that were already filed: rclone, for instance, starts the whole copy again (3 times by
default) when a file that left `inbox/` has vanished in the meantime. For an import of tens of GB, either:

- run `rclone copy --retries 1`;
- upload into another folder (for instance `import/`), then move it to `inbox/` once the upload is done;
- stop filing during the import (`systemctl stop rengaine-index.timer`), then start it again
  (`systemctl start rengaine-index.timer`).

Then run `rengaine inbox --essai` to see what will be filed.

## Scans

`rengaine-index.timer` scans every 15 minutes, and only reads what changed (size, date, ETag). A renamed
or moved file keeps its track, its favourites and its history (recognised by size and SHA-256). A
missing file is marked, not deleted. Start a scan from **Admin > Jobs** or with `rengaine index`
(`--force` re-reads everything).

## Analysis

Each new track is analysed in the background: loudness (EBU R128, for volume normalisation), leading
and trailing silences, a waveform, and with the optional tools, tempo, key, beat grids, energy (Essentia)
and an acoustic fingerprint (Chromaprint). The player uses these to normalise volume, skip silences,
cross-fade on the beat, and to offer "DJ" filters, moods and smart continuous play.

Files with unreadable frames are listed in **Admin > Duplicates > Damaged files**, with where the
glitches are.

## Genres

A genre is never created by a tag alone. Every genre value found in tags is recorded; if it matches an
existing genre (ignoring case and accents) it is attached automatically, otherwise it waits in
**Admin > Tags** until you map it ("Hip-Hop/Rap" → "Rap"). Genres can have sub-genres. Albums take the
genres chosen for them, or else the majority of their tracks' tags; tracks inherit their album's genres
unless they have their own (compilations).

## Corrections

Fix a title, a year, an album artist, genres, a cover, split or merge albums, rename or merge artists,
from the album or artist page (**Edit**) or the admin. Every change is a correction: kept with its
author and previous value, re-applied after every scan, and reversible from **Admin > Corrections**.
An AI can propose batches of corrections that you apply or reject (see [AI](ai.md)).
