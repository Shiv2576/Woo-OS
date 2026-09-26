#!/usr/bin/env bash
# Idempotent WooCommerce + Mercora setup. Safe to re-run.
set -euo pipefail
EXEC="kubectl -n mercora exec deploy/mercora-wordpress -c wordpress --"
WP="$EXEC wp"

echo "==> Permalinks"
$WP rewrite structure '/%postname%/'

echo "==> WooCommerce"
$WP plugin is-installed woocommerce || $WP plugin install woocommerce
$WP plugin activate woocommerce

echo "==> Store settings"
$WP option update woocommerce_default_country IN
$WP option update woocommerce_currency INR
$WP option update woocommerce_coming_soon no          # otherwise guests can't see the store
$WP option update woocommerce_store_pages_only no
$WP option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
$WP wc tool run install_pages --user=admin >/dev/null  # shop, cart, checkout, my-account

echo "==> Mercora plugin"
$EXEC ln -sfn /mnt/mercora-plugin /opt/bitnami/wordpress/wp-content/plugins/mercora-plugin
$WP plugin activate mercora-plugin
$WP rewrite flush

echo ""
echo "Store: http://localhost:8080"
echo "Admin: http://localhost:8080/wp-admin  (admin / mercora-admin-123)"
echo "Alice: http://localhost:8080/assistant"
