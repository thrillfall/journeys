#!/usr/bin/env bash
#
# Access-control integration test for the travel-diary sharing feature.
# Exercises the security core that the pure unit suite can't (it is DB-bound):
#   - canAccess (owner / member / non-member)
#   - requireOwner vs requireAccess (co-edit vs owner-only actions)
#   - setEntryPhotos owner-preservation + add-own-only (optional, needs DB)
#
# Requires a running Nextcloud with the app enabled and two app passwords.
# Generate app passwords with:
#   occ user:add-app-password <uid>
#
# Usage:
#   BASE_URL=http://localhost:8095 \
#   OWNER_USER=admin OWNER_PW=<app-pw> \
#   MEMBER_USER=other MEMBER_PW=<app-pw> \
#   [DB_CONTAINER=nextcloud_db DB_NAME=nextcloud DB_USER=nextcloud DB_PASS=nextcloud] \
#   bash tests/integration/diary_access_test.sh
set -u
BASE="${BASE_URL:-http://localhost:8095}"; APP="$BASE/index.php/apps/journeys/diary"
OU="${OWNER_USER:?}"; OP="${OWNER_PW:?}"; MU="${MEMBER_USER:?}"; MP="${MEMBER_PW:?}"
PASS=0; FAIL=0
ok(){ echo "  PASS: $1"; PASS=$((PASS+1)); }; no(){ echo "  FAIL: $1"; FAIL=$((FAIL+1)); }
jq_(){ python3 -c "import sys,json;d=json.load(sys.stdin);print($1)" 2>/dev/null; }
H=(-H 'OCS-APIRequest: true')
owner(){ curl -s -u "$OU:$OP" "${H[@]}" "$@"; }
member(){ curl -s -u "$MU:$MP" "${H[@]}" "$@"; }
ocode(){ curl -s -o /dev/null -w '%{http_code}' "$@"; }
db(){ [ -n "${DB_CONTAINER:-}" ] && docker exec "${DB_CONTAINER}" mysql -u"${DB_USER:-nextcloud}" -p"${DB_PASS:-nextcloud}" "${DB_NAME:-nextcloud}" -N -e "$1" 2>/dev/null; }

JOURNAL=$(owner -H 'Content-Type: application/json' -X POST -d '{"title":"AccessTest"}' "$APP/journals" | jq_ "d['journal']['id']")
[ -n "$JOURNAL" ] && ok "owner created journal $JOURNAL" || { no "create failed"; exit 1; }

echo "== canAccess: non-member denied =="
[ "$(ocode -u "$MU:$MP" "${H[@]}" "$APP/journals/$JOURNAL")" = "404" ] && ok "non-member GET -> 404" || no "non-member not denied"

echo "== owner-only: non-member cannot add members / share / delete =="
[ "$(ocode -u "$MU:$MP" "${H[@]}" -H 'Content-Type: application/json' -X POST -d "{\"type\":\"user\",\"principal\":\"$MU\"}" "$APP/journals/$JOURNAL/members")" = "404" ] && ok "non-member addMember -> 404" || no "non-member added member"

echo "== owner adds member -> canAccess grants =="
owner -H 'Content-Type: application/json' -X POST -d "{\"type\":\"user\",\"principal\":\"$MU\"}" "$APP/journals/$JOURNAL/members" >/dev/null
[ "$(ocode -u "$MU:$MP" "${H[@]}" "$APP/journals/$JOURNAL")" = "200" ] && ok "member GET -> 200" || no "member still denied"
member "$APP/journals" | jq_ "[t['title'] for t in d['journals']]" | grep -q AccessTest && ok "journal in member's list" || no "not in member list"

echo "== requireAccess: member co-edits entry + journal metadata =="
E=$(member -H 'Content-Type: application/json' -X POST -d '{"date":"2026-06-03","body":"by member"}' "$APP/journals/$JOURNAL/entries" | jq_ "d['entry']['id']")
[ -n "$E" ] && [ "$E" != "None" ] && ok "member created entry" || no "member cannot create entry"
member -H 'Content-Type: application/json' -X PUT -d '{"title":"Member Renamed"}' "$APP/journals/$JOURNAL" >/dev/null
[ "$(owner "$APP/journals/$JOURNAL" | jq_ "d['journal']['title']")" = "Member Renamed" ] && ok "member metadata co-edit persists" || no "metadata edit lost"

echo "== requireOwner: member cannot manage/share/delete =="
[ "$(ocode -u "$MU:$MP" "${H[@]}" -H 'Content-Type: application/json' -X POST -d "{\"type\":\"user\",\"principal\":\"$OU\"}" "$APP/journals/$JOURNAL/members")" = "404" ] && ok "member addMember -> 404" || no "member managed members"
[ "$(ocode -u "$MU:$MP" "${H[@]}" -X POST "$APP/journals/$JOURNAL/share")" = "404" ] && ok "member share -> 404" || no "member shared"
[ "$(ocode -u "$MU:$MP" "${H[@]}" -X DELETE "$APP/journals/$JOURNAL")" = "404" ] && ok "member delete -> 404" || no "member deleted"

if [ -n "${DB_CONTAINER:-}" ]; then
  echo "== setEntryPhotos owner-preservation (DB) =="
  # owner attaches one of their own photos, member re-saves; owner_uid must stay owner.
  OFID=$(db "SELECT fileid FROM oc_filecache WHERE storage=(SELECT numeric_id FROM oc_storages WHERE id='home::$OU') AND path LIKE 'files/%' AND mimetype IN (SELECT id FROM oc_mimetypes WHERE mimetype='image/jpeg') LIMIT 1;")
  if [ -n "$OFID" ]; then
    owner -H 'Content-Type: application/json' -X PUT -d "{\"photos\":[$OFID]}" "$APP/entries/$E/photos" >/dev/null
    member -H 'Content-Type: application/json' -X PUT -d "{\"photos\":[$OFID]}" "$APP/entries/$E/photos" >/dev/null
    OWN=$(db "SELECT owner_uid FROM oc_journeys_entry_photos WHERE entry_id=$E AND fileid=$OFID;")
    [ "$OWN" = "$OU" ] && ok "owner's photo keeps owner_uid=$OU after member re-save" || no "owner_uid became '$OWN'"
  else
    echo "  SKIP: no owner jpeg found for owner-preservation check"
  fi
else
  echo "  (DB_CONTAINER unset — skipping owner-preservation DB assertions)"
fi

echo "== removeMember revokes access =="
owner -X DELETE "$APP/journals/$JOURNAL/members/user/$MU" >/dev/null
[ "$(ocode -u "$MU:$MP" "${H[@]}" "$APP/journals/$JOURNAL")" = "404" ] && ok "after removeMember -> 404" || no "still has access"

owner -X DELETE "$APP/journals/$JOURNAL" >/dev/null
echo ""; echo "RESULT: $PASS passed, $FAIL failed"; exit $FAIL
