#!/usr/bin/env bash
# Seed the Mercora catalog in small batches (each batch is a fresh PHP process, so memory stays low).
#   ./db/seeds/seed.sh              → 500 products (skips ones that already exist)
#   ./db/seeds/seed.sh 500 reset    → delete previously seeded products, then seed fresh
#   BATCH=25 ./db/seeds/seed.sh     → smaller batches if memory is tight
set -euo pipefail
cd "$(dirname "$0")"

COUNT="${1:-500}"
MODE="${2:-}"
BATCH="${BATCH:-40}"
NS=mercora
EXEC=(kubectl -n "$NS" exec -i deploy/mercora-wordpress -c wordpress --)

echo "==> Seeding $COUNT products in batches of $BATCH ${MODE:+($MODE)}"
round=0
while :; do
  round=$((round + 1))
  if ! OUT=$("${EXEC[@]}" wp eval-file - "$COUNT" $MODE "limit=$BATCH" < seed-products.php 2>&1); then
    echo "$OUT" | tail -20
    echo "!! Batch $round failed (see above)."
    exit 1
  fi
  MODE=""   # reset only on the first batch
  CREATED=$(echo "$OUT" | sed -n 's/^MERCORA_CREATED=//p' | tail -1)
  echo "    batch $round: $(echo "$OUT" | grep -E '^Success:' | sed 's/^Success: //')"
  [ "${CREATED:-0}" -eq 0 ] && break
done

echo "==> Rebuilding WooCommerce lookup tables"
"${EXEC[@]}" wp wc tool run regenerate_product_lookup_tables --user=admin >/dev/null 2>&1 || true
"${EXEC[@]}" wp wc tool run regenerate_product_attributes_lookup_table --user=admin >/dev/null 2>&1 || true
"${EXEC[@]}" wp wc tool run clear_transients --user=admin >/dev/null 2>&1 || true

echo "==> Catalog now contains: $("${EXEC[@]}" wp post list --post_type=product --post_status=publish --format=count) products"
