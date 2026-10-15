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
php bin/migrate.php && php tests/selftest.php > /dev/null || { echo 'Local migrate or selftest failed' >&2; exit 1; }

# The code lives outside the web root; only public/ is exposed, by a symlink. Excluded paths are never deleted on the
# server, so config.php and var/sessions survive.
rsync -az --delete --exclude=/.git/ --exclude=/config.php --exclude=/var/ --exclude=/ref/ --exclude=/deploy.env \
    --exclude=.DS_Store ./ "$DEPLOY_HOST:$DEPLOY_DIR/"

# -T refuses to replace a real folder there. Apache must read public/ whatever the Mac's permissions are (macOS's
# openrsync has no --chmod), so open everything up except config.php and var/.
# Schema changes go in first (db/migrations, see README); seed.sql never touches heroes, it respawns monsters and
# rerolls the Delve.
ssh "$DEPLOY_HOST" "ln -sfnT \"\$HOME/$DEPLOY_DIR/public\" '$DEPLOY_WEB' && cd '$DEPLOY_DIR' \
    && find . \\( -path ./var -o -path ./config.php \\) -prune -o -exec chmod go+rX {} + \
    && $php_bin bin/migrate.php && cat db/seed.sql db/areas/*.sql | mysql && $php_bin tests/selftest.php > /dev/null \
    && echo 'Deployed $(git rev-parse --short HEAD); selftest ok.' \
    || { echo 'FAILED on the server: symlink, permissions, migrations, world reload or selftest.'; exit 1; }"
