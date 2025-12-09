# Changelog

All notable changes to this project will be documented in this file.

## [0.18.3] - 2025-12-09
### Changed
- Landscape renderer now matches portrait: chunking enabled when any source image exceeds 13MP, otherwise single-pass.
- Added chunk/merge progress logging alignment with portrait renderer for landscape renders.

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
