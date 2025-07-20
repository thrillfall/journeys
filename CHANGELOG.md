# Changelog

All notable changes to this project will be documented in this file.

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
