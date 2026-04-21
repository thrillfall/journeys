# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Nextcloud app (`journeys`, app id `journeys`, namespace `OCA\Journeys`) that clusters a user's photos by time + location into "journey" albums, and optionally renders each album to an MP4 via `ffmpeg`.

Hard dependencies on other Nextcloud apps:
- **Memories** — this app does not scan the filesystem. It reads photo rows (id, path, datetaken, lat, lon) from the Memories index table (`oc_memories`), so the set of clusterable photos is whatever Memories has indexed.
- **Photos** — albums are created through `OCA\Photos\Album\AlbumMapper`. Only `fileid` is used to add photos (mount-agnostic), not paths.

Supported Nextcloud: `30–32`. PHP `>=8.0`.

## Commands

Frontend build (webpack, single Vue 2 bundle → `js/journeys-personalSettings.js`):
```sh
npm ci                    # first time
npx webpack --mode production     # production bundle (same as `npm run build`)
npx webpack --mode development --watch   # dev watch
```
`js/` is gitignored and must be rebuilt before testing the personal settings UI. The Makefile targets `build-js`, `watch-js`, `test`, `lint` reference npm scripts (`dev`, `watch`, `test:unit`, `lint`) that are **not defined** in `package.json` — only `build` exists. Don't trust those Makefile targets for day-to-day work; invoke webpack / phpunit directly.

PHP tests (PHPUnit, bootstrapped via Composer autoload — no Nextcloud server required for the `tests/Service/*` suite, which uses pure unit tests):
```sh
vendor/bin/phpunit                                    # all tests
vendor/bin/phpunit tests/Service/ClustererTest.php    # single file
vendor/bin/phpunit --filter testClusterImagesWith...  # single test
```

Packaging for the Nextcloud appstore:
```sh
make appstore     # produces build/artifacts/journeys.tar.gz
```

OCC commands (run from the Nextcloud root as the web user). Primary ones:
```sh
php occ journeys:cluster-create-albums <user> [--from-scratch] [--include-group-folders] \
    [--include-shared-images] [--debug-splits] [--from <date>] [--to <date>] [--last-years N]
php occ journeys:list-clusters <user>
php occ journeys:remove-all-albums <user>           # only removes albums tracked by this app
php occ journeys:render-cluster-video <user> <albumId>
php occ journeys:render-cluster-video-landscape <user> <albumId>
php occ journeys:show-latest-cluster-end <user>
```

## Architecture

### Dependency injection — explicit, not auto-wired

Services are registered in `appinfo/services.xml` (Symfony DI container XML). **Auto-wiring is not used for this app's own services** — if you add a new service with constructor dependencies on other services in `OCA\Journeys\Service\*`, you must add a `<service>` entry in `services.xml` listing each `<argument>`. A silent failure mode we've hit before: forgetting to register a service inside `<services>` → the daily cron appears to work but the dependent feature doesn't run (see 0.20.1 changelog).

`lib/AppInfo/Application.php` is the `IBootstrap` entry point — it only registers the notifier. App config, routes, commands, background jobs, and settings are declared in `appinfo/info.xml` and `appinfo/routes.php`.

### Clustering pipeline

`ClusteringManager::clusterForUser()` is the orchestrator for both OCC and the daily cron. Flow:

1. `ImageFetcher::fetchImagesForUser()` queries `oc_memories` joined against `oc_filecache` / `oc_mounts` to return `Image[]`. Three sources are blended based on flags: home storage (always), Group Folders / external mounts (`includeGroupFolders`), incoming user shares scoped to their mount root (`includeSharedImages`). Per-source counts are exposed via `getLastFetchStats()` / `getLastFileSources()`.
2. `HomeService` / `HomeLocationDetector` determine the user's home coordinate (manual override via user config, or auto-detected).
3. Timeline is segmented into near-home vs away-from-home blocks (home-aware mode is default). Each segment is clustered independently by `Clusterer::clusterImages()` using per-segment time + distance thresholds. Distance checks anchor to the **last geolocated image** in the current cluster (`prevGeo`), so a run of no-GPS photos cannot bridge a large spatial jump.
4. `ClusterLocationResolver` + `SimplePlaceResolver` resolve a place name for each cluster (uses Memories' Places tables; falls back automatically when PostGIS functions are unavailable).
5. `AlbumCreator` creates a Photos album per cluster, tags it with the `journeys-album` SystemTag, and records `(user_id, album_id, start_dt, end_dt, name, location)` in the tracking table `oc_journeys_cluster_albums`. Incremental runs use `end_dt` to skip already-processed images. `purgeClusterAlbums()` only deletes albums listed in this tracking table — manual albums are preserved.
6. Aggregated notification is sent via `Notifier` with an "Open Photos" action.
7. If auto-render is enabled, `VideoRenderJobScheduler` enqueues one `RenderClusterVideoJob` per newly created away-from-home album (runs as a separate Nextcloud background job, does not block clustering).

User-visible behavior settings (time gap, distance, min cluster size, near/away thresholds, includeGroupFolders, includeSharedImages, rangeFrom/rangeTo, auto-render prefs) live in `IConfig` user values under app `journeys`. CLI flags override; when omitted, the effective value is the stored user value; when that is empty, the code default applies. The daily job (`BackgroundJob\DailyClusteringJob`) reads these same user values so UI changes take effect on the next cron run.

### Video rendering

`ClusterVideoJobRunner` is the entry point used by both the OCC command, the controller action, and `RenderClusterVideoJob`. It composes:
- `ClusterVideoImageProvider` — selects images for the video (coverage-first two-pass spread across the journey, face-boost via `FacePresenceProvider` using Recognize data when available, screenshot filtering, optional `--no-face-boost`).
- `ClusterVideoFilePreparer` — resolves `fileid`s to local paths, handling mounted / shared storages.
- `ClusterVideoRenderer` (portrait) / `ClusterVideoRendererLandscape` — shell out to `ffmpeg` in `PATH`. Ken Burns on stills, optional GCam motion-photo handling, optional 3-wide landscape stacks inside portrait output, title overlay, background music from `data/` (128kbps) with fade-out. Both renderers **chunk** automatically when any source image exceeds 13 MP; otherwise they run single-pass. Requires FFmpeg 7.1+.
- Output defaults to `Documents/Journeys Movies/` in the user's storage.

### Frontend

Single-page Vue 2 app (`src/views/PersonalSettingsPage.vue`) mounted at `#personal_settings` by `templates/settings/personal.php`. Uses `@nextcloud/vue@^7`, talks to `lib/Controller/PersonalSettingsController.php` via the routes in `appinfo/routes.php` (`/personal_settings/*`). Settings auto-save inline on toggle. CSRF is enabled — do not re-add `@NoCSRFRequired`.

### Migrations

`lib/Migration/VersionXXXXDate*.php` using `SimpleMigrationStep` manages the `journeys_cluster_albums` tracking table (added `start_dt` / `end_dt` in 0403 for incremental clustering). Do not create tables at runtime — always add a migration. Use `*PREFIX*` placeholder in raw SQL; do not read the connection prefix directly.
