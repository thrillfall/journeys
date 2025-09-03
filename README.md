# Journeys: Automatic Photo Album Creation for Nextcloud

Automatically cluster your images into journeys (vacations/trips) and create albums for each journey.

**Requires the [Memories](https://github.com/pulsejet/memories) app.**

## ✨ Features
- **🗺️ Location & Time Clustering:** Group images by when and where they were taken
- **🗂️ Album Creation:** Albums are created automatically for each journey
- **⚙️ Customizable:** Control minimum cluster size, time gap, and distance thresholds

## Requirements
- The Memories app must be installed and enabled.
- The Places setup in the Memories app must be completed (see Memories app documentation for details).

## 🚀 OCC Command Usage

```sh
php occ journeys:cluster-create-albums <user> [maxTimeGap] [maxDistanceKm] [minClusterSize]
```

**Arguments:**
- `user` — The user to cluster images for (**required**)
- `maxTimeGap` — Max allowed time gap in hours (optional, default: 24)
- `maxDistanceKm` — Maximum allowed distance in kilometers between consecutive images in a cluster (default: 50.0)
- `minClusterSize` — Minimum images per cluster (optional, default: 3)

**How time gap influences clustering:**  
The `maxTimeGap` defines the largest allowed time (in hours) between two consecutive images for them to be grouped into the same journey. If the gap between two images exceeds this value, a new journey (album) is started. Smaller values create more, shorter journeys; larger values group more images together.

**Example:**
```sh
php occ journeys:cluster-create-albums admin 24 100 5
```


## ♻️ Deterministic purge on re-runs (>= 0.4.1)

- The app tracks clusterer-created albums in a DB table and purges those before creating new ones, so repeated runs do not accumulate albums.
- Behavior differs with/without `--home-aware` because cluster results differ; within the same mode and parameters the album count should be stable.

First-time migration to enable tracking runs automatically on app update (see below).


## 🏠 Home-aware clustering (optional)

Home-aware mode adapts clustering based on whether photos are taken near your home or away:

- Near home: uses the global time threshold and a capped distance (defaults: 24h, up to 25km)
- Away from home: uses separate, typically looser thresholds (defaults: 36h, 50km)
- The timeline is segmented into contiguous near/away blocks, and each block is clustered independently. This supports long, multi-week away trips without per-edge switching.

Enable with:

```sh
php occ journeys:cluster-create-albums <user> --home-aware [--home-lat <lat> --home-lon <lon> --home-radius <km>] \
  [--near-time-gap <hours>] [--near-distance-km <km>] \
  [--away-time-gap <hours>] [--away-distance-km <km>]
```

Flags:

- `--home-aware` Enable home-aware clustering
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

