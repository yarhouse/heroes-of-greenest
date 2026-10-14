#!/bin/sh
# deploy.sh :: copy the committed code to DreamHost, reload the world and run the selftest there.
# Usage: bin/deploy.sh. Reads deploy.env (gitignored); one-time server setup is in the README.
set -eu
cd "$(dirname "$0")/.."
. ./deploy.env # DEPLOY_HOST=user@host, DEPLOY_DIR=code dir in its home, DEPLOY_WEB=public URL folder in its home
php_bin=/usr/local/php84/bin/php # ssh commands don't read .bash_profile, so name DreamHost's PHP outright

# The server should always match a commit.
if [ -n "$(git status --porcelain)" ]; then
    echo 'Uncommitted changes: commit or stash first.' >&2
    exit 1
fi
php tests/selftest.php > /dev/null || { echo 'Local selftest failed: php tests/selftest.php' >&2; exit 1; }

# The code lives outside the web root; only public/ is exposed, by a symlink. Excluded paths are never deleted on the
# server, so config.php and var/sessions survive.
rsync -az --delete --exclude=/.git/ --exclude=/config.php --exclude=/var/ --exclude=/ref/ --exclude=/deploy.env \
    --exclude=.DS_Store ./ "$DEPLOY_HOST:$DEPLOY_DIR/"

# -T refuses to replace a real folder there. seed.sql never touches heroes; it respawns monsters and rerolls the Delve.
# shortcut: schema changes still need their ALTERs run by hand first, or this stops at the seed.
ssh "$DEPLOY_HOST" "ln -sfnT \"\$HOME/$DEPLOY_DIR/public\" '$DEPLOY_WEB' && cd '$DEPLOY_DIR' \
    && cat db/seed.sql db/areas/*.sql | mysql && $php_bin tests/selftest.php > /dev/null \
    && echo 'Deployed $(git rev-parse --short HEAD); selftest ok.' \
    || { echo 'FAILED on the server: symlink, world reload or selftest.'; exit 1; }"
