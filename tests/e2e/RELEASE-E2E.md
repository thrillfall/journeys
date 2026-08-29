# Release E2E verification

Manual end-to-end smoke check to run **before releasing a major feature change**
(and after any change that could touch the Nextcloud runtime API surface — the
0.28.1 `getDatabaseConnection()` crash on NC 34 is exactly what this catches).

This is a checklist, not a script — run the blocks in order against the live dev
Nextcloud and eyeball the results against the **PASS** criteria. Each scenario is
independent; if one fails, fix and re-run just that block.

Dev env details (URL, containers, credentials, app-password recipe) live in the
`reference-dev-nextcloud-env` memory. Key facts repeated inline below.

---

## 0. Environment bring-up

```sh
cd /home/moritzdeissner/code/private/journeys_app && docker compose up -d --build
# If the persisted volume was an older NC major, an upgrade is required:
docker exec -u www-data nextcloud php occ upgrade            # no-op if not needed
docker exec -u www-data nextcloud php occ maintenance:mode --off
docker exec -u www-data nextcloud php occ status
docker exec -u www-data nextcloud php occ app:list | grep -iE 'journeys|memories|photos'
```

**PASS:** `status` shows the expected `versionstring` (the Dockerfile pins the NC
major — check `../Dockerfile`), `maintenance: false`, `needsDbUpgrade: false`; and
`journeys` (at the just-released version), `memories`, and `photos` are all listed
as enabled. Confirm there are indexed photos to cluster:

```sh
docker exec nextcloud_db mysql -unextcloud -pnextcloud nextcloud -N -e "SELECT COUNT(*) FROM oc_memories;"
```

**PASS:** count is non-zero (data set is ~6k photos, 2014–2026, admin user).

---

## 1. Clustering — the crash regression

Run the exact shape of the command from the 0.28.0→0.28.1 bug report. This is the
cheap incremental path; it proves the fetch→cluster pipeline executes without a
fatal.

```sh
docker exec -u www-data nextcloud php occ journeys:cluster-create-albums admin --last-years=1
```

**PASS:** exits cleanly, prints the settings banner + `Image sources: total=…` +
`All clusters processed.` **FAIL** if you see any PHP fatal, especially
`Call to undefined method` (removed-API regression). `Found 0 clusters` here is
*fine* — incremental runs skip already-processed images.

---

## 2. Album creation — full from-scratch

> ⚠️ **Destructive on purpose.** `--from-scratch` purges every album tracked in
> `oc_journeys_cluster_albums` and recreates the set. Only ever run this against
> the **dev** instance (localhost:8095) — never a real user's server.

```sh
docker exec -u www-data nextcloud php occ journeys:cluster-create-albums admin --from-scratch
docker exec nextcloud_db mysql -unextcloud -pnextcloud nextcloud -N -e "SELECT COUNT(*) FROM oc_journeys_cluster_albums;"
docker exec -u www-data nextcloud php occ journeys:list-clusters admin | head
```

**PASS:**
- The run prints `Found N clusters` with **N > 0** and multiple
  `Cluster X: Created album '<Place> <Month Year>' with M images.` lines.
- Location resolution works — album names carry real place names (e.g.
  `València …`, `New Zealand/Aotearoa …`, `Lasithi Regional Unit …`), not blank
  or coordinate-only labels.
- Tracking table count is **> 0** and `list-clusters` renders the album table
  without error (this also exercises the DI container path in that command).

---

## 3. Journals (travel diary) — HTTP lifecycle

App routes require CSRF, so authenticate with an **app-password + `OCS-APIRequest`
header** (plain `-u admin:admin` fails on writes).

```sh
PW=$(docker exec -u www-data -e NC_PASS=admin nextcloud php occ user:add-app-password admin --password-from-env | tail -1)
B="http://localhost:8095/index.php/apps/journeys"
AUTH=(-u "admin:$PW" -H 'OCS-APIRequest: true' -H 'Content-Type: application/json')

# list
curl -s "${AUTH[@]}" "$B/diary/journals" -w '\n[%{http_code}]\n'

# create -> capture id
JID=$(curl -s "${AUTH[@]}" -X POST -d '{"title":"E2E Verify","description":"smoke"}' "$B/diary/journals" | grep -oP '"id":\K[0-9]+' | head -1)

# add an entry  (field is "date", NOT "entryDate")
curl -s "${AUTH[@]}" -X POST -d '{"date":"2026-07-09","title":"Day 1","body":"hello"}' "$B/diary/journals/$JID/entries" -w '\n[%{http_code}]\n'

# read back
curl -s "${AUTH[@]}" "$B/diary/journals/$JID"

# share -> public token  (token is the JSON "token" field; the "url" has escaped slashes)
TOKEN=$(curl -s "${AUTH[@]}" -X POST "$B/diary/journals/$JID/share" | grep -oP '"token":"\K[^"]+')

# public page — NO auth
curl -s "$B/s/$TOKEN" -o /dev/null -w '[public %{http_code}]\n'
curl -s "$B/s/$TOKEN" | grep -c "E2E Verify"

# CLEANUP — always delete the test journal
curl -s "${AUTH[@]}" -X DELETE "$B/diary/journals/$JID" -w '\n[%{http_code}]\n'
```

