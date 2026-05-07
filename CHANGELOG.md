# Changelog

All notable changes to this project will be documented in this file.

## [0.24.0] - 2026-05-07
### Added
- Settings: user-editable journey names. Each journey card has a pencil button that opens a modal to set a custom name (e.g. "Christmas 2024", "Family reunion", "Sabbatical") on top of the auto-derived location/date title. The custom name becomes the card heading, the Photos album title, and the video title overlay; the auto-derived name is kept as a smaller secondary line and remains the source of truth in `oc_journeys_cluster_albums.name`.
- Clustering: custom journey names survive `--from-scratch` reclustering. Before purge, `AlbumCreator::getCustomNameSnapshot` captures each named album's file IDs; after rebuilding, `CustomNameReassigner` greedily attaches each old name to the new album with the highest file-ID Jaccard overlap (≥ 0.5). Names whose best match falls below threshold are dropped. Robust against algorithm changes, place-resolver improvements, and retroactive photo additions that shift cluster boundaries.
- DB migration: new nullable `custom_name` column on `oc_journeys_cluster_albums` (Version0404Date20260507).
- API: `POST /apps/journeys/personal_settings/update_cluster_name` accepts `{albumId, customName}`. Empty `customName` clears the override and restores the Photos album title to the auto-derived name.

## [0.23.6] - 2026-05-07
### Added
- Clustering: tracked journey albums whose photos have all been deleted are now removed automatically on the next clustering run (daily cron, OCC, or settings UI). New `AlbumCreator::pruneEmptyClusterAlbums` runs ahead of fetching/clustering so it executes even on days with no new photos. Stale tracking rows pointing at albums that were deleted externally are cleaned at the same time. Manual albums and tracked albums that still hold photos are untouched. Closes #24.

## [0.23.5] - 2026-05-06
### Added
- Video rendering: per-location subtitles now also overlay portrait videos (previously landscape-only). Reuses the `showLocationSubtitles` toggle and the same group/5s-cap/single-location-suppression behavior.

## [0.23.4] - 2026-05-06
### Added
- Video rendering: landscape videos now overlay per-location subtitles that fade in/out per location group (capped at 5s). Suppressed automatically when the journey resolves to a single place. New `showLocationSubtitles` user setting (default on).

## [0.23.3] - 2026-05-05
### Fixed
- Video rendering: landscape chunked merge no longer collapses output to a fraction of expected length. `ClusterVideoRendererLandscape::mergeChunks` now probes each chunk's actual on-disk duration before computing cumulative `xfade` offsets; previously it trusted the renderer's formula-based `holdDuration*N + transition` figure, which overstated chunks containing motion-photo segments shorter than their nominal slot. The inflated offsets pushed `xfade` past the end of the running merged stream and ffmpeg silently dropped subsequent chunks. Reproduced on a 96-image / 8-chunk cluster: output went from 57s to 225s (expected). Different layer than the 0.23.2 orientation-filter fix that targeted the same symptom.

## [0.23.2] - 2026-04-28
### Fixed
- Video rendering: landscape videos are no longer truncated. Image selection for the landscape renderer now pre-filters to landscape-orientation candidates instead of inheriting the portrait-biased mix from the user's `videoOrientation` setting. Previously a 120-image selection from a multi-week journey produced a ~1-minute landscape video because `ClusterVideoRendererLandscape::filterLandscapeFiles` dropped every portrait frame after sampling, leaving only ~30–40 usable inputs.

## [0.23.1] - 2026-04-27
### Fixed
- Video rendering: always chunk renders and pick chunk size from the largest source image (16 / 12 / 8 / 4 segments per chunk for ≤8 / ≤16 / ≤32 / >32 MP). The previous "render in one pass unless any input >13 MP" gate let typical phone-camera albums (12 MP) feed 60+ simultaneous `-loop 1 -i image.jpg` decoders to ffmpeg, which kept enough raw frame buffers resident to OOM-kill the renderer mid-album. Each chunk now runs as its own ffmpeg process so peak RSS is released between chunks. Verified end-to-end on an 88-image cluster.

## [0.23.0] - 2026-04-26
### Added
- Settings: filter toolbar above the journeys list — year, month, and free-text location/name search with a result counter and a "Clear" reset, so instances with many journeys stay manageable.
- Settings: new "Rendered videos" section lists every `.mp4` in `Documents/Journeys Movies/` (mtime, size, "Open in Files" deep link).
- Settings: per-journey `▶ Watch` badge appears when a rendered video exists for the cluster and links straight to the file in the Files app.
- Backend: new `GET /personal_settings/rendered_videos` endpoint backed by a `RenderedVideoLister` service; `listClusters` now returns `hasVideo`, `videoFileId`, `videoName` per cluster.

