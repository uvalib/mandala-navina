#!/bin/bash
# usage: s3.sh login <user> | s3.sh get <user|anon> <nid>   (cookie jars in $S)
S=/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/8044e084-b986-49bc-865f-f6dae1813628/scratchpad
H=https://mandala.ddev.site
case $1 in
 login) j=$S/jar_$2; rm -f $j
   fb=$(curl -sk -c $j $H/user/login | grep -o 'name="form_build_id" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
   curl -sk -b $j -c $j -o /dev/null -w "login $2 -> %{http_code}\n" --data-urlencode "name=$2" --data-urlencode "pass=s3pass" --data-urlencode "form_build_id=$fb" -d form_id=user_login_form -d op=Log+in $H/user/login;;
 get) j=$S/jar_$2; [ $2 = anon ] && j=/dev/null
   curl -sk -b $j -D $S/hdr -o $S/body "$H/spike13/book/$3$4"
   code=$(head -1 $S/hdr | tr -d '\r'); n=$(grep -o 'class="shanti-texts-section"' $S/body | wc -l | tr -d ' ')
   echo "$2 nid=$3 $code sections=$n dyn=$(grep -i '^x-drupal-dynamic-cache' $S/hdr | tr -d '\r') page=$(grep -i '^x-drupal-cache:' $S/hdr | tr -d '\r')";;
esac
