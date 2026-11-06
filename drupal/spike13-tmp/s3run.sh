#!/bin/bash
# usage: s3run.sh <mode>
S=/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/8044e084-b986-49bc-865f-f6dae1813628/scratchpad
m=$1; A=123746; B=123748; P=123769; HID=123768
mut(){ (cd /Users/ndg8f/Sandbox/Mandala/mandala-navina && ddev drush php:script drupal/spike13-tmp/s3-mut.php -- "$@" 2>&1 | grep -v -i deprecated | grep -v '^$' | head -2 | tr '\n' ' '); }
# chk <label> <viewer> <nid> <want-code> <want-sections> [grep-text]
chk(){ out=$($S/s3.sh get $2 $3 "?cache=$m"); code=$(echo "$out" | sed -E 's/.* (200|403|500) .*/\1/'); n=$(echo "$out" | sed -E 's/.*sections=([0-9]+).*/\1/'); dyn=$(echo "$out" | grep -o 'dynamic-cache: [A-Z]*' | sed 's/dynamic-cache: //'); extra=""; res=PASS
  [ "$code" = "$4" ] || res=FAIL; [ "$4" != 200 ] || [ "$n" = "$5" ] || res=FAIL
  if [ -n "$6" ]; then t=$(grep -c "$6" $S/body); extra=" title_seen=$t"; [ "$t" -ge 1 ] || res=FAIL; fi
  printf "  %-34s %-9s got=%s/%-3s want=%s/%-3s dyn=%-4s%s %s\n" "$1" "$2" "$code" "$n" "$4" "$5" "$dyn" "$extra" "$res"; }
all(){ # label then wants: anon other member editor (code:sections)
  l=$1; shift; i=0; for v in anon s3_other s3_member s3_editor; do w=${!#}; i=$((i+1)); done; }
echo "######## cache=$m"
mut ovr $HID 2 >/dev/null; mut grp 0 >/dev/null; mut mem add >/dev/null; mut title $P "S3 base title" >/dev/null
(cd /Users/ndg8f/Sandbox/Mandala/mandala-navina && ddev drush cr >/dev/null 2>&1)
for v in anon s3_other s3_member s3_editor; do $S/s3.sh get $v $A "?cache=$m" >/dev/null; $S/s3.sh get $v $A "?cache=$m" >/dev/null; done
$S/s3.sh get anon $B "?cache=$m" >/dev/null
echo "0 warm baseline"; chk "baseline" anon $A 200 42; chk "baseline" s3_other $A 200 44; chk "baseline" s3_member $A 200 46; chk "baseline" s3_editor $A 200 46
echo "1 edit one page in book A; book B must stay cached"; mut title $P "S3 edited $m"
for v in anon s3_other s3_member s3_editor; do chk "after title edit" $v $A 200 $( [ $v = anon ] && echo 42 || ([ $v = s3_other ] && echo 44 || echo 46)) "S3 edited $m"; done
out=$($S/s3.sh get anon $B "?cache=$m"); echo "  book B (untouched) anon: $(echo $out | grep -o 'dynamic-cache: [A-Z]*') page=$(echo $out | grep -o ' page=.*')  (HIT = not invalidated)"
echo "2 hidden private page -> default (visible)"; mut ovr $HID 0
chk "ovr 2->0" anon $A 200 43; chk "ovr 2->0" s3_other $A 200 45; chk "ovr 2->0" s3_member $A 200 46; chk "ovr 2->0" s3_editor $A 200 46
echo "3 back to private"; mut ovr $HID 2
chk "ovr 0->2" anon $A 200 42; chk "ovr 0->2" s3_other $A 200 44; chk "ovr 0->2" s3_member $A 200 46; chk "ovr 0->2" s3_editor $A 200 46
echo "4 whole collection -> private"; mut grp 1
chk "group private" anon $A 403 0; chk "group private" s3_other $A 403 0; chk "group private" s3_member $A 200 46; chk "group private" s3_editor $A 200 46
echo "5 collection back to public"; mut grp 0
chk "group public" anon $A 200 42; chk "group public" s3_other $A 200 44; chk "group public" s3_member $A 200 46
echo "6 member removed from collection"; mut mem rm
chk "member removed" s3_member $A 200 44
echo "7 member re-added"; mut mem add
chk "member re-added" s3_member $A 200 46
mut title $P "S3 base title" >/dev/null
