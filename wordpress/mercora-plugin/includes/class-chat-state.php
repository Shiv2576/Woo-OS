<?php
// wordpress/mercora-plugin/includes/class-chat-state.php
namespace Mercora;

defined("ABSPATH") || exit();

/**
 * Keeps the shopper's small Alice conversation in the WooCommerce SESSION.
 *
 * Why the WooCommerce session: it lives in the store's own database, works for
 * guests (cookie) and logged-in customers (keyed by user id, so it follows them
 * across devices), and WooCommerce deletes expired rows itself (about 48 hours),
 * so nothing piles up.
 *
 * What is stored: the visible transcript (text, product IDS only, last buttons)
 * and the working context (slots, focus, pending, on_screen, short lists). Never
 * the cart (read live from WooCommerce) and never anything for the brain to keep.
 *
 * The whole thing is stored as ONE JSON string, capped in size, because
 * WooCommerce loads the session on every request and because PHP arrays cannot
 * tell `{}` from `[]` (the brain's schema can).
 */
final class Chat_State
{
    public const KEY = "mercora_chat";
    public const SCHEMA = 1;

    private const MAX_MSGS = 24;
    private const MAX_TEXT = 600;
    private const MAX_IDS = 8;
    private const MAX_BTNS = 6;
    private const MAX_BYTES = 20000;

    public static function init(): void
    {
        add_action("rest_api_init", [self::class, "register_routes"]);

        // "Until checkout": wipe the chat when an order is placed.
        add_action("woocommerce_checkout_order_processed", [
            self::class,
            "clear",
        ]);
        add_action("woocommerce_store_api_checkout_order_processed", [
            self::class,
            "clear",
        ]);
        add_action("woocommerce_thankyou", [self::class, "clear"]);
    }

    public static function register_routes(): void
    {
        register_rest_route("mercora/v1", "/chat", [
            [
                "methods" => "POST",
                "permission_callback" => "__return_true",
                "callback" => [self::class, "rest_save"],
            ],
            [
                "methods" => "DELETE",
                "permission_callback" => "__return_true",
                "callback" => [self::class, "rest_clear"],
            ],
        ]);
    }

    /** The WooCommerce session, loading it first if this is a REST request. */
    private static function session()
    {
        if (!function_exists("WC")) {
            return null;
        }
        if (!WC()->session && function_exists("wc_load_cart")) {
            wc_load_cart();
        }
        return WC()->session ?: null;
    }

    /** Saved state as an object (so `{}` stays `{}` when sent to JS), or null. */
    public static function get(): ?object
    {
        $s = self::session();
        if (!$s) {
            return null;
        }
        $raw = $s->get(self::KEY);
        if (!is_string($raw)) {
            return null;
        }
        $d = json_decode($raw, false);
        return is_object($d) && ($d->v ?? 0) === self::SCHEMA ? $d : null;
    }

    public static function rest_save($request)
    {
        $s = self::session();
        if (!$s) {
            return new \WP_REST_Response(["saved" => false], 503);
        }
        $body = json_decode((string) $request->get_body(), false);
        if (!is_object($body)) {
            return new \WP_REST_Response(
                ["saved" => false, "error" => "bad body"],
                400,
            );
        }

        $json = self::encode_capped(self::clean($body));

        // A guest only gets a session row (and cookie) once there is data to keep.
        $s->set_customer_session_cookie(true);
        $s->set(self::KEY, $json);

        return rest_ensure_response([
            "saved" => true,
            "bytes" => strlen($json),
        ]);
    }

    public static function rest_clear()
    {
        self::clear();
        return rest_ensure_response(["cleared" => true]);
    }

    public static function clear(): void
    {
        $s = self::session();
        if ($s) {
            $s->__unset(self::KEY);
        }
    }

    // ───────────────────────────────────────────────────────────── sanitising

    private static function prop($obj, string $name, $default = null)
    {
        return is_object($obj) && isset($obj->$name) ? $obj->$name : $default;
    }