**PASS:**
- list `200`; create returns a journal with an `id`; add-entry `201` and the entry
  echoes back the `date`/`title`; read-back shows the entry under `entries` and the
  journal's `startDate`/`endDate` auto-updated to the entry date.
- share returns a non-empty `token`; the public page returns `200` and the title
  grep count is **> 0** (HTML actually rendered the journal).
- delete returns `{"deleted":true}` and the journal is gone from the list.

**Gotchas that bit us before (harness bugs, not app bugs):**
- entry date field is `date`, not `entryDate`.
- the share `url` value has JSON-escaped slashes (`s\/…`) — extract the `token`
  field instead of parsing the URL.

---

## 4. Memories timeline scoping

Journeys must never use a photo Memories would not show. `oc_memories` is wider
than the Memories timeline (the indexer walks the whole home tree; the timeline is
narrowed at query time), so this needs checking against the DB, not just the app.

**4a is the check that matters** — it reproduces issue #41, where a user's ebook
folder was clustered because it sat outside their `timelinePath`. 4b is a parity
check on a much narrower window; run it, but a green 4b means little if 4a is red.

### 4a. Timeline path — the #41 regression

```sh
# What the user's timeline path is, and what the index holds outside it
docker exec -u www-data nextcloud php occ user:setting admin memories
docker exec nextcloud_db mysql -unextcloud -pnextcloud nextcloud -e "
  SELECT CASE WHEN f.path LIKE 'files/Photos/%' THEN 'inside' ELSE 'OUTSIDE' END AS scope, COUNT(*) n
  FROM oc_memories m JOIN oc_filecache f ON f.fileid=m.fileid
  JOIN oc_storages s ON s.numeric_id=f.storage
  WHERE s.id='home::admin' AND f.path LIKE 'files/%' AND m.datetaken IS NOT NULL
  GROUP BY scope;"

# No tracked album may hold a home-storage photo from outside the timeline path
docker exec nextcloud_db mysql -unextcloud -pnextcloud nextcloud -N -e "
  SELECT COUNT(*) FROM oc_journeys_cluster_albums j
  JOIN oc_photos_albums_files af ON af.album_id = j.album_id
  JOIN oc_filecache f ON f.fileid = af.file_id
  JOIN oc_storages s ON s.numeric_id = f.storage
  WHERE j.user_id='admin' AND s.id='home::admin' AND f.path NOT LIKE 'files/Photos/%';"
```

**PASS:** the index reports a non-zero `OUTSIDE` count (so the check is
meaningful — if it is 0, the fixture proves nothing), and the album query returns
**0**. `Image sources: total=` from block 2 equals the `inside` count plus any
shared/group totals.

### 4b. `.nomedia` — markers added after indexing

Memories excludes marker folders from indexing in the first place
(`Service/Index.php`, `Listeners/PostWriteListener.php`), so rows for them
normally never reach `oc_memories` and this path cannot fire. It covers the one
window where they do: a marker dropped on a folder that was **already indexed**,
whose rows survive until the next full index sweep. Memories hides those at query
time; so must we.

> Needs `$PW` / `$B` from block 3, and the cleanup re-runs the destructive
> `--from-scratch` from block 2 — dev instance only.

```sh
docker exec nextcloud sh -c "touch /var/www/html/data/admin/files/Photos/teupitz/.nomedia && chown www-data:www-data /var/www/html/data/admin/files/Photos/teupitz/.nomedia"
docker exec -u www-data nextcloud php occ files:scan --path=/admin/files/Photos/teupitz
docker exec -u www-data nextcloud php occ journeys:cluster-create-albums admin --from-scratch --debug-splits 2>&1 | grep -c teupitz
curl -s -u "admin:$PW" -H 'OCS-APIRequest: true' "$B/diary/day-photos?date=2025-08-29"

# CLEANUP — always remove the marker and re-cluster
docker exec nextcloud rm -f /var/www/html/data/admin/files/Photos/teupitz/.nomedia
docker exec -u www-data nextcloud php occ files:scan --path=/admin/files/Photos/teupitz
docker exec -u www-data nextcloud php occ journeys:cluster-create-albums admin --from-scratch
```

**PASS:** the grep count is **0** (the folder's 30 photos are gone from clustering
while their rows are still in `oc_memories` — confirm that with a `SELECT`, since
it is the whole point of the check), and `day-photos` for a day covered only by
that folder returns `{"photos":[]}`. **FAIL** if either still lists `teupitz`.

---

## Growing this checklist

One `##` scenario section per feature domain. When you ship a **major feature**,
add (or extend) the matching section with: the command/route, the concrete
expected output, and a **PASS** criterion. Keep destructive steps clearly flagged
and dev-only.
