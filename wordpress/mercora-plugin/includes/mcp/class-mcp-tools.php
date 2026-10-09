<?php
// wordpress/mercora-plugin/includes/class-mcp-tools.php
namespace Mercora;

defined("ABSPATH") || exit();

/**
 * The read-only tools an outside AI can call. Everything here reads WooCommerce
 * directly, so prices and stock are always the store's own truth. Nothing here
 * can change the store.
 *
 * Product text (names, descriptions) is store-controlled data, never instructions:
 * it is stripped of markup and length-limited before it is returned.
 */
final class Mcp_Tools
{
    private const LOW_STOCK_AT = 5;

    public static function register(Mcp_Server $server): void
    {
        $read_only = [
            "readOnlyHint" => true,
            "destructiveHint" => false,
            "idempotentHint" => true,
            "openWorldHint" => false,
        ];

        $server->add_tool(
            "search_products",
            "Search products",
            "Find products in this store by words, optionally inside a category or price range. " .
                'It matches the store\'s own product names, descriptions and categories, and EVERY word must appear, ' .
                'so use concrete product words ("dark chocolate", "hiking boots") rather than moods ("sweet", "cozy"). ' .
                "Returns up to 20 products, most relevant first. If nothing matches the answer says so: " .
                "try fewer or different words, or call list_categories and browse a category.",
            [
                "type" => "object",
                "properties" => [
                    "query" => [
                        "type" => "string",
                        "maxLength" => 120,
                        "description" =>
                            'Words to look for, e.g. "spicy chips". May be empty when a category is given.',
                    ],
                    "category" => [
                        "type" => "string",
                        "maxLength" => 80,
                        "description" =>
                            "A category name or slug from list_categories.",
                    ],
                    "min_price" => [
                        "type" => "number",
                        "minimum" => 0,
                        "description" => "Lowest price, in the store currency.",
                    ],
                    "max_price" => [
                        "type" => "number",
                        "minimum" => 0,
                        "description" =>
                            "Highest price, in the store currency.",
                    ],
                    "in_stock_only" => [
                        "type" => "boolean",
                        "default" => true,
                        "description" =>
                            "Only products that can be bought now.",
                    ],
                    "limit" => [
                        "type" => "integer",
                        "minimum" => 1,
                        "maximum" => 20,
                        "default" => 8,
                        "description" => "How many products to return.",
                    ],
                ],
            ],
            [self::class, "search_products"],
            $read_only,
        );

        $server->add_tool(
            "get_product",
            "Get product details",
            "Full details for one product: description, price, live stock, attributes and, for products with " .
                "options such as size or colour, every variation with its own stock. Use the id from search_products.",
            [
                "type" => "object",
                "properties" => [
                    "product_id" => [
                        "type" => "integer",
                        "minimum" => 1,
                        "description" => "The product id from search_products.",
                    ],
                ],
                "required" => ["product_id"],
            ],
            [self::class, "get_product"],
            $read_only,
        );

        $server->add_tool(
            "list_categories",
            "List categories",
            'The store\'s product categories with how many products each holds. Use it to browse, ' .
                "or to pick a category name for search_products.",
            ["type" => "object", "properties" => new \stdClass()],
            [self::class, "list_categories"],
            $read_only,
        );

        $server->add_tool(
            "get_store_info",
            "Get store information",
            'The store\'s name, currency, web address and its published policies (returns, shipping, payments). ' .
                "Quote these exactly; never invent a policy.",
            ["type" => "object", "properties" => new \stdClass()],
            [self::class, "get_store_info"],
            $read_only,
        );
    }

    // ───────────────────────────────────────────────────────────── tools