### Changed
- Settings UI: rebuilt the journeys list as a compact, mobile-friendly card grid (replacing the previous HTML tables). Each card shows name, dates, photo count and place on a single line; render buttons sit side-by-side and stay legible at narrow widths.
- Settings: render endpoints (`render_cluster_video`, `render_cluster_video_landscape`) now enqueue a `RenderClusterVideoJob` instead of running ffmpeg synchronously inside the HTTP request. The page returns instantly, the user no longer has to keep the tab open through a multi-minute render, and behavior matches the existing daily-cron auto-render path.
- Settings: render-button labels shortened to `Portrait` / `Landscape` so cards stay compact; the full action ("Re-render landscape video", etc.) lives in the button's title tooltip.

## [0.22.4] - 2026-04-26
### Fixed
- Video rendering: cap ffmpeg's decoder, filter, and encoder thread pools to 2 each (`-threads 2 -filter_threads 2 -filter_complex_threads 2`) to bound peak memory in `zoompan`/`xfade` filter graphs. With ffmpeg's default of `nproc`, concurrent full-resolution frame buffers had OOM-killed or frozen multi-core servers rendering large albums.

## [0.22.3] - 2026-04-25
### Changed
- Video rendering: per-cluster image cap now scales with trip length so multi-week journeys produce longer recap videos. The previous fixed default of 80 images stays in effect for trips up to a week, then climbs by 4 images per extra day up to an absolute cap of 120 (≈3:30 at the default 2.5 s per image). The `--max-images` CLI flag still acts as an explicit override.

## [0.22.2] - 2026-04-23
### Changed
- Clustering: merge pass now absorbs tiny GPS-noise clusters (below `minClusterSize`) that sit between two same-country clusters. Fixes cases where a single-photo cluster with a device-default coordinate (e.g. a spurious Hong Kong fix during a New Zealand trip) blocked adjacent legs from being stitched together. The noise image is preserved in the merged cluster, not dropped.
- Clustering debug: `--debug-splits` now prints `MERGE (through noise)` events with the absorbed cluster's size, fileid, datetime, and coordinates.

## [0.22.1] - 2026-04-23
### Changed
- Clustering debug: `--debug-splits` now emits `NO MERGE` events when the merge pass rejects a pair on country grounds (null country on either side, or name mismatch). Includes resolved country strings, image coordinates, and fileids for the cluster boundary. Helps diagnose cases where adjacent clusters in the same country are not being stitched (e.g. Memories Places index missing admin_level=2 for a coastal area).

## [0.22.0] - 2026-04-23
### Changed
- Compatibility: declare support for Nextcloud 33 (`max-version` bumped from 32 to 33). Verified clean of APIs removed in NC 33 (`IJob::execute`, `IQueryBuilder::execute`, `Files::buildNotExistingFileName`, legacy Search provider classes).

## [0.21.0] - 2026-04-22
### Added
- Clustering: new post-clustering merge pass that stitches adjacent clusters in the same country within 7 days, fixing over-splitting of multi-city road trips and long vacations with photo-less rest days. Away-from-home clusters only; near-home clusters never merge.
- Settings: new "Merge adjacent journeys in the same country (within one week)" toggle in Personal Settings (default on).
- CLI: new `--no-merge` flag on `journeys:cluster-create-albums` to disable the merge pass for debugging.
- CLI: `--debug-splits` now prints `MERGE (same country)` events when adjacent clusters are merged.

## [0.20.3] - 2026-04-22
### Changed
- Video rendering: extracted shared primitives from portrait and landscape renderers into a new `VideoRenderPrimitives` trait (~350 LOC duplication removed). No behavior change; both orientations verified end-to-end.

## [0.20.2] - 2026-02-13
### Changed
- Cron: Reduced `recentCutoffDays` from 5 to 2 in the daily clustering job to align with the OCC command default.

## [0.20.1] - 2026-02-13
### Fixed
- DI: Moved `VideoRenderJobScheduler` service definition inside the XML structure in `services.xml` so auto-generated video rendering jobs are correctly scheduled by the daily clustering cron.

## [0.20.0] - 2026-02-10
### Added
- Clustering: optional date-range scoping to limit which photos are clustered.
- Personal Settings: set an optional "Only cluster from/to" range; daily cron honors it.
- CLI: `--from`, `--to`, and `--last-years` options for `journeys:cluster-create-albums`.

### Changed
- CLI: when no date range is provided via CLI flags, the command falls back to the user's configured UI date range.

