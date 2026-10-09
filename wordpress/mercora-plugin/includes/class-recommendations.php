<?php
// wordpress/mercora-plugin/includes/class-recommendations.php
namespace Mercora;

defined("ABSPATH") || exit();

/**
 * Renders the brain's tiered recommendations on the storefront.
 *
 * Shortcode: [mercora_recommendations]  (also a block-friendly placeholder)
 *
 * HOW THE PAYLOAD IS USED — the important part.
 *
 * `reco.tiers.tier_N.product_ids` is the brain's RANKED INTENT, not a list we
 * can print. The brain works from a catalog snapshot that can lag the store by
 * minutes, so an id may be deleted, drafted, hidden from the catalog, or out of
 * stock by the time a shopper lands on the homepage. So:
 *
 *   1. Every id from all three tiers is validated in ONE query against live
 *      published, catalog-visible, in-stock products.
 *   2. Survivors are re-ordered back into the brain's order (the ranking is the
 *      value — we keep it, we just drop what died).
 *   3. Products are de-duplicated across tiers; the higher tier wins.
 *   4. A tier the brain under-filled, or whose ids all died, is backfilled from
 *      its own `category` slug, then from store bestsellers — so the grid is
 *      never half empty.
 *   5. If the stored payload is older than `Context_Store::RECO_TTL`, the ids
 *      are ignored entirely and the tiers are rebuilt from `category_history`.
 *      Stale ids are the main failure mode of storing ids at all.
 *
 * HOW IT REACHES THE HOMEPAGE — progressive enhancement, not replacement.
 *
 * The front page ships three ordinary WooCommerce sections (Featured, On sale,
 * Bestsellers). That IS the default recommendation context: correct for guests,
 * correct for a shopper who has never talked to Alice, and safe to full-page
 * cache because it is the same for everyone.
 *
 * Each of those sections is tagged `data-mercora-tier="tier_1|2|3"` and contains
 * two children: `[data-mercora-default]` (the Woo shortcode output, visible) and
 * `[data-mercora-slot]` (empty, hidden). `assets/recommendations.js` fetches
 * `mercora/v1/recommendations` once, and for every tier that came back with
 * products it fills the slot, swaps the heading, and hides the default.
 *
 * A fourth section, `also_like` (default: Bestsellers), is "You may also like":
 * not more of the same category, but OTHER categories the shopper's inferred plan
 * needs (chocolate + hiking boots → tents, backpacks). The brain picks them; see
 * `brain/app/graph/also_like.py`.
 *
 * So a tier only ever changes when there is something better to show. No context
 * → the defaults simply stay. No empty states, no layout shift, and the cached
 * HTML is never personalised — the personalisation happens after it loads.
 */
final class Recommendations
{
    /** Matches the `[products limit="8"]` grid these tiers replace. */
    private const PER_TIER = 8;

    /** Fallback headings when the category has no readable term name. */
    private const TITLES = [
        "tier_1" => "Picked from your last chat",
        "tier_2" => "You were also looking at",
        "tier_3" => "More ideas for you",
    ];

    private const SUBTITLES = [
        "tier_1" => "Based on what you just asked Alice.",
        "tier_2" => "The topic before that.",
        "tier_3" => "Still on your mind from earlier.",
    ];

    public static function init(): void
    {
        add_action("rest_api_init", [self::class, "register_routes"]);
        add_action("wp_enqueue_scripts", [self::class, "enqueue"]);
    }

    // ───────────────────────────────────────────────────────────────── rest

    public static function register_routes(): void
    {
        register_rest_route("mercora/v1", "/recommendations", [
            "methods" => "GET",
            // Logged-in only, and always the CURRENT user: the route takes no
            // user parameter, so there is nothing to tamper with.
            "permission_callback" => static fn() => is_user_logged_in(),
            "callback" => [self::class, "rest_get"],
        ]);
    }

    public static function rest_get()
    {
        $resp = rest_ensure_response(["tiers" => self::tiers()]);
        $resp->header("Cache-Control", "no-store, private");
        return $resp;
    }