    public static function search_products(array $a): array
    {
        $query = trim((string) ($a["query"] ?? ""));
        $cat = trim((string) ($a["category"] ?? ""));
        if ("" === $query && "" === $cat) {
            throw new Mcp_Tool_Error(
                "Give a query, a category, or both. Call list_categories to see the categories.",
            );
        }

        $limit = (int) ($a["limit"] ?? 8);
        $in_stock = (bool) ($a["in_stock_only"] ?? true);
        $min = isset($a["min_price"]) ? (float) $a["min_price"] : null;
        $max = isset($a["max_price"]) ? (float) $a["max_price"] : null;

        $args = [
            "status" => "publish",
            "limit" => 60,
            "orderby" => "" !== $query ? "relevance" : "popularity",
            "order" => "DESC",
            "return" => "objects",
        ];
        if ("" !== $query) {
            $args["s"] = $query;
        }
        if ($in_stock) {
            $args["stock_status"] = "instock";
        }
        if ("" !== $cat) {
            $slug = self::category_slug($cat);
            if (null === $slug) {
                throw new Mcp_Tool_Error(
                    "No category called \"{$cat}\". Call list_categories for the exact names.",
                );
            }
            $args["category"] = [$slug];
        }

        $found = [];
        foreach ((array) wc_get_products($args) as $p) {
            [$lo, $hi] = self::price_range($p);
            if (null !== $min && $hi < $min) {
                continue;
            }
            if (null !== $max && $lo > $max) {
                continue;
            }
            if ($in_stock && !$p->is_in_stock()) {
                continue;
            }
            $found[] = $p;
        }

        $out = [
            "currency" => get_woocommerce_currency(),
            "query" => $query,
            "count" => min(count($found), $limit),
            "more_available" => count($found) > $limit,
            "products" => array_map(
                [self::class, "summary"],
                array_slice($found, 0, $limit),
            ),
        ];
        if (!$found) {
            $out["note"] =
                "Nothing in this store matched every word" .
                (null !== $max || null !== $min
                    ? " within that price range"
                    : "") .
                ". Try fewer or different words, widen the price range, or call list_categories and browse a category.";
        }
        return $out;
    }

    public static function get_product(array $a): array
    {
        $p = wc_get_product((int) $a["product_id"]);
        if (!$p || "publish" !== $p->get_status()) {
            throw new Mcp_Tool_Error(
                "No product with that id. Use an id returned by search_products.",
            );
        }

        $out = self::summary($p);
        $out["currency"] = get_woocommerce_currency();
        $out["description"] = self::plain($p->get_description(), 1500);

        $attributes = [];
        foreach ($p->get_attributes() as $attr) {
            $name = $attr->get_name();
            $label = wc_attribute_label($name);
            $vals = $attr->is_taxonomy()
                ? array_values(
                    (array) wc_get_product_terms($p->get_id(), $name, [
                        "fields" => "names",
                    ]),
                )
                : array_values(
                    array_map("strval", (array) $attr->get_options()),
                );
            if ($vals) {
                $attributes[$label] = array_map(
                    static fn($v) => self::plain((string) $v, 80),
                    $vals,
                );
            }
        }
        if ($attributes) {
            $out["attributes"] = $attributes;
        }

        if ("variable" === $p->get_type()) {
            $variations = [];
            foreach (
                array_slice((array) $p->get_available_variations(), 0, 60)
                as $v
            ) {
                $variations[] = [
                    "id" => (int) $v["variation_id"],
                    "options" => self::variation_options(
                        (array) ($v["attributes"] ?? []),
                    ),
                    "in_stock" => (bool) ($v["is_in_stock"] ?? false),
                    "price" => round(
                        (float) ($v["display_price"] ?? 0),
                        wc_get_price_decimals(),
                    ),
                ];
            }
            $out["variations"] = $variations;
        }
        return $out;
    }

    public static function list_categories(array $a): array
    {
        $terms = get_terms([
            "taxonomy" => "product_cat",
            "hide_empty" => true,
            "number" => 200,
            "orderby" => "count",
            "order" => "DESC",
        ]);
        if (is_wp_error($terms) || !is_array($terms)) {
            $terms = [];
        }
        $slug_by_id = [];
        foreach ($terms as $t) {
            $slug_by_id[(int) $t->term_id] = $t->slug;
        }
        $list = [];
        foreach ($terms as $t) {
            $list[] = [
                "name" => self::plain($t->name, 80),
                "slug" => $t->slug,
                "parent" => $slug_by_id[(int) $t->parent] ?? null,
                "products" => (int) $t->count,
            ];
        }
        return ["categories" => $list];
    }

