<?php
// wordpress/mercora-plugin/includes/class-context-store.php
namespace Mercora;

defined("ABSPATH") || exit();

/**
 * Durable, per-customer, per-TENANT recommendation context in MariaDB.
 *
 * Why a table and not user_meta: the brain serves many stores, and a store's
 * rows must stay identifiable as *that store's* even when two installs share a
 * database (a multisite network, or a staging clone restored next to prod).
 * The tenant id travels with the row, so context is portable and never bleeds
 * between tenants. `base_prefix` (not `prefix`) is deliberate: one table for the
 * whole network, partitioned by tenant_id rather than by table name.
 *
 * Why this is NOT the chat session: `Chat_State` holds the live conversation in
 * the WooCommerce session, which expires after ~48h of inactivity and is wiped
 * at checkout. Both are wrong for recommendations — a customer who just bought
 * headphones is the best person to recommend to. This table survives all of it.
 *
 * Why LONGTEXT and not the JSON type: MariaDB's JSON is a LONGTEXT alias with a
 * CHECK constraint, and `dbDelta` cannot parse it — it would try to re-add the
 * column on every upgrade check. We validate the JSON in PHP instead.
 *
 * Writes are lock-free compare-and-swap (see `mutate`). There is no
 * read-modify-write race: two concurrent turns cannot clobber each other.
 */
final class Context_Store
{
    public const SCHEMA = 1;
    private const DB_VERSION = 1;
    private const DB_OPTION = "mercora_context_db_version";

    /** Keep three episodes: tier 1, tier 2, tier 3. */
    public const KEEP_CATEGORIES = 3;

    /** Stored product ids older than this are treated as intent only, not truth. */
    public const RECO_TTL = 30 * DAY_IN_SECONDS;

    /** Rows untouched for this long are dropped by the daily GC. */
    private const GC_AFTER = 365 * DAY_IN_SECONDS;

    private const MAX_BYTES = 16000;
    /**
     * CAS attempts before giving up. Measured against MariaDB 11 with 12
     * simultaneous writers on one row: the worst case needed 5 attempts and
     * every write landed. A real shopper has one or two tabs, so 8 is deep
     * headroom. (The same test against a plain read-modify-write kept only 4 of
     * the 12 writes — that is the race this replaces.)
     */
    private const MAX_TRIES = 8;