## [0.19.6] - 2026-01-05
### Changed
- Cron: Daily clustering job now logs “no images found for user” as info instead of warning.

## [0.19.5] - 2025-12-18
### Fixed
- Clustering: when `--include-shared-images` is enabled, only include images from incoming shares (exclude outbound shares shared by the user).

## [0.19.4] - 2025-12-18
### Fixed
- Clustering: exclude generated videos in `Documents/Journeys Movies/` from clustering input.
- Clustering: more robust timestamp parsing & deterministic sorting to avoid spurious splits when including shared images.

### Changed
- CLI: `--debug-splits` output now includes raw boundary indices plus source (`home/shared`) and location context (`loc=...`, `prevGeo`).

## [0.19.3] - 2025-12-17
### Added
- CLI: `--debug-splits` for `journeys:cluster-create-albums` prints why clustering starts a new cluster (time/distance exceeded amounts and home-aware boundaries).

## [0.19.2] - 2025-12-17
### Fixed
- Clustering: shared image inclusion is now scoped to the shared mount root subtree (prevents pulling unrelated files from other users' storages).

## [0.19.1] - 2025-12-16
### Added
- Clustering debug: When clustering with shared images enabled, the OCC output prints which shared images were included per created cluster (fileid, path, datetaken, datetaken_ts).

## [0.19.0] - 2025-12-16
### Changed
- Video rendering: verified compatibility with FFmpeg 7.x (CFR handling, fades); recommend FFmpeg 7.1+.
- Both orientations end on a still frame when available to ensure reliable fade-outs even after motion clips.
- Landscape retains chunking/logging parity with portrait.

## [0.17.2] - 2025-12-05
### Added
- Clustering OCC command streams cluster creation details immediately as albums are generated.

## [0.17.1] - 2025-12-04
### Fixed
- Clustering now skips screenshot-style images (missing EXIF camera metadata, PNG/WebP, screen keywords) so they no longer appear in albums or videos.

## [0.17.0] - 2025-12-04
### Changed
- Video rendering now prefers photos with faces for video generation
### Fixed
- Personal Settings: "Prefer photos with people when building videos" checkbox now persists its saved value after page reloads.

## [0.16.0] - 2025-11-28
### Added
- Personal Settings: new "Include shared images" toggle now includes shared mounts in clustering and video workflows.
- CLI: `journeys:cluster` prints per-source image counts (home, group folders, shared) for easier debugging.

### Changed
- Surface a warning when shared inclusion yields no matches so users can verify share visibility.

## [0.15.1] - 2025-11-24
### Changed
- Place resolution now automatically falls back to Memories tables when GIS functions are unavailable or fail.

### Upgrade Notes
- Drop existing Journeys albums and re-run clustering to rebuild album titles with the new fallback behavior.

## [0.15.0] - 2025-11-24
### Added
- Video selection now prefers photos with faces when Recognize data is available, improving story highlights.
- Personal Settings: "Prefer photos with people when building videos" checkbox (enabled by default) to control face boosting.
- CLI: `--no-face-boost` flag for both `journeys:render-cluster-video` commands to disable face boosting per run.

## [0.14.0] - 2025-11-20
### Added
- Video title overlay: Videos now display the cluster name on the first image for 4 seconds with smooth fade in/out animation.
- Font size automatically scales to use 80% of video width with intelligent text wrapping at word boundaries and dashes.
- Personal Settings: "Show cluster name title on videos" checkbox to toggle title display (enabled by default).
- CLI: `--no-title` flag for both `journeys:render-cluster-video` and `journeys:render-cluster-video-landscape` commands to disable title overlay.

## [0.13.2] - 2025-11-20
### Fixed
- Video rendering no longer hangs when processing GCam motion clips. Fixed frame padding calculation to use exact frame counts instead of time-based duration.

### Changed
- Rendered videos now always include timestamp suffix (YYYYMMDD-HHMMSS) in filename to avoid file conflicts.

## [0.13.1] - 2025-11-19
### Added
- CLI: `--ffmpeg-verbose` flag for `journeys:render-cluster-video` and `journeys:render-cluster-video-landscape` commands to enable detailed FFmpeg output for debugging rendering issues.

## [0.13.0] - 2025-11-19
### Fixed
- Video rendering: Ken Burns effect now works correctly on still images that follow GCam motion videos in both portrait and landscape renderers. Fixed PTS (Presentation TimeStamp) normalization that was causing static images after motion clips.

## [0.12.1] - 2025-11-19
### Fixed
- `journeys:remove-all-albums` command now only removes Journeys-created albums instead of all user albums. Manually-created albums are now preserved.

### Removed
- Removed dangerous `purgeAllAlbums()` method that deleted all user albums regardless of source.

## [0.12.0] - 2025-11-18
### Added
- Landscape video renderer now supports GCam Motion Photos (Live Photos from Google Camera).
- Automatically extracts and uses embedded motion videos from landscape images when available.
- CLI: `--no-motion` flag for `journeys:render-cluster-video-landscape` command to disable motion inclusion.

### Changed
- Landscape videos now seamlessly blend static images and motion clips with time-stretching and crossfades.

## [0.11.0] - 2025-11-13
### Changed
- Near-home clusters: append frequent sublocality names to make album titles more specific.

## [0.10.0] - 2025-11-13
### Added
- GCam Motion toggle in Personal Settings: `Include motion from GCam photos (Live)`.
- OCC: `--no-motion` flag for `journeys:render-cluster-video` to disable motion inclusion per run.

### Fixed
- Smooth timing for GCam Motion playback: probe trailer duration and time-stretch to match per-image hold with proper crossfades.


## [0.9.2] - 2025-11-06
### Fixed
- Notifications: don't filter out notifications from other apps.

## [0.9.1] - 2025-11-05
### Changed
- Personal Settings: time thresholds are now configured in hours (supports decimals like 0.5 for 30 minutes).
- Docs updated to reflect this change.

## [0.9.0] - 2025-11-05
### Added
- Personal Settings: separate, clearly grouped controls for near-home and away-from-home thresholds (time gap and distance).
- OCC: When arguments/options are omitted, the CLI now falls back to the user's saved settings (including near/away thresholds).
- OCC: Prints the effective settings at the start of each run.

### Changed
- Daily background job reads per-user settings from the UI for both global and near/away thresholds.
- More detailed debug logs for clustering: when a cluster ends (time vs distance) and the thresholds used per near/away segment.

## [0.8.2] - 2025-11-04
### Added
- Optional inclusion of Group Folders and other mounts in clustering.
  - Personal Settings: new "Include Group Folders" toggle (default: off).
  - OCC: `--include-group-folders` flag.

### Changed
- Album assignment now uses `fileid` (mount-agnostic) instead of path, fixing empty albums when clustering images from Group Folders or other mounts.

## [0.8.0] - 2025-11-01
### Added
- Automatic video generation for new away-from-home clusters during the daily cron job.
- Rendering runs as a separate Nextcloud background job; clustering no longer blocks on rendering.
- Personal Settings: toggle to auto-generate videos and choose orientation (portrait/landscape).
- Home name (from stored JSON) is displayed next to the home coordinate fields.

### Changed
- Home-aware is default; removed the "Enable home-aware clustering" checkbox from the UI.

### Notes
- Auto-generation is cron-only; UI and OCC-triggered clustering do not auto-render.

## [0.7.11] - 2025-10-16
### Added
- Dedicated landscape video renderer with Ken Burns motion and crossfade stitching, separate from portrait pipeline.
- OCC command: `journeys:render-cluster-video-landscape` and a "Render Landscape" button in the personal settings UI.

### Fixed
- Rendering would stall after first image in landscape experiments; timing and stitching now mirror portrait (per-image hold+transition, `xfade` offsets, final trim).
- Progress output now shown during OCC runs via ffmpeg `-progress` parsing (e.g., `Progress: 42%`).

## [0.7.10] - 2025-10-16
### Fixed
- Sanitize album titles before creation to replace slashes and backslashes (e.g., `New Zealand/Aotearoa`) with a safe separator. Prevents album/folder path issues in Photos.

## [0.7.8] - 2025-10-12
### Changed
- Selection: coverage-first, two-pass selection spreads picks across the whole journey timeline.

## [0.7.7] - 2025-10-11
### Changed
- Tuning: faster slide-in/out with ~2s center pause for 3-wide stacks.
- Fix: prevent FFmpeg pad error in stack segments by scaling with `force_original_aspect_ratio=decrease` before padding.
- Docs/version sync.

## [0.7.5] - 2025-10-10
### Added
- Portrait videos now include occasional 3-wide landscape stacks with horizontal slides.

### Changed
- Stack rows pause at center (~2s) and slide in/out faster.
- Video selection keeps landscape images; the renderer mixes them automatically.

## [0.7.1] - 2025-09-26
### Fixed
- Trim rendered MP4 files to the expected duration so playback ends when the slideshow does.
- Ensure portrait output uses the correct crop for all frames when crossfades are enabled.

### Improved
- Smoother Ken Burns motion and crossfade transitions without regressing CLI progress reporting.

## [0.7.0] - 2025-09-26
### Added
- Journey albums can now be rendered into MP4 videos via the personal settings page or the `occ journeys:render-cluster-video <user> <albumId>` command.

### Requirements
- Rendering depends on `ffmpeg` being installed and available on the Nextcloud server's `PATH`.

### Known limitations
- Output is currently optimised for mobile-friendly portrait playback. Landscape presets, transitions, and background music will arrive in upcoming releases.

## [0.6.0] - 2025-09-19
### Packaging
- Release workflow builds the frontend before packaging to ensure fresh assets ship with releases.

For local dev from a git checkout, run `npm ci && npm run build`.


## [0.5.8] - 2025-09-19
### Changed
- Settings (#7): Personal settings page is now accessible to all logged-in users (per-user configuration; no admin required).


## [0.5.5] - 2025-09-10
### Changed
- Clustering now uses your home location by default to segment near-home vs away-from-home and apply appropriate thresholds.

### Added
- `--no-home-aware` flag to disable home-based segmentation and use a single global set of thresholds.

### Deprecated
- `--home-aware` (no longer required; default is on).

### Docs
- Updated app description to explain how home location is used by default and how to opt out.

## [0.5.4] - 2025-09-09
### Added
- Aggregated notification per run with an "Open Photos" action that links to the Photos app.


## [0.5.3] - 2025-09-08
### Added
- DB migration: add `start_dt` and `end_dt` columns and an index to `journeys_cluster_albums` for reliable incremental clustering.

### Changed
- Fail-fast album tracking: throw on DB errors instead of ignoring them silently.

## [0.5.2] - 2025-09-08
### Fixed
- Prevent division by zero when interpolating locations for adjacent images with identical `datetaken` (equal timestamps).

## [0.5.0] - 2025-09-07
### Added
- Incremental clustering (default): only process images taken after the latest tracked cluster end.
- OCC flags: `--from-scratch` to fully rebuild clusters; `--recent-cutoff-days` to control skipping of very recent trips (default 5; 0 disables).

## [0.4.3] - 2025-09-05
### Changed
- Skip no-location-only clusters: clusters composed entirely of images without coordinates are no longer turned into albums. This reduces noise from placeholder “Journey # (date range)” albums.

## [0.4.2] - 2025-09-05
### Changed
- Clustering robustness: prevent time-only tails (images without coordinates) from bridging over large spatial jumps by anchoring distance checks to the last-known geolocated photo within a cluster.

## [0.4.1] - 2025-09-03
### Added
- Deterministic purge of cluster albums via DB-backed tracking table (per user). Prevents album accumulation across runs.
### Changed
- Cleanup: removed runtime table creation; rely on app migrations for schema.

## [0.4.0] - 2025-09-03
### Added
- Home-aware clustering: timeline is segmented into near-home and away-from-home blocks; each block is clustered independently.

### Changed
- Near-home thresholds align to global `maxTimeGap/maxDistanceKm` when left at built-in defaults, ensuring consistent behavior near home.
- Near-home distance is capped at 25km when aligning to global defaults to improve local clustering.
- Away thresholds default to 36h / 50km and can be adjusted via CLI flags to better handle multi-day gaps during long trips.
- Removed post-processing merge for home-aware mode; clustering output is used directly.

## [0.3.2] - 2025-07-27
### Changed
- Default `maxDistanceKm` for clustering is now 50.0 (was 100.0)
- Updated documentation and help text in code, README, and info.xml to reflect new default

## [0.3.1] - 2025-07-20
- SystemTag-only album identification: new albums are created without postfix, identified and managed solely via SystemTags.
- Legacy albums with postfix are still purged via fallback logic.
- Added spatial constraint (≤1km) for location interpolation
- Stricter time gap (≤1h) for single neighbor interpolation
- Improved clustering accuracy for album clustering
- Added PHPUnit tests for interpolation logic

## [0.3.0-alpha] - 2025-07-20
### Added
- Spatial constraint for location interpolation: only interpolate image locations if the two neighboring images are within 1km of each other.
- Stricter temporal constraint for single-neighbor interpolation: if only one reference image is available, the time gap must not be larger than 1 hour.
- Comprehensive PHPUnit tests for all major interpolation scenarios.

### Changed
- Updated album creation and clustering logic to use improved location interpolation.
- Version bumped to 0.3.0-alpha in `appinfo/info.xml`.

### Fixed
- Prevented assignment of interpolated locations over large distances, improving album clustering accuracy.
- Fixed test data to match new spatial constraint in interpolation tests.

---

## [0.2.2-alpha] - 2024-XX-XX
- Previous changes (see earlier commits)
