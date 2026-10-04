<?php
// wordpress/mercora-plugin/includes/class-sync.php
namespace Mercora;

defined("ABSPATH") || exit();

/**
 * Pushes product changes to the Alice API so its in-memory catalog stays in sync.
 *
 * Why not WooCommerce's built-in webhooks: stock reductions from orders are written
 * directly to the database and do not reliably fire "product.updated". These hooks do.
 *
 * Settings come from config.local.php (constants), falling back to WordPress options.
 */
final class Sync
{
    /** @var array<int,true> */
    private static array $changed = [];
    /** @var array<int,true> */
    private static array $deleted = [];
    private static bool $registered = false;

    public static function init(): void
    {
        // Product created / saved (admin, REST, imports, seeder)
        add_action("woocommerce_new_product", [self::class, "on_id"], 10, 1);
        add_action("woocommerce_update_product", [self::class, "on_id"], 10, 1);
        add_action(
            "woocommerce_new_product_variation",
            [self::class, "on_id"],
            10,
            1,
        );
        add_action(
            "woocommerce_update_product_variation",
            [self::class, "on_id"],
            10,
            1,
        );

        // Stock changes (orders, refunds, cancellations, manual stock edits)
        add_action(
            "woocommerce_product_set_stock",
            [self::class, "on_product"],
            10,
            1,
        );
        add_action(
            "woocommerce_variation_set_stock",
            [self::class, "on_product"],
            10,
            1,
        );
        add_action(
            "woocommerce_product_set_stock_status",
            [self::class, "on_id"],
            10,
            1,
        );
        add_action(
            "woocommerce_variation_set_stock_status",
            [self::class, "on_id"],
            10,
            1,
        );

        // Removal
        add_action("wp_trash_post", [self::class, "on_delete"], 10, 1);
        add_action("before_delete_post", [self::class, "on_delete"], 10, 1);
    }

    /** @return array{0:string,1:string} [url, secret] */
    private static function config(): array
    {
        $url = defined("MERCORA_ALICE_SYNC_URL")
            ? (string) MERCORA_ALICE_SYNC_URL
            : (string) get_option("mercora_alice_sync_url", "");
        $secret = defined("MERCORA_ALICE_SYNC_SECRET")
            ? (string) MERCORA_ALICE_SYNC_SECRET
            : (string) get_option("mercora_alice_sync_secret", "");
        return [$url, $secret];
    }

    /** Normalise any product/variation ID to the parent product ID Alice indexes. */
    private static function parent_id(int $id): int
    {
        $type = get_post_type($id);
        if ("product_variation" === $type) {
            return (int) wp_get_post_parent_id($id);
        }
        return "product" === $type ? $id : 0;
    }

    private static function schedule_flush(): void
    {
        if (!self::$registered) {
            self::$registered = true;
            add_action("shutdown", [self::class, "flush"], 100); // one push per request
        }
    }

    private static function queue(int $id): void
    {
        $pid = self::parent_id($id);
        if ($pid > 0) {
            self::$changed[$pid] = true;
            self::schedule_flush();
        }
    }

    public static function on_id($id): void
    {
        self::queue((int) $id);
    }

    public static function on_product($product): void
    {
        if ($product instanceof \WC_Product) {
            self::queue($product->get_id());
        }
    }

    public static function on_delete($post_id): void
    {
        $post_id = (int) $post_id;
        if ("product" === get_post_type($post_id)) {
            self::$deleted[$post_id] = true;
            unset(self::$changed[$post_id]);
            self::schedule_flush();
        }
    }

    public static function flush(): void
    {
        [$url, $secret] = self::config();
        if (
            "" === $url ||
            "" === $secret ||
            (!self::$changed && !self::$deleted)
        ) {
            return;
        }

        $body = wp_json_encode([
            "changed" => array_keys(self::$changed),
            "deleted" => array_keys(self::$deleted),
            "sent_at" => time(),
        ]);
        self::$changed = [];
        self::$deleted = [];

        // Fire-and-forget: the shopper's request must never wait on Alice.
        wp_remote_post($url, [
            "body" => $body,
            "headers" => [
                "Content-Type" => "application/json",
                "X-Mercora-Signature" => hash_hmac("sha256", $body, $secret),
            ],
            "timeout" => 1,
            "blocking" => false,
        ]);
    }
}
