# Journeys: Automatic Photo Album Creation for Nextcloud

Automatically cluster your images into journeys (vacations/trips) and create albums for each journey.

**Requires the [Memories](https://github.com/pulsejet/memories) app.**

## ✨ Features
- **🗺️ Location & Time Clustering:** Group images by when and where they were taken
- **🗂️ Album Creation:** Albums are created automatically for each journey
- **⚙️ Customizable:** Control minimum cluster size, time gap, and distance thresholds
- **🎥 Video Rendering:** Render any Journey album to an MP4 via personal settings or OCC command. Portrait videos use Ken Burns transitions and may include occasional 3‑wide landscape stacks with a short center pause. Background music is sourced at 128kbps and gently fades out at the end.
 - **⚡ Auto-generation (cron-only, >= 0.8.0):** New away-from-home albums found by the daily cron job can automatically trigger video rendering. Enable this in personal settings and choose the orientation (portrait/landscape). Rendering runs as a separate Nextcloud background job so clustering is not blocked.

## Requirements
- The Memories app must be installed and enabled.
- The Places setup in the Memories app must be completed (see Memories app documentation for details).
- The Nextcloud server must have `ffmpeg` available in `PATH` for video rendering.

## 🚀 OCC Command Usage

```sh
php occ journeys:cluster-create-albums <user> [maxTimeGap] [maxDistanceKm] [--from-scratch] [--include-group-folders]
```

**Arguments:**
- `user` — The user to cluster images for (**required**)
- `maxTimeGap` — Max allowed time gap in hours (optional, default: 24)
- `maxDistanceKm` — Maximum allowed distance in kilometers between consecutive images in a cluster (default: 50.0)
- `--min-cluster-size` — Minimum images per cluster (optional, default: 5)
 - `--include-group-folders` — Include photos from Group Folders and other mounts in clustering (optional, default: off)

**How time gap influences clustering:**  
The `maxTimeGap` defines the largest allowed time (in hours) between two consecutive images for them to be grouped into the same journey. If the gap between two images exceeds this value, a new journey (album) is started. Smaller values create more, shorter journeys; larger values group more images together.

**Example:**
```sh
php occ journeys:cluster-create-albums admin 24 100 --min-cluster-size=5
```

## 🎥 Video rendering (>= 0.7.2)

- Render any Journey album to an MP4 via personal settings: open **Settings → Journeys**, find the album in the list, and click **Render Video**.
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

### Roadmap

- Background music selection.
- Scene transitions and pacing controls.


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
- `--near-time-gap` Near-home max time gap in hours (default: 6)
- `--near-distance-km` Near-home max distance in km (default: 3)
- `--away-time-gap` Away-from-home max time gap in hours (default: 36)
- `--away-distance-km` Away-from-home max distance in km (default: 50)

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

