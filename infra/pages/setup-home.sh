#!/usr/bin/env bash
# Create (or refresh) the Home page and make it the static front page.
set -euo pipefail
cd "$(dirname "$0")"
EXEC=(kubectl -n mercora exec -i deploy/mercora-wordpress -c wordpress --)

ID=$("${EXEC[@]}" wp post list --post_type=page --name=home --post_status=any --field=ID | head -1 || true)
if [ -z "$ID" ]; then
  ID=$("${EXEC[@]}" wp post create - --post_type=page --post_title=Home --post_name=home --post_status=publish --porcelain < home.html)
  echo "Created Home page (ID $ID)"
else
  "${EXEC[@]}" wp post update "$ID" - < home.html >/dev/null
  echo "Updated Home page (ID $ID)"
fi

"${EXEC[@]}" wp option update show_on_front page >/dev/null
"${EXEC[@]}" wp option update page_on_front "$ID" >/dev/null
echo "Front page set → http://127.0.0.1"