    /** @return int[] */
    private static function ints($v, int $max): array
    {
        $list = is_array($v) ? $v : [];
        $out = [];
        foreach ($list as $n) {
            $n = (int) $n;
            if ($n > 0 && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        return array_slice($out, -$max);
    }

    /** Recursively sanitise client data without turning ints into strings. */
    private static function scrub($v, int $depth = 0)
    {
        if (is_int($v) || is_float($v) || is_bool($v) || null === $v) {
            return $v;
        }
        if (is_string($v)) {
            return mb_substr(sanitize_text_field($v), 0, 200);
        }
        if ($depth >= 3) {
            return null;
        }
        if (is_array($v)) {
            $out = [];
            foreach (array_slice($v, 0, 20) as $x) {
                $out[] = self::scrub($x, $depth + 1);
            }
            return $out;
        }
        if (is_object($v)) {
            $o = new \stdClass();
            $n = 0;
            foreach (get_object_vars($v) as $k => $x) {
                if (++$n > 20) {
                    break;
                }
                // Keep keys readable ("Colour" must stay "Colour"); only drop odd characters.
                $key = mb_substr(
                    (string) preg_replace(
                        "/[^\p{L}\p{N} _\-.:]/u",
                        "",
                        (string) $k,
                    ),
                    0,
                    60,
                );
                if ("" !== $key) {
                    $o->$key = self::scrub($x, $depth + 1);
                }
            }
            return $o;
        }
        return null;
    }

    public static function clean($in): array
    {
        $c = self::prop($in, "ctx", new \stdClass());

        $slots = self::scrub(self::prop($c, "slots", new \stdClass()));
        $ctx = [
            "on_screen" => self::ints(self::prop($c, "on_screen", []), 8),
            "viewed" => self::ints(self::prop($c, "viewed", []), 20),
            "added" => self::ints(self::prop($c, "added", []), 20),
            "rejected" => self::ints(self::prop($c, "rejected", []), 20),
            "slots" => is_object($slots) ? $slots : new \stdClass(),
            "pending" => self::scrub(self::prop($c, "pending")),
            "focus" => self::scrub(self::prop($c, "focus")),
        ];

        $msgs = [];
        $raw = self::prop($in, "msgs", []);
        foreach (
            array_slice(is_array($raw) ? $raw : [], -self::MAX_MSGS)
            as $m
        ) {
            $role = "u" === self::prop($m, "r") ? "u" : "a";
            $text = mb_substr(
                sanitize_text_field((string) self::prop($m, "t", "")),
                0,
                self::MAX_TEXT,
            );
            $ids = self::ints(self::prop($m, "ids", []), self::MAX_IDS);

            $btns = [];
            $b = self::prop($m, "b", []);
            foreach (
                array_slice(is_array($b) ? $b : [], 0, self::MAX_BTNS)
                as $label
            ) {
                $btns[] = mb_substr(
                    sanitize_text_field((string) $label),
                    0,
                    60,
                );
            }

            if ("" === $text && !$ids && !$btns) {
                continue;
            }
            $entry = ["r" => $role, "t" => $text];
            if ($ids) {
                $entry["ids"] = $ids;
            }
            if ($btns) {
                $entry["b"] = $btns;
            }
            $msgs[] = $entry;
        }

        return [
            "v" => self::SCHEMA,
            "saved_at" => time(),
            "turn" => max(0, min(100000, (int) self::prop($in, "turn", 0))),
            "ctx" => $ctx,
            "msgs" => $msgs,
        ];
    }

    /** JSON, with the oldest messages dropped until it fits the size cap. */
    private static function encode_capped(array $state): string
    {
        $json = (string) wp_json_encode($state);
        while (strlen($json) > self::MAX_BYTES && count($state["msgs"]) > 1) {
            array_shift($state["msgs"]);
            $json = (string) wp_json_encode($state);
        }
        return $json;
    }
}