    // ───────────────────────────────────────────────────────────── rendering

    /**
     * Load the upgrader on the front page, for logged-in shoppers only.
     *
     * Guests have no durable context, so there is nothing to fetch and the
     * defaults are already the right answer — don't ship them the request.
     */
    public static function enqueue(): void
    {
        if (!is_front_page() || !is_user_logged_in()) {
            return;
        }

        wp_enqueue_style(
            "mercora-recommendations",
            MERCORA_URL . "assets/recommendations.css",
            [],
            mercora_asset_ver("assets/recommendations.css"),
        );

        wp_enqueue_script(
            "mercora-recommendations",
            MERCORA_URL . "assets/recommendations.js",
            [],
            mercora_asset_ver("assets/recommendations.js"),
            ["in_footer" => true, "strategy" => "defer"],
        );

        wp_add_inline_script(
            "mercora-recommendations",
            "window.MERCORA_RECO = " .
                wp_json_encode([
                    "endpoint" => esc_url_raw(
                        rest_url("mercora/v1/recommendations"),
                    ),
                    "nonce" => wp_create_nonce("wp_rest"),
                ]) .
                ";",
            "before",
        );
    }

    // ─────────────────────────────────────────────────────── payload → tiers

    /**
     * The whole resolution pipeline. Returns render-ready tiers.
     *
     * @return array<int,array{key:string,title:string,products:array}>
     */
    public static function tiers(?int $user_id = null): array
    {
        $ctx = Context_Store::get($user_id);
        $history = Context_Store::clean_history($ctx["category_history"] ?? []);
        $reco = is_array($ctx["reco"] ?? null) ? $ctx["reco"] : null;
        $fresh = (int) ($ctx["reco_at"] ?? 0) > time() - Context_Store::RECO_TTL;

        // Step 5: a stale payload keeps its categories but loses its ids.
        $wanted = []; // tier key => ranked ids from the brain
        $cats = []; // tier key => category slug
        for ($n = 1; $n <= Context_Store::KEEP_CATEGORIES; $n++) {
            $k = "tier_$n";
            $t = $reco["tiers"][$k] ?? null;
            $cats[$k] = $t["category"] ?? ($history[$n - 1] ?? null);
            $wanted[$k] =
                $fresh && is_array($t["product_ids"] ?? null)
                    ? $t["product_ids"]
                    : [];
        }

        if (!array_filter($cats) && !array_filter($wanted)) {
            return []; // no context at all — render nothing rather than noise
        }

        // "You may also like": OTHER categories the shopper's plan needs. Same
        // freshness rule — a stale payload keeps its categories, loses its ids.
        $also = $reco["tiers"]["also_like"] ?? null;
        $also = is_array($also) && $also["categories"] ? $also : null;
        $also_ids = $also && $fresh ? (array) ($also["product_ids"] ?? []) : [];

        // Steps 1 + 2: validate every id from every tier in ONE query.
        $live = self::live_ids(
            array_merge(...array_values($wanted), ...[$also_ids]),
        );

        $used = [];
        $out = [];
        foreach ($wanted as $k => $ids) {
            // Step 3: brain's order, minus the dead, minus anything a higher
            // tier already claimed.
            $picked = [];
            foreach ($ids as $id) {
                if (isset($live[$id]) && !isset($used[$id])) {
                    $picked[] = $id;
                    $used[$id] = true;
                }
                if (count($picked) >= self::PER_TIER) {
                    break;
                }
            }

            // Step 4: backfill an under-filled tier.
            if (count($picked) < self::PER_TIER && $cats[$k]) {
                foreach (
                    self::by_category($cats[$k], self::PER_TIER * 3, $used)
                    as $id
                ) {
                    $picked[] = $id;
                    $used[$id] = true;
                    if (count($picked) >= self::PER_TIER) {
                        break;
                    }
                }
            }
            if (count($picked) < self::PER_TIER && "tier_1" === $k) {
                // Tier 1 is the one the shopper will actually look at: never
                // leave it empty. Bestsellers are the honest last resort.
                foreach (self::bestsellers(self::PER_TIER * 3, $used) as $id) {
                    $picked[] = $id;
                    $used[$id] = true;
                    if (count($picked) >= self::PER_TIER) {
                        break;
                    }
                }
            }

            $products = array_values(array_filter(array_map([self::class, "card"], $picked)));
            if ($products) {
                $out[] = [
                    "key" => $k,
                    "products" => $products,
                ] + self::labels($k, $cats[$k]);
            }
        }

        if ($also) {
            $tier = self::also_like($also, $also_ids, $live, $used);
            if ($tier) {
                $out[] = $tier;
            }
        }

        return $out;
    }

