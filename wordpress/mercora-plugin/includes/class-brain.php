<?php
// wordpress/mercora-plugin/includes/class-brain.php
namespace Mercora;

defined("ABSPATH") || exit();

/**
 * Handles one turn: build request → call brain → validate → execute → render.
 * The plugin is the session store. The brain is stateless.
 */
final class Brain
{
    public static function turn(string $msg, array &$ctx, int $turn_num): array
    {
        $payload = self::build_request($msg, $ctx, $turn_num);

        // Validate outgoing request against shared schema
        if (!self::valid_request($payload)) {
            return self::fallback("Invalid request shape.");
        }

        $raw = self::call_brain($payload);
        if (is_wp_error($raw)) {
            return self::fallback("Brain unreachable.");
        }

        // Validate incoming reply against shared schema
        if (!self::valid_response($raw, $turn_num)) {
            return self::fallback("Invalid response from brain.");
        }

        // Execute action first (before applying patch or rendering)
        $action_result = self::execute_action($raw["action"] ?? null);

        // Apply patch to context (cart is NEVER taken from the brain)
        self::apply_patch($ctx, $raw["patch"] ?? []);
        $ctx["cart"] = self::live_cart(); // always refresh from WooCommerce

        return [
            "display" => $raw["display"],
            "action_result" => $action_result,
            "meta" => $raw["meta"],
        ];
    }

    private static function build_request(
        string $msg,
        array $ctx,
        int $turn,
    ): array {
        [$scrubbed, $placeholders] = self::scrub($msg);
        return [
            "v" => 1,
            "turn" => $turn,
            "session" => $ctx["session_id"],
            "catalog_version" => (int) get_option("mercora_catalog_version", 0),
            "msg" => $scrubbed,
            "placeholders" => $placeholders,
            "ctx" => [
                "on_screen" => $ctx["on_screen"] ?? [],
                "viewed" => $ctx["viewed"] ?? [],
                "added" => $ctx["added"] ?? [],
                "rejected" => $ctx["rejected"] ?? [],
                "slots" => $ctx["slots"] ?? self::empty_slots(),
                "pending" => $ctx["pending"] ?? null,
                "cart" => self::live_cart(),
                "page" => $ctx["page"] ?? null,
                "past_purchases" => self::past_purchase_ids(),
            ],
        ];
    }

    private static function empty_slots(): array
    {
        return [
            "cat" => null,
            "max_price" => null,
            "min_price" => null,
            "brand" => null,
            "tag" => null,
        ];
    }

    private static function call_brain(array $payload): array|\WP_Error
    {
        $url = (string) (defined("MERCORA_BRAIN_URL")
            ? MERCORA_BRAIN_URL
            : get_option("mercora_brain_url", ""));
        $token = (string) (defined("MERCORA_BRAIN_TOKEN")
            ? MERCORA_BRAIN_TOKEN
            : get_option("mercora_brain_token", ""));

        $r = wp_remote_post(trailingslashit($url) . "turn", [
            "headers" => [
                "Content-Type" => "application/json",
                "Authorization" => "Bearer $token",
            ],
            "body" => wp_json_encode($payload),
            "timeout" => 4, // 4 s total: enough for 2 model calls
            "blocking" => true,
        ]);

        if (is_wp_error($r)) {
            return $r;
        }
        $code = wp_remote_retrieve_response_code($r);
        if ($code !== 200) {
            return new \WP_Error("brain_error", "HTTP $code");
        }
        return json_decode(wp_remote_retrieve_body($r), true) ??
            new \WP_Error("parse", "bad JSON");
    }

    private static function valid_request(array $p): bool
    {
        // Required fields and basic types — mirrors turn_request in v1.schema.json
        return isset(
            $p["v"],
            $p["turn"],
            $p["session"],
            $p["msg"],
            $p["ctx"],
        ) &&
            $p["v"] === 1 &&
            is_int($p["turn"]) &&
            is_string($p["msg"]) &&
            strlen($p["msg"]) > 0 &&
            is_array($p["ctx"]) &&
            isset(
                $p["ctx"]["slots"],
                $p["ctx"]["on_screen"],
                $p["ctx"]["pending"],
            ) &&
            self::valid_slots($p["ctx"]["slots"]);
    }

