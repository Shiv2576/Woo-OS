<?php
// wordpress/mercora-plugin/includes/class-mcp-rest.php
namespace Mercora;

defined("ABSPATH") || exit();

/**
 * Publishes the MCP server at  /wp-json/mercora/v1/mcp  and guards it.
 *
 * Optional constants in config.local.php:
 *   MERCORA_MCP_TOKEN     require "Authorization: Bearer <token>" (leave unset for a public read-only server)
 *   MERCORA_MCP_LOG       true = write one JSON log line per tool call to the PHP error log
 *   MERCORA_MCP_DISABLED  true = switch the endpoint off completely
 *
 * Extension points:
 *   do_action( 'mercora_mcp_register_tools', $server )   add tools (checkout tools will plug in here)
 *   do_action( 'mercora_mcp_call', $event )              monitoring (tool, ok, ms, channel, client, store, request_id)
 *   apply_filters( 'mercora_mcp_rate_limit', 120 )       requests per minute per client IP, 0 = unlimited
 *   apply_filters( 'mercora_mcp_allowed_origins', [] )   extra browser origins allowed to call the endpoint
 */
final class Mcp_Rest
{
    private const ROUTE = "/mercora/v1/mcp";

    private static ?Mcp_Server $server = null;
    private static string $request_id = "";

    public static function init(): void
    {
        add_action("rest_api_init", [self::class, "register"]);
        add_filter("rest_pre_serve_request", [self::class, "serve_raw"], 20, 3);
    }

    public static function register(): void
    {
        register_rest_route("mercora/v1", "/mcp", [
            "methods" => ["GET", "POST", "DELETE"],
            "callback" => [self::class, "handle"],
            "permission_callback" => [self::class, "authorize"],
        ]);
    }

    // ───────────────────────────────────────────────────────────── guarding

    /** @return true|\WP_Error */
    public static function authorize(\WP_REST_Request $request)
    {
        if (defined("MERCORA_MCP_DISABLED") && MERCORA_MCP_DISABLED) {
            return new \WP_Error("mercora_mcp_disabled", "Not found.", [
                "status" => 404,
            ]);
        }

        // Browsers (and DNS-rebinding attacks) send an Origin header; server-to-server hosts usually don't.
        $origin = (string) $request->get_header("origin");
        if ("" !== $origin && !self::origin_allowed($origin)) {
            return new \WP_Error("mercora_mcp_origin", "Origin not allowed.", [
                "status" => 403,
            ]);
        }

        $token = defined("MERCORA_MCP_TOKEN") ? (string) MERCORA_MCP_TOKEN : "";
        if ("" !== $token) {
            $given = "";
            if (
                preg_match(
                    '/^Bearer\s+(.+)$/i',
                    (string) $request->get_header("authorization"),
                    $m,
                )
            ) {
                $given = trim($m[1]);
            }
            if (!hash_equals($token, $given)) {
                header('WWW-Authenticate: Bearer realm="mercora"');
                return new \WP_Error("mercora_mcp_auth", "Unauthorized.", [
                    "status" => 401,
                ]);
            }
        }

        $limit = (int) apply_filters("mercora_mcp_rate_limit", 120);
        if ($limit > 0 && !self::within_rate_limit($limit)) {
            header("Retry-After: 60");
            return new \WP_Error("mercora_mcp_rate", "Too many requests.", [
                "status" => 429,
            ]);
        }
        return true;
    }

    private static function origin_allowed(string $origin): bool
    {
        $host = wp_parse_url($origin, PHP_URL_HOST);
        $site = wp_parse_url(home_url(), PHP_URL_HOST);
        if ($host && $site && 0 === strcasecmp($host, $site)) {
            return true;
        }
        return in_array(
            rtrim($origin, "/"),
            (array) apply_filters("mercora_mcp_allowed_origins", []),
            true,
        );
    }

    private static function client_ip(): string
    {
        $ip = isset($_SERVER["REMOTE_ADDR"])
            ? sanitize_text_field(wp_unslash($_SERVER["REMOTE_ADDR"]))
            : "";
        return (string) apply_filters("mercora_mcp_client_ip", $ip);
    }

    private static function within_rate_limit(int $limit): bool
    {
        $key =
            "mercora_mcp_rl_" .
            substr(hash("sha256", self::client_ip()), 0, 16) .
            "_" .
            (int) floor(time() / 60);
        $n = (int) get_transient($key);
        if ($n >= $limit) {
            return false;
        }
        set_transient($key, $n + 1, 120);
        return true;
    }

    // ───────────────────────────────────────────────────────────── serving

    public static function handle(\WP_REST_Request $request)
    {
        self::$request_id = wp_generate_uuid4();

        $out = self::server()->handle(
            $request->get_method(),
            (string) $request->get_body(),
            [
                "mcp-protocol-version" => $request->get_header(
                    "mcp-protocol-version",
                ),
                "mcp-method" => $request->get_header("mcp-method"),
                "mcp-name" => $request->get_header("mcp-name"),
            ],
        );

        $response = new \WP_REST_Response($out["body"], $out["status"]);
        foreach ($out["headers"] as $name => $value) {
            $response->header($name, $value);
        }
        $response->header("Cache-Control", "no-store");
        $response->header("X-Mercora-Request-Id", self::$request_id);
        return $response;
    }

    /**
     * MCP wants the JSON-RPC body sent as-is. WordPress would JSON-encode our already-encoded
     * string a second time, so for this route (and only for successful responses) we print it raw.
     * Error responses from the guards above still go through WordPress' normal formatting.
     */
    public static function serve_raw($served, $result, $request)
    {
        if (
            self::ROUTE !== $request->get_route() ||
            !($result instanceof \WP_REST_Response)
        ) {
            return $served;
        }
        $data = $result->get_data();
        if (!is_string($data)) {
            return $served;
        }
        echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-RPC, encoded by Mcp_Server
        return true;
    }

    // ───────────────────────────────────────────────────────────── wiring

    private static function server(): Mcp_Server
    {
        if (null !== self::$server) {
            return self::$server;
        }
        $store = (string) get_bloginfo("name");
        $server = new Mcp_Server(
            "mercora",
            $store . " shopping tools",
            defined("MERCORA_VERSION") ? (string) MERCORA_VERSION : "0.1.0",
            "You are connected to {$store}'s read-only product tools. Search with concrete product words, then call " .
                "get_product for details and options. Prices are in the store currency. State only facts the tools " .
                "returned: never invent products, prices, stock or policies. If nothing matches, say so and offer to try " .
                "other words or a category. These tools cannot place orders; to buy, give the shopper the product link.",
        );
        Mcp_Tools::register($server);
        $server->observe([self::class, "log_call"]);

        do_action("mercora_mcp_register_tools", $server);

        self::$server = $server;
        return $server;
    }

    public static function log_call(array $event): void
    {
        $event += [
            "channel" => "mcp",
            "store" => wp_parse_url(home_url(), PHP_URL_HOST),
            "request_id" => self::$request_id,
            "client" => substr(
                sanitize_text_field($_SERVER["HTTP_USER_AGENT"] ?? ""),
                0,
                80,
            ),
            "ip" => substr(
                hash("sha256", self::client_ip() . wp_salt()),
                0,
                12,
            ), // never the raw address
        ];
        do_action("mercora_mcp_call", $event);

        if (defined("MERCORA_MCP_LOG") && MERCORA_MCP_LOG) {
            error_log("[mercora-mcp] " . wp_json_encode($event)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}
