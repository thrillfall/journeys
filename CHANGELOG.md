# Changelog

All notable changes to this project will be documented in this file.

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