    public static function init(): void
    {
        // An already-active plugin must get the table without being reactivated.
        add_action("plugins_loaded", [self::class, "maybe_install"], 5);

        add_action("init", static function () {
            if (!wp_next_scheduled("mercora_context_gc")) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, "daily", "mercora_context_gc");
            }
        });
        add_action("mercora_context_gc", [self::class, "gc"]);
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->base_prefix . "mercora_context";
    }

    // ───────────────────────────────────────────────────────────────── schema

    public static function maybe_install(): void
    {
        if ((int) get_option(self::DB_OPTION, 0) === self::DB_VERSION) {
            return;
        }
        self::install();
    }

    public static function install(): void
    {
        global $wpdb;
        $table = self::table();
        $collate = $wpdb->get_charset_collate();

        // `rev` is the compare-and-swap token. `user_key` is 190 chars so the
        // composite unique key fits utf8mb4's 767-byte index limit.
        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id VARCHAR(64) NOT NULL,
            user_key VARCHAR(190) NOT NULL,
            rev BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            context_data LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY tenant_user (tenant_id, user_key),
            KEY updated_at (updated_at)
        ) $collate;";

        require_once ABSPATH . "wp-admin/includes/upgrade.php";
        dbDelta($sql);
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    /** Delete context nobody has touched in a year, so the table stays bounded. */
    public static function gc(): void
    {
        global $wpdb;
        $table = self::table();
        $cutoff = gmdate("Y-m-d H:i:s", time() - self::GC_AFTER);
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table WHERE tenant_id = %s AND updated_at < %s LIMIT 500",
                self::tenant_id(),
                $cutoff,
            ),
        );
    }

    // ──────────────────────────────────────────────────────────────── identity

    /**
     * This store's tenant id, as the brain knows it.
     *
     * Set `MERCORA_TENANT_ID` in config.local.php to the id the brain was
     * provisioned with. The host-derived fallback keeps a fresh install working
     * (and stable across restarts) without configuration.
     */
    public static function tenant_id(): string
    {
        static $id = null;
        if (null !== $id) {
            return $id;
        }
        if (defined("MERCORA_TENANT_ID") && MERCORA_TENANT_ID) {
            $id = substr((string) MERCORA_TENANT_ID, 0, 64);
        } else {
            $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            $id = "site_" . substr(sha1($host . "|" . get_current_blog_id()), 0, 24);
        }
        /** Filter the tenant id if the brain keys tenants some other way. */
        $id = substr((string) apply_filters("mercora_tenant_id", $id), 0, 64);
        return $id;
    }

    /**
     * The row key for a customer, or null for guests.
     *
     * Guests get nothing durable on purpose: there is no stable identity to hang
     * it on, and the WooCommerce session already covers the one visit they have.
     */
    public static function user_key(?int $user_id = null): ?string
    {
        $uid = $user_id ?? get_current_user_id();
        return $uid > 0 ? "u:" . $uid : null;
    }

    // ──────────────────────────────────────────────────────────────── defaults

    public static function defaults(): array
    {
        return [
            "v" => self::SCHEMA,
            "category_history" => [],
            "slots" => new \stdClass(),
            "rejected" => [],
            "reco" => null,
            "reco_at" => 0,
        ];
    }

    // ───────────────────────────────────────────────────────────────── reading

    /** @return array{rev:int,data:array} */
    private static function read(string $key): array
    {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT rev, context_data FROM $table WHERE tenant_id = %s AND user_key = %s",
                self::tenant_id(),
                $key,
            ),
            ARRAY_A,
        );
        if (!$row) {
            return ["rev" => 0, "data" => self::defaults()];
        }
        $data = json_decode((string) $row["context_data"], true);
        if (!is_array($data) || (int) ($data["v"] ?? 0) !== self::SCHEMA) {
            $data = self::defaults();
        }
        return ["rev" => (int) $row["rev"], "data" => $data + self::defaults()];
    }

    /** The stored context for a customer (defaults when there is none). */
    public static function get(?int $user_id = null): array
    {
        $key = self::user_key($user_id);
        return $key ? self::read($key)["data"] : self::defaults();
    }

    // ───────────────────────────────────────────────────────────────── writing

    /**
     * Apply a mutation atomically — compare-and-swap, no locks, no transaction.
     *
     *   1. `INSERT IGNORE` materialises the row. Atomic: the UNIQUE key makes a
     *      concurrent second insert a silent no-op instead of a duplicate.
     *   2. Read `rev` with the data.
     *   3. `UPDATE ... WHERE rev = <the rev we read>`. Exactly one of two racing
     *      writers matches; the other gets 0 affected rows.
     *   4. The loser re-reads and re-runs the callback against the winner's data,
     *      so both mutations compose instead of one overwriting the other.
     *
     * This is why there is no read-modify-write race. It also needs no
     * transaction, so it cannot leak an open transaction into the connection
     * WordPress shares with the rest of the request.
     *
     * @param callable(array):?array $fn Returns the new context, or null for "no change".
     */
    public static function mutate(?int $user_id, callable $fn): ?array
    {
        global $wpdb;
        $key = self::user_key($user_id);
        if (!$key) {
            return null; // guests: nothing durable to write
        }

        $table = self::table();
        $tenant = self::tenant_id();

        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO $table (tenant_id, user_key, rev, context_data, updated_at)
                 VALUES (%s, %s, 0, %s, %s)",
                $tenant,
                $key,
                (string) wp_json_encode(self::defaults()),
                current_time("mysql", true),
            ),
        );

        for ($try = 0; $try < self::MAX_TRIES; $try++) {
            $current = self::read($key);
            $next = $fn($current["data"]);
            if (!is_array($next)) {
                return $current["data"]; // callback decided nothing changed
            }

            $next["v"] = self::SCHEMA;
            $json = self::encode_capped($next);

            $ok = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table SET context_data = %s, rev = rev + 1, updated_at = %s
                     WHERE tenant_id = %s AND user_key = %s AND rev = %d",
                    $json,
                    current_time("mysql", true),
                    $tenant,
                    $key,
                    $current["rev"],
                ),
            );

            if (1 === $ok) {
                return $next;
            }
            // 0 rows: someone else won this round. Back off a hair so two
            // writers do not keep colliding in lockstep, then re-read and
            // re-apply the callback on top of the winner's data.
            usleep(random_int(500, 4000));
        }

        // Recommendations are advisory — never fail a shopper's turn over them.
        if (function_exists("wc_get_logger")) {
            wc_get_logger()->warning(
                "mercora: context CAS gave up after " . self::MAX_TRIES . " tries",
                ["source" => "mercora"],
            );
        }
        return null;
    }

    /**
     * The single write entry point, called once per turn.
     *
     * Everything it merges is append-style (history pushed, slots merged,
     * rejected unioned), which is what makes the CAS retry safe: re-running it
     * against a newer base produces the union, never a rollback.
     */
    public static function record_turn(
        ?int $user_id,
        array $category_history,
        $slots,
        array $rejected,
        $reco,
    ): ?array {
        $history = self::clean_history($category_history);
        $slots = self::clean_slots($slots);
        $rejected = self::clean_ids($rejected, 40);
        $reco = self::clean_reco($reco);

        if (!$history && !$slots && !$rejected && null === $reco) {
            return null; // nothing worth a write
        }

        return self::mutate($user_id, static function (array $ctx) use (
            $history,
            $slots,
            $rejected,
            $reco,
        ) {
            // History is authoritative: the brain already merged it this turn.
            if ($history) {
                $ctx["category_history"] = $history;
            }
            if ($slots) {
                $ctx["slots"] = (object) array_merge(
                    (array) ($ctx["slots"] ?? []),
                    $slots,
                );
            }
            if ($rejected) {
                $ctx["rejected"] = array_slice(
                    array_values(
                        array_unique(
                            array_merge(
                                self::clean_ids($ctx["rejected"] ?? [], 40),
                                $rejected,
                            ),
                        ),
                    ),
                    -40,
                );
            }
            if (null !== $reco) {
                $ctx["reco"] = $reco;
                $ctx["reco_at"] = time();
            }
            return $ctx;
        });
    }

    // ────────────────────────────────────────────────────────────── sanitising

    /** @return string[] */
    public static function clean_history($raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $slug) {
            $slug = sanitize_title((string) $slug);
            if ("" !== $slug && !in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }
        return array_slice($out, 0, self::KEEP_CATEGORIES);
    }

    /** @return int[] */
    public static function clean_ids($raw, int $max): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return array_slice($out, 0, $max);
    }

    /** Only the slots that shape a recommendation; anything else is chat state. */
    private static function clean_slots($raw): array
    {
        $in = is_object($raw) ? get_object_vars($raw) : (is_array($raw) ? $raw : []);
        $out = [];
        if (isset($in["cat"]) && is_string($in["cat"])) {
            $out["cat"] = sanitize_title($in["cat"]);
        }
        foreach (["min_price", "max_price"] as $k) {
            if (isset($in[$k]) && is_numeric($in[$k])) {
                $out[$k] = max(0, (int) $in[$k]);
            }
        }
        return $out;
    }

    /**
     * Validate a `reco` payload into exactly the shape the renderer expects.
     *
     * This is a trust boundary: the payload reaches us through the shopper's
     * browser, so it is treated as untrusted input. Ids are bounded integers,
     * categories become slugs, and the renderer still checks every id against
     * live products — the worst a tampered payload can do is reorder that one
     * customer's own homepage.
     */
    public static function clean_reco($raw): ?array
    {
        $in = is_object($raw) ? get_object_vars($raw) : $raw;
        if (!is_array($in) || empty($in["tiers"])) {
            return null;
        }
        $tiers_in = is_object($in["tiers"])
            ? get_object_vars($in["tiers"])
            : $in["tiers"];
        if (!is_array($tiers_in)) {
            return null;
        }

        $tiers = [];
        for ($n = 1; $n <= self::KEEP_CATEGORIES; $n++) {
            $k = "tier_$n";
            $t = $tiers_in[$k] ?? null;
            $t = is_object($t) ? get_object_vars($t) : $t;
            $cat = is_array($t) ? sanitize_title((string) ($t["category"] ?? "")) : "";
            $ids = is_array($t) ? self::clean_ids($t["product_ids"] ?? [], 12) : [];
            $tiers[$k] = [
                "category" => "" !== $cat ? $cat : null,
                "product_ids" => $ids,
            ];
        }

        // "You may also like": complementary categories + the inferred plan.
        $al = $tiers_in["also_like"] ?? null;
        $al = is_object($al) ? get_object_vars($al) : $al;
        if (is_array($al)) {
            $cats = array_slice(
                array_values(array_unique(array_filter(array_map(
                    "sanitize_title",
                    is_array($al["categories"] ?? null) ? $al["categories"] : [],
                )))),
                0,
                4,
            );
            $ids = self::clean_ids($al["product_ids"] ?? [], 12);
            if ($cats || $ids) {
                $title = sanitize_text_field((string) ($al["title"] ?? ""));
                $tiers["also_like"] = [
                    "category" => null,
                    "categories" => $cats,
                    "title" => "" !== $title ? mb_substr($title, 0, 60) : null,
                    "product_ids" => $ids,
                ];
            }
        }

        $any = false;
        foreach ($tiers as $t) {
            if ($t["product_ids"] || $t["category"]) {
                $any = true;
                break;
            }
        }
        if (!$any) {
            return null;
        }

        return [
            "v" => 1,
            "category_history" => self::clean_history($in["category_history"] ?? []),
            "tiers" => $tiers,
        ];
    }

    private static function encode_capped(array $ctx): string
    {
        $json = (string) wp_json_encode($ctx);
        if (strlen($json) <= self::MAX_BYTES) {
            return $json;
        }
        // Shed the least valuable thing first, then the ids themselves.
        $ctx["rejected"] = array_slice($ctx["rejected"] ?? [], -10);
        $json = (string) wp_json_encode($ctx);
        if (strlen($json) > self::MAX_BYTES) {
            $ctx["reco"] = null;
            $json = (string) wp_json_encode($ctx);
        }
        return $json;
    }
}