    public static function get_store_info(array $a): array
    {
        return [
            "name" => get_bloginfo("name"),
            "url" => home_url("/"),
            "currency" => get_woocommerce_currency(),
            "checkout_url" => wc_get_checkout_url(),
            "guest_checkout" =>
                "yes" === get_option("woocommerce_enable_guest_checkout", "no"),
            "policies" => [
                "returns" => (string) get_option(
                    "mercora_faq_returns",
                    "Unworn items can be returned within 10 days of delivery.",
                ),
                "shipping" => (string) get_option(
                    "mercora_faq_shipping",
                    "Standard delivery takes 3-5 business days.",
                ),
                "payments" => (string) get_option(
                    "mercora_faq_payments",
                    "We accept all major cards and UPI.",
                ),
            ],
            "note" =>
                "These tools only look things up. To buy, give the shopper the product link or the checkout address.",
        ];
    }

    // ───────────────────────────────────────────────────────────── helpers

    private static function summary($p): array
    {
        [$lo, $hi] = self::price_range($p);
        $on_sale = (bool) $p->is_on_sale();
        $regular = (float) $p->get_regular_price();
        $qty = $p->get_manage_stock() ? $p->get_stock_quantity() : null;
        $image = $p->get_image_id()
            ? wp_get_attachment_image_url(
                $p->get_image_id(),
                "woocommerce_thumbnail",
            )
            : null;
        $rating = (float) $p->get_average_rating();

        $out = [
            "id" => (int) $p->get_id(),
            "name" => self::plain($p->get_name(), 160),
            "url" => get_permalink($p->get_id()),
            "type" => $p->get_type(),
            "price" => $lo,
            "on_sale" => $on_sale,
            "in_stock" => (bool) $p->is_in_stock(),
            "categories" => array_values(
                (array) wp_get_post_terms($p->get_id(), "product_cat", [
                    "fields" => "names",
                ]),
            ),
            "summary" => self::plain($p->get_short_description(), 220),
        ];
        if ($hi > $lo) {
            $out["price_up_to"] = $hi; // products with options show a range
        }
        if ($on_sale && $regular > $lo) {
            $out["regular_price"] = round($regular, wc_get_price_decimals());
        }
        if (is_int($qty) && $qty > 0 && $qty <= self::LOW_STOCK_AT) {
            $out["low_stock_left"] = $qty;
        }
        if ($rating > 0) {
            $out["rating"] = round($rating, 1);
        }
        if ($image) {
            $out["image"] = $image;
        }
        return $out;
    }

    /** @return array{0:float,1:float} lowest and highest price a buyer could pay */
    private static function price_range($p): array
    {
        $d = wc_get_price_decimals();
        if ("variable" === $p->get_type()) {
            return [
                round((float) $p->get_variation_price("min"), $d),
                round((float) $p->get_variation_price("max"), $d),
            ];
        }
        $v = round((float) $p->get_price(), $d);
        return [$v, $v];
    }

    private static function category_slug(string $c): ?string
    {
        $t = get_term_by("slug", sanitize_title($c), "product_cat");
        if (!$t || is_wp_error($t)) {
            $t = get_term_by("name", $c, "product_cat");
        }
        return $t && !is_wp_error($t) ? $t->slug : null;
    }

    /** Turn {"attribute_pa_color":"brown"} into {"Colour":"Brown"}. */
    private static function variation_options(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $slug) {
            $tax = preg_replace("/^attribute_/", "", (string) $key);
            $label = wc_attribute_label($tax);
            $value = (string) $slug;
            if ("" === $value) {
                $out[$label] = "any";
                continue;
            }
            if (0 === strpos($tax, "pa_")) {
                $term = get_term_by("slug", $value, $tax);
                if ($term && !is_wp_error($term)) {
                    $value = $term->name;
                }
            }
            $out[$label] = self::plain($value, 80);
        }
        return $out;
    }

    /** Store text as plain, length-limited data: no markup, no control characters. */
    private static function plain(string $html, int $max): string
    {
        $text = html_entity_decode(
            wp_strip_all_tags($html),
            ENT_QUOTES | ENT_HTML5,
            "UTF-8",
        );
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', " ", $text);
        $text = trim((string) preg_replace("/\s+/u", " ", $text));
        return mb_strlen($text) > $max
            ? rtrim(mb_substr($text, 0, $max - 1)) . "…"
            : $text;
    }
}
