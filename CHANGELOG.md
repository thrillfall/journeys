# Changelog

All notable changes to this project will be documented in this file.

## [0.4.2] - 2025-09-04
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