    /**
     * The "You may also like" tier: the brain's ranked ids, then a round-robin
     * backfill across its complementary categories so one category can't fill
     * the whole row. No bestseller fallback — if the plan's categories are
     * empty, the section's own default (Bestsellers) is the honest answer.
     */
    private static function also_like(array $also, array $ids, array $live, array &$used): ?array
    {
        $picked = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($live[$id]) && !isset($used[$id])) {
                $picked[] = $id;
                $used[$id] = true;
            }
            if (count($picked) >= self::PER_TIER) {
                break;
            }
        }

        if (count($picked) < self::PER_TIER) {
            $pools = [];
            foreach ($also["categories"] as $slug) {
                $pools[] = self::by_category($slug, self::PER_TIER, $used);
            }
            for ($row = 0; $row < self::PER_TIER && count($picked) < self::PER_TIER; $row++) {
                foreach ($pools as $pool) {
                    $id = $pool[$row] ?? null;
                    if ($id && !isset($used[$id])) {
                        $picked[] = $id;
                        $used[$id] = true;
                        if (count($picked) >= self::PER_TIER) {
                            break;
                        }
                    }
                }
            }
        }

        $products = array_values(array_filter(array_map([self::class, "card"], $picked)));
        if (!$products) {
            return null;
        }

        $mission = trim((string) ($also["title"] ?? ""));
        return [
            "key" => "also_like",
            "products" => $products,
            "title" => "You may also like",
            "subtitle" => "" !== $mission
                ? sprintf("Picked for your %s.", mb_strtolower($mission))
                : "Goes well with what you've been looking at.",
        ];
    }

    /**
     * The heading a personalised tier carries.
     *
     * Named after the actual category term when we can resolve it ("More Hiking
     * Boots for you"), because that is the sentence that tells the shopper why
     * these products are here. Falls back to a generic line otherwise.
     *
     * @return array{title:string,subtitle:string}
     */
    private static function labels(string $key, ?string $cat): array
    {
        $title = self::TITLES[$key] ?? "Recommended for you";

        if ($cat) {
            $term = get_term_by("slug", $cat, "product_cat");
            if ($term && !is_wp_error($term)) {
                $title =
                    "tier_1" === $key
                        ? sprintf("More %s for you", $term->name)
                        : sprintf("Back to %s", $term->name);
            }
        }

        return [
            "title" => $title,
            "subtitle" => self::SUBTITLES[$key] ?? "",
        ];
    }

    // ─────────────────────────────────────────────────────────────── queries

    /**
     * Which of these ids are still purchasable right now.
     *
     * One query for all three tiers, not one per tier. `post__in` plus the
     * product_visibility exclusions is what drops deleted, drafted, hidden and
     * out-of-stock products that the brain's snapshot still believed in.
     *
     * @param int[] $ids
     * @return array<int,true>
     */
    private static function live_ids(array $ids): array
    {
        $ids = Context_Store::clean_ids($ids, 48);
        if (!$ids) {
            return [];
        }
        $found = get_posts([
            "post_type" => "product",
            "post_status" => "publish",
            "post__in" => $ids,
            "posts_per_page" => count($ids),
            "fields" => "ids",
            "ignore_sticky_posts" => true,
            "no_found_rows" => true,
            "suppress_filters" => false,
            "tax_query" => self::visibility_tax_query(),
        ]);
        return array_fill_keys(array_map("intval", $found), true);
    }

    /** @return int[] */
    private static function by_category(string $slug, int $limit, array $exclude): array
    {
        $tax = self::visibility_tax_query();
        $tax[] = [
            "taxonomy" => "product_cat",
            "field" => "slug",
            "terms" => [$slug],
        ];
        return array_map(
            "intval",
            get_posts([
                "post_type" => "product",
                "post_status" => "publish",
                "posts_per_page" => $limit,
                "fields" => "ids",
                "ignore_sticky_posts" => true,
                "no_found_rows" => true,
                "post__not_in" => array_keys($exclude),
                "orderby" => "meta_value_num",
                "meta_key" => "total_sales",
                "order" => "DESC",
                "tax_query" => $tax,
            ]),
        );
    }

    /** @return int[] */
    private static function bestsellers(int $limit, array $exclude): array
    {
        return array_map(
            "intval",
            get_posts([
                "post_type" => "product",
                "post_status" => "publish",
                "posts_per_page" => $limit,
                "fields" => "ids",
                "ignore_sticky_posts" => true,
                "no_found_rows" => true,
                "post__not_in" => array_keys($exclude),
                "orderby" => "meta_value_num",
                "meta_key" => "total_sales",
                "order" => "DESC",
                "tax_query" => self::visibility_tax_query(),
            ]),
        );
    }

    /** Hidden-from-catalog and out-of-stock products are never recommended. */
    private static function visibility_tax_query(): array
    {
        // "outofstock" is excluded regardless of the store's catalog setting:
        // a recommendation the shopper cannot buy is worse than one fewer card.
        $terms = ["exclude-from-catalog", "outofstock"];
        return [
            "relation" => "AND",
            [
                "taxonomy" => "product_visibility",
                "field" => "name",
                "terms" => $terms,
                "operator" => "NOT IN",
            ],
        ];
    }

    /** Minimal card data. Prices are read live, never from the payload. */
    private static function card(int $id): ?array
    {
        $p = function_exists("wc_get_product") ? wc_get_product($id) : null;
        if (!$p || !$p->is_visible()) {
            return null;
        }
        $img = wp_get_attachment_image_url($p->get_image_id(), "woocommerce_thumbnail");
        return [
            "id" => $id,
            "name" => $p->get_name(),
            "url" => $p->get_permalink(),
            "price" => self::price_text($p),
            "image" => $img ?: null,
        ];
    }

    /**
     * `get_price_html()` as plain text a JS string can hold.
     *
     * Two things make a naive `wp_strip_all_tags()` wrong here:
     *
     * 1. For a variable product WooCommerce appends an accessibility span —
     *    `<span class="screen-reader-text">Price range: X through Y</span>` —
     *    which is visually hidden in HTML but survives tag-stripping, giving
     *    "₹2,399.00 – ₹2,569.00Price range: ₹2,399.00 through ₹2,569.00".
     *    It is removed with its contents BEFORE stripping. Also dropped: the
     *    `<del>`/`<ins>` of a sale price, where the struck-out original would
     *    read as a second price once the tags were gone.
     *
     * 2. The markup is entity-encoded (`&#8377;` for ₹, `&ndash;` for –). The
     *    renderer escapes what it is given, so an un-decoded entity would print
     *    literally as "&#8377;". Decode once here, and the client's escaping
     *    then produces the real glyph.
     */
    private static function price_text($product): string
    {
        $html = (string) $product->get_price_html();

        // Visually hidden accessibility text duplicates the visible price.
        $html = preg_replace(
            '#<span[^>]*class=["\'][^"\']*screen-reader-text[^"\']*["\'][^>]*>.*?</span>#is',
            "",
            $html,
        );
        // On a sale price, keep only the current price, not the struck-out one.
        $html = preg_replace("#<del\b[^>]*>.*?</del>#is", "", (string) $html);

        $text = wp_strip_all_tags((string) $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, "UTF-8");

        // Collapse the whitespace that stripping the tags leaves behind.
        return trim((string) preg_replace('/\s+/u', " ", $text));
    }
}
