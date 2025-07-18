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
- `maxDistanceKm` — Max allowed distance in kilometers (optional, default: 100)
- `minClusterSize` — Minimum images per cluster (optional, default: 3)

**How time gap influences clustering:**  
The `maxTimeGap` defines the largest allowed time (in hours) between two consecutive images for them to be grouped into the same journey. If the gap between two images exceeds this value, a new journey (album) is started. Smaller values create more, shorter journeys; larger values group more images together.

**Example:**
```sh
php occ journeys:cluster-create-albums admin 24 100 5
```

