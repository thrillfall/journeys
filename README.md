# Journeys: Automatic Photo Album Creation for Nextcloud

Automatically clusters your photos by time and location into journeys (trips, vacations) and creates a Photos album for each. Only photos inside your Memories timeline path are used — folders you keep out of Memories stay out of Journeys.

## ✨ Features

- **Journey albums:** created daily in the background (opt-in), named after place and dates, e.g. `Iceland — Aug 12–19, 2024`. Names can be overridden in personal settings and survive reclustering. A notification tells you when new albums appear.
- **Home-aware clustering:** local days and trips are clustered with separate thresholds; adjacent trips in the same country within a week are merged, so road trips and long vacations stay one journey.
- **Journals:** a travel diary with day entries auto-filled from your photos. Share with users or groups for co-editing, or publish to a revocable public link with route map and travel stats.
- **Videos:** render any journey or Photos album into an MP4 highlight reel, manually or automatically for new trips. Saved to `Documents/Journeys Movies/`.

## Requirements

- [Memories](https://github.com/pulsejet/memories) with Places set up. Journeys reads the Memories index, so only indexed photos can be clustered.
- [Photos](https://github.com/nextcloud/photos) — albums are created through it. If Photos is disabled, `occ` fails with `Could not resolve OCA\Photos\Album\AlbumMapper`.
- `ffmpeg` 7.1+ in `PATH` for video rendering.

## 🚀 OCC commands

```sh
php occ journeys:cluster-create-albums <user> [maxTimeGap] [maxDistanceKm] [options]
php occ journeys:render-cluster-video <user> <albumId>
php occ journeys:render-cluster-video-landscape <user> <albumId>
php occ journeys:list-clusters <user>
php occ journeys:remove-all-albums <user>    # only albums created by Journeys
```

Clustering runs incrementally: only photos after the last created journey are considered. Omitted arguments fall back to the user's personal settings; the effective values are printed at the start of the run.

Common options for `cluster-create-albums`:

| Option | Effect |
|---|---|
| `--from-scratch` | Delete Journeys albums and recluster everything |
| `--from`, `--to` / `--last-years N` | Limit to a time window (useful for large libraries) |
| `--include-group-folders`, `--include-shared-images` | Also cluster Group Folders / incoming shares |
| `--min-cluster-size N` | Minimum photos per journey |
| `--home-lat`, `--home-lon`, `--home-radius` | Set home instead of auto-detecting it |
| `--near-time-gap`, `--near-distance-km`, `--away-time-gap`, `--away-distance-km` | Thresholds near / away from home (hours, km) |
| `--no-merge`, `--no-home-aware` | Disable merging / home-aware mode |
| `--recent-cutoff-days N` | Skip journeys ending in the last N days (default 2) |
| `--debug-splits` | Explain why each journey was split |

## ⬆️ Upgrading

```sh
php occ app:update journeys
```

Full history: [CHANGELOG.md](CHANGELOG.md)
