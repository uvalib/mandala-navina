#!/bin/bash
# Run scripts/verify-oauth2-userinfo.py against a Mandala app node, reading the
# OAuth2 client credentials out of the running solr-proxy container so that no
# secret is ever typed, echoed, or left in shell history.
#
# Usage, from a workstation:
#     ./scripts/verify-oauth2-userinfo.sh                      # dev-0
#     ./scripts/verify-oauth2-userinfo.sh <ssh-host>           # another node
#     ./scripts/verify-oauth2-userinfo.sh <ssh-host> --show-claims
#
# Everything after the host is passed through to the Python script.
#
# Background: this is the regression test for ADR 014's OAuth2-authenticated
# path. It has to run from inside the environment's network -- the internal
# hostnames do not resolve from outside -- so it copies itself to the node and
# executes there. See docs/dev-notes/howto-verify-oauth2-authenticated-path.md.
#
# TIMING TRAP: do not run this while a deploy is in flight. deploy_backend.yml
# starts the new container roughly 50 seconds before `import full site
# configuration` enables modules, and mandala_saml_oauth's ServiceProvider --
# which carries the defect-4 fix -- only runs for an *enabled* module. Testing
# inside that window produces a false FAIL. Wait for the pipeline to reach
# "Deploy Succeeded", or confirm with:
#     drush pm:list --status=enabled | grep mandala_saml_oauth
set -euo pipefail

HOST="${1:-mandala-drupal-dev-0.internal.lib.virginia.edu}"
[ $# -gt 0 ] && shift || true
SSH_USER="${MANDALA_SSH_USER:-$(whoami)}"
SSH_KEY="${MANDALA_SSH_KEY:-$HOME/.ssh/id_rsa}"
PROXY_CONTAINER="${MANDALA_PROXY_CONTAINER:-mandala-solr-proxy-0}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$SSH_USER@$HOST"

echo "node:   $TARGET"
echo "proxy:  $PROXY_CONTAINER"

scp -q -o ConnectTimeout=10 -i "$SSH_KEY" \
    "$SCRIPT_DIR/verify-oauth2-userinfo.py" "$TARGET:/tmp/verify-oauth2-userinfo.py"

# The credentials are resolved on the remote side, inside the ssh command, and
# never cross back over the wire or into this shell.
ssh -o ConnectTimeout=10 -i "$SSH_KEY" "$TARGET" \
    "PASSTHRU_ARGS='$*' bash -s" <<'REMOTE'
set -euo pipefail
PROXY_CONTAINER="${MANDALA_PROXY_CONTAINER:-mandala-solr-proxy-0}"

if ! sudo docker inspect "$PROXY_CONTAINER" >/dev/null 2>&1; then
  echo "ERROR: container $PROXY_CONTAINER is not running on this node" >&2
  exit 3
fi

eval "$(sudo docker exec "$PROXY_CONTAINER" env \
  | grep -E '^SOLRPROXY_(CLIENT_ID|CLIENT_SECRET|REDIRECT_URI)=' \
  | sed 's/^/export /')"

if [ -z "${SOLRPROXY_CLIENT_SECRET:-}" ]; then
  echo "ERROR: could not read SOLRPROXY_CLIENT_SECRET from $PROXY_CONTAINER" >&2
  exit 3
fi

DRUPAL_CONTAINER="${MANDALA_DRUPAL_CONTAINER:-mandala-drupal-0}"
echo "image:  $(sudo docker inspect "$DRUPAL_CONTAINER" --format '{{.Config.Image}}' | sed 's#.*/##')"
printf 'fix module enabled: '
sudo docker exec "$DRUPAL_CONTAINER" sh -c \
  'cd /opt/drupal/app/drupal && vendor/bin/drush pm:list --status=enabled --format=json 2>/dev/null' \
  | grep -q '"mandala_saml_oauth"' && echo YES || echo "NO  <-- expect a false FAIL; see the timing trap"
echo

exec python3 /tmp/verify-oauth2-userinfo.py $PASSTHRU_ARGS
REMOTE
