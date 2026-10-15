#!/bin/sh
# bulletin.sh :: list, post, update or delete the Town Square's bulletin board posts, here or on the server; no deploy.
# Usage: bin/bulletin.sh local|server list | post TEXT | update ID TEXT | delete ID
# In TEXT, \n starts a new line. An update isn't announced to heroes as news; delete and post again for that.
set -eu
cd "$(dirname "$0")/.."
usage='Usage: bin/bulletin.sh local|server list | post TEXT | update ID TEXT | delete ID'

# TEXT as a SQL string: quotes doubled, backslashes doubled except the \n that starts a new line.
str() {
    printf "'%s'" "$(printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/\\\\n/\\n/g' -e "s/'/''/g")"
}
id() {
    case $1 in ''|*[!0-9]*) echo "Not a post id: '$1'" >&2; exit 1 ;; esac
}

changed='; SELECT ROW_COUNT() AS changed' # 0 means no post had that id
case "${2:-} $#" in
    'list 2') sql='SELECT id, posted, text FROM bulletins ORDER BY id DESC' ;;
    'post 3') sql="INSERT INTO bulletins (text) VALUES ($(str "$3"))$changed" ;;
    'update 4') id "$3"; sql="UPDATE bulletins SET text = $(str "$4") WHERE id = $3$changed" ;;
    'delete 3') id "$3"; sql="DELETE FROM bulletins WHERE id = $3$changed" ;;
    *) echo "$usage" >&2; exit 1 ;;
esac

case $1 in
    local) printf '%s;\n' "$sql" | mysql --table -u greenest -p greenest ;;
    server) . ./deploy.env && printf '%s;\n' "$sql" | ssh "$DEPLOY_HOST" mysql --table ;;
    *) echo "$usage" >&2; exit 1 ;;
esac
