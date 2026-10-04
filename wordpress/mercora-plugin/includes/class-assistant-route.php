<?php
namespace Mercora;

defined("ABSPATH") || exit();

/**
 * Serves the dedicated /assistant route that hosts the Alice UI.
 */
final class Assistant_Route
{
    public const QUERY_VAR = "mercora_assistant";

    public static function init(): void
    {
        add_action("init", [self::class, "add_rewrite"]);
        add_filter(
            "query_vars",
            static fn(array $vars) => [...$vars, self::QUERY_VAR],
        );
        add_action("template_redirect", [self::class, "render"], 0);
    }

    public static function add_rewrite(): void
    {
        add_rewrite_rule(
            '^assistant/?$',
            "index.php?" . self::QUERY_VAR . "=1",
            "top",
        );
    }

    public static function render(): void
    {
        if (!get_query_var(self::QUERY_VAR)) {
            return;
        }

        nocache_headers();
        status_header(200);

        $config = [
            "aliceApi" => defined("MERCORA_BRAIN_PUBLIC_URL")
                ? MERCORA_BRAIN_PUBLIC_URL
                : (defined("MERCORA_BRAIN_URL")
                    ? MERCORA_BRAIN_URL
                    : ""),
            "storeApi" => esc_url_raw(rest_url("wc/store/v1")),
            "mercoraApi" => esc_url_raw(rest_url("mercora/v1")),
            "isLoggedIn" => is_user_logged_in(),
            "brainToken" => defined("MERCORA_BRAIN_TOKEN")
                ? MERCORA_BRAIN_TOKEN
                : "",
            "catalogVersion" => (int) get_option("mercora_catalog_version", 0),
            "nonce" => wp_create_nonce("wc_store_api"),
            "store" => self::build_store_for_js(),
        ];

        wp_enqueue_style(
            "mercora-alice",
            MERCORA_URL . "assets/alice.css",
            [],
            mercora_asset_ver("assets/alice.css"),
        );
        wp_enqueue_script(
            "mercora-alice",
            MERCORA_URL . "assets/alice.js",
            [],
            mercora_asset_ver("assets/alice.js"),
            ["in_footer" => true, "strategy" => "defer"],
        );
        wp_add_inline_script(
            "mercora-alice",
            "window.MERCORA = " . wp_json_encode($config) . ";",
            "before",
        );

        include MERCORA_PATH . "templates/assistant.php";
        exit();
    }

    /**
     * Store identity + FAQ sent to the brain with every turn, so the brain
     * stays generic — it adapts to whatever store sent the request instead
     * of having anything store-specific hardcoded.
     */
    private static function build_store_for_js(): array
    {
        if (!function_exists("wc_get_attribute_taxonomies")) {
            return [
                "name" => get_bloginfo("name"),
                "currency" => "INR",
                "locale" => get_locale(),
                "attributes" => [],
                "faq" => [],
            ];
        }

        $attrs = [];
        foreach (wc_get_attribute_taxonomies() as $tax) {
            $terms = get_terms([
                "taxonomy" => "pa_" . $tax->attribute_name,
                "hide_empty" => true,
            ]);
            $attrs[] = [
                "slug" => $tax->attribute_name,
                "label" => $tax->attribute_label,
                "values" => is_array($terms)
                    ? array_column($terms, "name")
                    : [],
            ];
        }

        return [
            "name" => get_bloginfo("name"),
            "currency" => function_exists("get_woocommerce_currency")
                ? get_woocommerce_currency()
                : "INR",
            "locale" => get_locale(),
            "attributes" => $attrs,
            "faq" => [
                [
                    "topic" => "returns",
                    "answer" => (string) get_option(
                        "mercora_faq_returns",
                        "Unworn items can be returned within 10 days of delivery.",
                    ),
                ],
                [
                    "topic" => "shipping",
                    "answer" => (string) get_option(
                        "mercora_faq_shipping",
                        "Standard delivery takes 3-5 business days.",
                    ),
                ],
                [
                    "topic" => "payments",
                    "answer" => (string) get_option(
                        "mercora_faq_payments",
                        "We accept all major cards and UPI.",
                    ),
                ],
            ],
        ];
    }
}
