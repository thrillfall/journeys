# Home-Aware Adaptive Clustering Specification

## 1. Home-Aware Clustering (Optional)

- Optionally enable home-aware mode via a boolean parameter (e.g., `homeAware: bool`).
    - If `homeAware` is `false`, use global/default time and distance thresholds for all images.
    - If `homeAware` is `true`, adapt the clustering thresholds based on each image’s proximity to the detected home location (see below).
- The default value for `homeAware` should be `false` for backward compatibility.

## 2. Home Location Detection

- Automatically detect the user’s home location by finding the most frequent image location (e.g., by rounding lat/lon to 1 decimal place and counting occurrences).
- Use the centroid of the most frequent bucket as the home location.

## 3. Adaptive Thresholds

- Define the following configurable parameters (with sensible defaults):
    - `nearHomeDistanceKm` (default: 10 km): Distance from home within which an image is considered “close to home”.
    - `nearHomeTimeGapSec` (default: 3 hours): Maximum time gap for splitting clusters near home.
    - `nearHomeDistanceGapKm` (default: 2 km): Maximum distance gap for splitting clusters near home.
    - `farAwayTimeGapSec` (default: 12 hours): Maximum time gap for splitting clusters far from home.
    - `farAwayDistanceGapKm` (default: 20 km): Maximum distance gap for splitting clusters far from home.
    - `outlierDistanceKm` (default: 10 km): Distance for outlier detection.
- For each image, calculate its distance to home:
    - If distance ≤ `nearHomeDistanceKm`, use the “near home” time/distance thresholds.
    - If distance > `nearHomeDistanceKm`, use the “far away” time/distance thresholds.

## 4. Cluster Splitting Logic

- Sort images by timestamp.
- For each image, start a new cluster if either:
    - The time gap to the previous image exceeds the adaptive time threshold, OR
    - The distance to the previous image exceeds the adaptive distance threshold.
- If either image in the pair is missing location data, **do not split** the cluster based on distance; only split on time if the time gap is exceeded.

## 5. Outlier Handling

- Detect location outliers: an image is a location outlier if it is much farther from both its neighbors (distance > `outlierDistanceKm`), but those neighbors are close to each other.
- Outlier images should be included in the current cluster but should **never cause a cluster split**.
- Do not update the “previous” image pointer when processing an outlier, so splits are only considered between non-outlier images.

## 6. Images Without Location

- Images without location data should **never cause a cluster split** based on distance.
- If the time gap is exceeded, a split can occur, but missing location alone should not fragment clusters.

## 7. Contiguous Block Handling

- Ensure that a contiguous block of images in a new area forms its own cluster, even if not the majority in the overall cluster.
- Avoid merging a real trip into a home cluster due to outlier suppression or majority logic.

## 8. Debugging

- Add debug output for each cluster: print the cluster index, size, and for each image, print datetaken, lat, lon, and resolved location.
- Print time and distance gaps at cluster boundaries for further analysis.

---

**Summary:**
This specification ensures optional, robust home-aware clustering with adaptive thresholds, outlier suppression, and correct handling of missing location data. All parameters should be configurable, with sensible defaults as above.