    private static function valid_response(array $r, int $expected_turn): bool
    {
        if (!isset($r["turn"], $r["patch"], $r["display"], $r["meta"])) {
            return false;
        }
        if ((int) $r["turn"] !== $expected_turn) {
            return false;
        } // turn mismatch
        if (!isset($r["display"]["template"], $r["display"]["speech"])) {
            return false;
        }
        if (
            isset($r["patch"]["slots"]) &&
            !self::valid_slots($r["patch"]["slots"])
        ) {
            return false;
        }
        if (isset($r["patch"]["pending"])) {
            $p = $r["patch"]["pending"];
            if (
                $p !== null &&
                (!isset($p["type"]) ||
                    !in_array($p["type"], ["slot", "confirm"], true))
            ) {
                return false;
            }
        }
        return true;
    }

    private static function valid_slots($slots): bool
    {
        if (!is_array($slots)) {
            return false;
        }
        // Only the keys declared in v1.schema.json are allowed
        $allowed = ["cat", "max_price", "min_price", "brand", "tag"];
        foreach (array_keys($slots) as $k) {
            if (!in_array($k, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    private static function execute_action(?array $action): ?array
    {
        if (!$action || !isset($action["type"])) {
            return null;
        }

        if ($action["type"] === "cart.add") {
            $pid = (int) ($action["product_id"] ?? 0);
            $vid = isset($action["variation_id"])
                ? (int) $action["variation_id"]
                : 0;
            $qty = (int) ($action["qty"] ?? 1);

            // Live stock check before touching the cart
            $p = wc_get_product($pid);
            if (!$p || !$p->is_in_stock()) {
                return ["ok" => false, "reason" => "out_of_stock"];
            }
            $result = WC()->cart->add_to_cart($pid, $qty, $vid);
            return ["ok" => (bool) $result];
        }

        if ($action["type"] === "cart.remove") {
            $pid = (int) ($action["product_id"] ?? 0);
            foreach (WC()->cart->get_cart() as $key => $item) {
                if ((int) $item["product_id"] === $pid) {
                    WC()->cart->remove_cart_item($key);
                    return ["ok" => true];
                }
            }
            return ["ok" => false, "reason" => "not_in_cart"];
        }

        if ($action["type"] === "navigate") {
            // The JS layer handles navigation; we just pass it through.
            return ["ok" => true, "url" => $action["url"]];
        }

        return null;
    }

    private static function apply_patch(array &$ctx, array $patch): void
    {
        // Replace
        foreach (["on_screen", "pending", "page"] as $k) {
            if (array_key_exists($k, $patch)) {
                $ctx[$k] = $patch[$k];
            }
        }
        // Append and trim
        foreach (["viewed", "added", "rejected"] as $k) {
            if (isset($patch[$k])) {
                $ctx[$k] = array_slice(
                    array_merge($ctx[$k] ?? [], $patch[$k]),
                    -20,
                );
            }
        }
        // Merge slots
        if (isset($patch["slots"])) {
            $ctx["slots"] = array_merge(
                $ctx["slots"] ?? self::empty_slots(),
                $patch["slots"],
            );
        }
        // cart is NEVER patched from the brain
    }

    private static function live_cart(): array
    {
        if (!function_exists("WC") || !WC()->cart) {
            return [];
        }
        $items = [];
        foreach (WC()->cart->get_cart() as $item) {
            $items[] = [
                "product_id" => $item["product_id"],
                "variation_id" => $item["variation_id"] ?: null,
                "qty" => $item["quantity"],
                "price" => (float) $item["line_total"],
            ];
        }
        return $items;
    }

    private static function past_purchase_ids(): array
    {
        if (!is_user_logged_in()) {
            return [];
        }
        $orders = wc_get_orders([
            "customer" => get_current_user_id(),
            "limit" => 10,
            "status" => ["completed"],
        ]);
        $ids = [];
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $ids[] = (int) $item->get_product_id();
            }
        }
        return array_values(array_unique($ids));
    }

    private static function scrub(string $msg): array
    {
        $placeholders = [];
        $scrubbed = preg_replace_callback(
            "/\b[\w.+-]+@[\w-]+\.\w+\b/",
            function ($m) use (&$placeholders) {
                $placeholders[] = ["type" => "email", "value" => $m[0]];
                return "[EMAIL]";
            },
            $msg,
        );
        $scrubbed = preg_replace_callback(
            "/\b[6-9]\d{9}\b/",
            function ($m) use (&$placeholders) {
                $placeholders[] = ["type" => "phone", "value" => $m[0]];
                return "[PHONE]";
            },
            $scrubbed,
        );
        return [$scrubbed, $placeholders];
    }

    private static function fallback(string $reason): array
    {
        return [
            "display" => [
                "template" => "error",
                "speech" =>
                    "Something went wrong. Try again or browse the shop.",
                "items" => [],
                "buttons" => ["Browse shop"],
            ],
            "action_result" => null,
            "meta" => ["path" => "fallback", "reason" => $reason],
        ];
    }
}
