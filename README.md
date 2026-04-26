# Journeys: Automatic Photo Album Creation for Nextcloud

Automatically cluster your images into journeys (vacations/trips) and create albums for each journey.

**Requires the [Memories](https://github.com/pulsejet/memories) app.**

## ✨ Features
- **🗺️ Location & Time Clustering:** Group images by when and where they were taken
- **🗂️ Album Creation:** Albums are created automatically for each journey
- **⚙️ Customizable:** Control minimum cluster size, time gap, and distance thresholds in a modern card-based settings UI with inline auto-saving toggles
- **🎥 Video Rendering:** Render any Journey album—or a manually created Photos album—to an MP4 from the personal settings page or via OCC. Settings-page renders are queued as background jobs so the page returns instantly and the tab can be closed; the resulting file shows up in `Documents/Journeys Movies/`. Portrait videos use Ken Burns transitions and may include occasional 3‑wide landscape stacks with a short center pause. Background music is sourced at 128kbps and gently fades out at the end of the video.
- **📚 Mobile-friendly journey browser:** Compact card list with year, month and location filters, plus a `▶ Watch` shortcut on cards whose video already exists, and a separate "Rendered videos" section listing every file in `Documents/Journeys Movies/`. Built for instances where journeys (and renders) accumulate over time.
 - **⚡ Auto-generation (cron-only, >= 0.8.0):** New away-from-home albums found by the daily cron job can automatically trigger video rendering. Enable this in personal settings and choose the orientation (portrait/landscape). Rendering runs as a separate Nextcloud background job so clustering is not blocked.

## Requirements
- The Memories app must be installed and enabled.
- The Places setup in the Memories app must be completed (see Memories app documentation for details).
- The Nextcloud server must have `ffmpeg` available in `PATH` for video rendering.

## 📌 Note on which images are clustered

Journeys uses the Memories index (`oc_memories`) to determine which images are available for clustering.

This means the set of images Journeys can cluster depends on the **Memories admin settings**.

If you don’t want unexpected images from outside your configured Memories timeline folders to be included in Journeys clustering, configure:

- **Settings → Administration → Memories → Media Indexing → Index per-user timeline folders**

## 🚀 OCC Command Usage

```sh
php occ journeys:cluster-create-albums <user> [maxTimeGap] [maxDistanceKm] [--from-scratch] [--include-group-folders] [--include-shared-images] [--debug-splits] [--from <date>] [--to <date>] [--last-years <N>]
```

**Arguments:**
- `user` — The user to cluster images for (**required**)
- `maxTimeGap` — Max allowed time gap in hours (optional; if omitted, uses your Personal Settings value)
- `maxDistanceKm` — Maximum allowed distance in kilometers between consecutive images in a cluster (optional; if omitted, uses your Personal Settings value)
- `--min-cluster-size` — Minimum images per cluster (optional; if omitted, uses your Personal Settings value)
  - `--include-group-folders` — Include photos from Group Folders and other mounts in clustering (optional, default: off)
  - `--include-shared-images` — Include images available via user shares (optional, default: off). Inclusion is scoped to the shared mount root subtree (prevents pulling unrelated files from other users' storages).
  - `--from` — Only cluster images taken on/after this date/time (ISO-8601 or `YYYY-MM-DD`)
  - `--to` — Only cluster images taken on/before this date/time (ISO-8601 or `YYYY-MM-DD`)
  - `--last-years` — Only cluster images from the last `N` years (alternative to `--from/--to`)
  - `--debug-splits` — Print why clustering starts a new cluster (time/distance exceeded amounts and home-aware boundaries).

Note: Time thresholds are specified in hours and support decimals (e.g., 0.5 = 30 minutes).

**Note:** When arguments/options are omitted, the command falls back to the user's saved values from Personal Settings. The command prints the effective settings at the start of the run.

**How time gap influences clustering:**  
The `maxTimeGap` defines the largest allowed time (in hours) between two consecutive images for them to be grouped into the same journey. If the gap between two images exceeds this value, a new journey (album) is started. Smaller values create more, shorter journeys; larger values group more images together.

**Example:**
```sh
php occ journeys:cluster-create-albums admin 24 100 --min-cluster-size=5
```

### Large libraries: safe, scoped backfills

If your photo timeline goes back many years, you can limit clustering to a specific time window.

Create journeys only for the last 2 years:

```sh
php occ journeys:cluster-create-albums admin --last-years=2
```

Create journeys only for a specific time period:

```sh
php occ journeys:cluster-create-albums admin --from=2018-01-01 --to=2019-12-31
```

Notes:

- If you omit `--from/--to/--last-years`, the command keeps the current behavior (it will use incremental clustering by default).
- With incremental clustering, the effective start is the later of:
  - the latest previously created journey end
  - `--from` (if provided)

When arguments/options are omitted, the command falls back to the user's saved values from Personal Settings. The command prints the effective settings at the start of the run.

## 🎥 Video rendering (>= 0.7.2)

- Render any Journey album to an MP4 via personal settings: open **Settings → Journeys**, find the album in the list, and click **Render Video**.
- Render any manual Photos album by entering its album ID (or selecting it from the "Manual Photos albums" table) in the same settings page.
- Or use the OCC command:

  ```sh
  php occ journeys:render-cluster-video <user> <albumId>
  ```

- The rendered file is saved to `Documents/Journeys Movies/` in the user’s storage (or to a custom path when `--output` is provided).
- Each render stitches in background music selected from the bundled list (128kbps sources) and applies a short fade‑out at the end of the video.

### Automatic rendering (cron-only, >= 0.8.0)

- When enabled in personal settings, the daily cron job will enqueue a render for each newly created away-from-home album.
- Orientation honors the user preference (portrait/landscape).
- Rendering is performed by a separate Nextcloud background job.

### Landscape stacks in portrait videos (>= 0.7.4)

- When enough landscape photos are available, the renderer inserts an occasional 3‑row landscape stack into portrait videos.
- Each row slides in, pauses at the center for ~2 seconds, then slides out. Slides are a bit faster for a dynamic feel.
- Stacks require at least 3 landscape images in the selected set. By default, a stack is inserted after ~4 portrait clips (heuristic).
- Tip: use `--duration-seconds >= 2.8` to allow a full ~2s center pause plus visible in/out slides; shorter durations still work but the pause will be shorter.

### Current limitations

- Portrait mode is the default.
- Portrait and landscape rendering chunk automatically when any source image exceeds 13 megapixels; otherwise they run as a single pass.

### Roadmap

- Background music selection.
- Scene transitions and pacing controls.


## 🧵 Merging adjacent journeys (default)

After the raw clusterer runs, a post-processing pass stitches adjacent clusters that look like the same journey — specifically, clusters in the same country (via OSM admin_level=2) within 7 days of each other. This fixes two common over-splits:

- **Multi-city road trips** (e.g. Paris → Lyon → Nice) where the distance threshold trips on each inter-city leg.
- **Long vacations with rest days** where time gaps of 2–3 days without photos trip the time threshold.

Only away-from-home clusters are merged; near-home clusters never merge across gaps, because returning to the home radius is treated as ending a journey. Disable with:

- CLI: `--no-merge` flag on `journeys:cluster-create-albums`
- UI: untick "Merge adjacent journeys in the same country" in Personal Settings

## 🏠 Home-aware clustering (default)

Home-aware mode adapts clustering based on whether photos are taken near your home or away:

- Near home: uses the global time threshold and a capped distance (defaults: 24h, up to 25km)
- Away from home: uses separate, typically looser thresholds (defaults: 36h, 50km)
- The timeline is segmented into contiguous near/away blocks, and each block is clustered independently. This supports long, multi-week away trips without per-edge switching.


```sh
php occ journeys:cluster-create-albums <user> [--home-lat <lat> --home-lon <lon> --home-radius <km>] \
  [--near-time-gap <hours>] [--near-distance-km <km>] \
  [--away-time-gap <hours>] [--away-distance-km <km>]
```

Flags:

- `--home-lat`, `--home-lon` Provide home coordinates (optional; otherwise auto-detected)
- `--home-radius` Home radius in km (default: 50)
- `--near-time-gap` Near-home max time gap in hours (optional; if omitted, uses your Personal Settings value)
- `--near-distance-km` Near-home max distance in km (optional; if omitted, uses your Personal Settings value)
- `--away-time-gap` Away-from-home max time gap in hours (optional; if omitted, uses your Personal Settings value)
- `--away-distance-km` Away-from-home max distance in km (optional; if omitted, uses your Personal Settings value)

Note: Time thresholds use hours and accept decimals (e.g., 1.5 = 1 hour 30 minutes).

Notes:

- If near-home thresholds are left at their defaults, the time gap aligns to the global `maxTimeGap`, and the distance aligns to the global `maxDistanceKm` but is capped at 25km for finer local clustering.
- For long away trips with multi-day gaps in the same place, consider increasing `--away-time-gap` (e.g., 72–168 hours).
- In home-aware mode, there is no post-processing merge; clusters are used as produced per segment for predictability.

### Incremental clustering (default)

- By default, clustering runs incrementally: it only considers images taken after the latest previously created cluster. This keeps runtime low and avoids recreating existing albums.
- Use `--from-scratch` to purge previously created cluster albums and recluster all images from a clean slate.
- To avoid creating incomplete trips, clusters whose last image is within the past 48 hours are skipped (configurable via `--recent-cutoff-days`); they will be picked up in a future run once the trip is likely complete.

## 🔔 Notifications (>= 0.5.4)

- After each run that creates one or more albums, the app sends a single aggregated notification to the user with a short summary of the created albums.
- The notification contains an "Open Photos" action that links to the Photos app so you can review the albums quickly.
- Notifications may appear with a short delay due to client polling.


## 🧭 Clustering robustness (>= 4.0.3)

- The clusterer now prevents time-only tails (images without coordinates) from bridging over large spatial jumps.
- Distance checks are anchored to the last-known geolocated photo within the current cluster, so a run of no-location images won’t “stitch” a far-away next geolocated point into the same cluster.
- This improves results for long trips where some photos are missing GPS data, especially in home-aware “away” segments.



## ⬆️ Upgrade notes

- Update the app to apply DB migrations:
  ```sh
  php occ app:update journeys
  # if prompted that an upgrade is required, run:
  php occ upgrade
  ```
- After updating, re-run the cluster command. To reset old albums once, you can run:
  ```sh
  php occ journeys:remove-all-albums <user>
  ```

