# Changelog

All notable changes to this project will be documented in this file.

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
