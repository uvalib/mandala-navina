#!/usr/bin/env bash
# Cross-core join prototype for Spike 11 (access option C).
# Starts a throwaway Solr 7.7.3 with a kmassets-shaped stub core and the real
# mandala-av-transcripts core definition, loads SYNTHETIC documents, and checks that the
# proxy's per-user visibility fq, wrapped in a join, admits exactly the right units.
# Nothing here touches a shared Solr. Requires docker.
# Usage: ./join-check.sh [VARIANT]   (default: as committed)
#   VARIANT=trie_to      is_trid as TrieIntField (join into a Trie field, from-field needs no docValues)
#   VARIANT=point_dv     is_trid Point (committed), kmassets trid_i given docValues="true"
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
CORE_CONF="$HERE/../conf"
WORK="$(mktemp -d)"; NAME=mandala-join-proto; PORT=${PORT:-8984}
trap 'rc=$?; [ $rc -ne 0 ] && docker logs --tail 40 $NAME 2>&1 | grep -E "ERROR|Exception|Caused" | head -15; docker rm -f $NAME >/dev/null 2>&1 || true; rm -rf "$WORK"' EXIT

docker run --rm solr:7.7.3 cat /opt/solr/server/solr/solr.xml > "$WORK/solr.xml"
for c in kmassets mandala-av-transcripts; do
  mkdir -p "$WORK/$c/conf"; echo "name=$c" > "$WORK/$c/core.properties"
  cp -R "$CORE_CONF"/. "$WORK/$c/conf/"
done
cp "$HERE/kmassets-stub-schema.xml" "$WORK/kmassets/conf/schema.xml"
VARIANT="${1:-point_dv}"
SCHEMA_T="$WORK/mandala-av-transcripts/conf/schema.xml"; SCHEMA_K="$WORK/kmassets/conf/schema.xml"
case "$VARIANT" in
  trie_to)  python3 - "$SCHEMA_T" <<'PY'
import sys,re;p=sys.argv[1];s=open(p).read()
s=s.replace('<fieldType name="int"    class="solr.IntPointField"   docValues="true"/>','<fieldType name="int"    class="solr.TrieIntField" precisionStep="0"/>');open(p,'w').write(s)
PY
  ;;
  point_dv) sed -i.bak 's|<field name="trid_i"        type="int"    indexed="true" stored="true"/>|<field name="trid_i" type="int" indexed="true" stored="true" docValues="true"/>|' "$SCHEMA_K"; rm -f "$SCHEMA_K.bak" ;;
esac
echo "variant: $VARIANT"
chmod -R a+rwX "$WORK"

docker run -d --name $NAME -p $PORT:8983 -v "$WORK":/var/solr/data solr:7.7.3 solr-foreground -s /var/solr/data >/dev/null
for i in $(seq 1 60); do curl -fs "localhost:$PORT/solr/kmassets/admin/ping" >/dev/null 2>&1 && break; sleep 1; done
S="localhost:$PORT/solr"
post() { curl -fsS -H 'Content-Type: application/json' "$S/$1/update?commit=true" -d "$2" >/dev/null; }

# kmassets stand-in: 4 AV nodes with a transcript (trid 101..104) + 1 AV node without one.
post kmassets '[
 {"id":"a1","uid":"audio-video-11-1","asset_type":"audio-video","visibility_i":1,"trid_i":101},
 {"id":"a2","uid":"audio-video-11-2","asset_type":"audio-video","visibility_i":3,"trid_i":102},
 {"id":"a3","uid":"audio-video-11-3","asset_type":"audio-video","visibility_i":2,"trid_i":103,"collection_uid_s":"collection-11-50","members_uid_ss":["user-600"]},
 {"id":"a4","uid":"audio-video-11-4","asset_type":"audio-video","visibility_i":2,"trid_i":104,"collection_uid_s":"collection-11-51"},
 {"id":"a5","uid":"audio-video-11-5","asset_type":"audio-video","visibility_i":1}]'
# units: two per transcript (synthetic text).
UNITS='['; for t in 101 102 103 104 999; do for n in 1 2; do
 UNITS+="{\"id\":\"tcu-11-$t$n\",\"entity_id\":$t$n,\"nid\":${t: -1},\"is_trid\":$t,\"fts_start\":$n.5,\"fts_end\":$n.9,\"ts_content_eng\":\"synthetic chanting $t\"},"; done; done
post mandala-av-transcripts "${UNITS%,}]"

# Same fq strings the proxy stores in Redis: pre-encoded (%20) and concatenated raw.
ANON='(visibility_i:1)'
USER600='(visibility_i:(1%203)%20OR%20(node_user_i:600)%20OR%20(members_uid_ss:user-600))'
ALL='(*:*)'
JOIN_PREFIX='%7B!join%20from=trid_i%20to=is_trid%20fromIndex=kmassets%7D'

count() { # label fq expected
  local got body
  body=$(curl -sS "$S/mandala-av-transcripts/select?q=*:*&rows=0&wt=json&fq=${JOIN_PREFIX}${2}")
  got=$(printf '%s' "$body" | python3 -c 'import sys,json
d=json.load(sys.stdin)
print(d["response"]["numFound"] if "response" in d else "ERROR: "+d.get("error",{}).get("msg","?"))') || got="ERROR (non-JSON reply)"
  [ "$got" = "$3" ] && r=PASS || { r=FAIL; FAILED=1; }
  printf '%-4s %-34s units=%s (expected %s)\n' "$r" "$1" "$got" "$3"
}
FAILED=0
count "anonymous (public only)"          "$ANON"    2    # trid 101
count "user 600 (public+UVA+member)"     "$USER600" 6    # 101,102,103
count "bypass (*:*) -> every linked unit" "$ALL"    8    # 101..104; 999 has no kmassets doc
exit $FAILED
