<?php
// wordpress/mercora-plugin/includes/class-mcp-server.php
namespace Mercora;

defined("ABSPATH") || exit();

/** A problem the CALLER can fix (bad id, no such product). Shown to the model as a tool error. */
final class Mcp_Tool_Error extends \Exception {}

/** A JSON-RPC protocol error (unknown method, unknown tool). */
final class Mcp_Rpc_Error extends \Exception {}

/**
 * A small, stateless MCP server speaking "Streamable HTTP" in its simplest form:
 * every request is one POST carrying JSON-RPC, every answer is one JSON body.
 * No sessions, no streaming, no WordPress code in here, so it can be tested alone.
 *
 * It speaks BOTH protocol eras on the same endpoint:
 *   modern  2026-07-28   no handshake and no session: every request carries its own version in
 *                        _meta and in the MCP-Protocol-Version / Mcp-Method / Mcp-Name headers;
 *                        clients may call server/discover.
 *   legacy  2025-11-25 and earlier   the initialize / initialized handshake (no session id is
 *                        ever issued, which the spec allows).
 * A request is served by whichever era its MCP-Protocol-Version header names.
 *
 * It never calls a model. A host app (Claude, ChatGPT, Gemini, Grok...) shows the
 * tool list to ITS model, and when that model asks for a tool the host POSTs the
 * call here. This class validates the arguments and runs plain code.
 */
final class Mcp_Server
{
    /** Stateless era: no initialize, per-request metadata. */
    public const MODERN = ["2026-07-28"];

    /** Handshake era. Newest first. */
    public const LEGACY = [
        "2025-11-25",
        "2025-06-18",
        "2025-03-26",
        "2024-11-05",
    ];

    /** Everything this server can speak. Extend when new MCP revisions appear. */
    public const SUPPORTED = [
        "2026-07-28",
        "2025-11-25",
        "2025-06-18",
        "2025-03-26",
        "2024-11-05",
    ];

    private const MAX_BODY = 65536;

    private string $name;
    private string $title;
    private string $version;
    private string $instructions;

    /** @var array<string,array> */
    private array $tools = [];

    /** @var callable|null */
    private $observer = null;

    public function __construct(
        string $name,
        string $title,
        string $version,
        string $instructions = "",
    ) {
        $this->name = $name;
        $this->title = $title;
        $this->version = $version;
        $this->instructions = $instructions;
    }

    /** @param array $schema JSON Schema for the arguments (keep it flat and simple). */
    public function add_tool(
        string $name,
        string $title,
        string $description,
        array $schema,
        callable $handler,
        array $annotations = [],
    ): void {
        $this->tools[$name] = compact(
            "name",
            "title",
            "description",
            "schema",
            "handler",
            "annotations",
        );
    }

    /** Called after every tool call with [tool, ok, ms, error]. Used for monitoring. */
    public function observe(callable $fn): void
    {
        $this->observer = $fn;
    }

    /**
     * @param array<string,string|null> $headers lower-case names: 'mcp-protocol-version', 'mcp-method', 'mcp-name'
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function handle(
        string $method,
        string $body,
        array $headers = [],
    ): array {
        $method = strtoupper($method);

        if ("POST" !== $method) {
            // No server-initiated stream and no sessions: GET and DELETE are not offered.
            return $this->http(405, "", ["Allow" => "POST"]);
        }
        if (strlen($body) > self::MAX_BODY) {
            return $this->http(
                413,
                $this->rpc_error(null, -32600, "Request too large"),
            );
        }

        $ver = $headers["mcp-protocol-version"] ?? null;
        if (null !== $ver && "" !== $ver) {
            if (in_array($ver, self::MODERN, true)) {
                return $this->handle_modern($body, (string) $ver, $headers);
            }
            if (!in_array($ver, self::LEGACY, true)) {
                return $this->http(
                    400,
                    $this->rpc_error(
                        null,
                        -32022,
                        "Unsupported protocol version",
                        [
                            "supported" => self::SUPPORTED,
                            "requested" => substr((string) $ver, 0, 40),
                        ],
                    ),
                );
            }
        }
        return $this->handle_legacy($body); // a legacy version, or no header at all
    }

    // ───────────────────────────────────────────────────────────── modern era (2026-07-28)

    private function handle_modern(
        string $body,
        string $ver,
        array $headers,
    ): array {
        $m = json_decode($body, true);
        if (null === $m && JSON_ERROR_NONE !== json_last_error()) {
            return $this->http(
                400,
                $this->rpc_error(null, -32700, "Parse error"),
            );
        }
        // One message per request: a batch (a list) is not allowed in this era.
        if (
            !is_array($m) ||
            [] === $m ||
            array_keys($m) === range(0, count($m) - 1) ||
            ($m["jsonrpc"] ?? null) !== "2.0"
        ) {
            return $this->http(
                400,
                $this->rpc_error(null, -32600, "Invalid request"),
            );
        }
        if (!array_key_exists("id", $m)) {
            return $this->http(202, ""); // a notification: accepted, nothing to say
        }
        $id = $m["id"];
        $rpc = $m["method"] ?? null;
        $params =
            isset($m["params"]) && is_array($m["params"]) ? $m["params"] : [];
        if (null === $id || !is_string($rpc)) {
            return $this->http(
                400,
                $this->rpc_error($id, -32600, "Invalid request"),
            );
        }

        // Headers and body must agree: a gateway may route on one while we act on the other.
        if (($headers["mcp-method"] ?? null) !== $rpc) {
            return $this->http(
                400,
                $this->rpc_error(
                    $id,
                    -32020,
                    "Header mismatch: Mcp-Method is missing or does not match the request method",
                ),
            );
        }
        $meta =
            isset($params["_meta"]) && is_array($params["_meta"])
                ? $params["_meta"]
                : [];
        if (
            ($meta["io.modelcontextprotocol/protocolVersion"] ?? null) !==
            $ver
        ) {
            return $this->http(
                400,
                $this->rpc_error(
                    $id,
                    -32020,
                    "Header mismatch: MCP-Protocol-Version does not match _meta protocolVersion",
                ),
            );
        }
        if ("tools/call" === $rpc) {
            $named = self::decode_header_value($headers["mcp-name"] ?? null);
            if (null === $named || ($params["name"] ?? null) !== $named) {
                return $this->http(
                    400,
                    $this->rpc_error(
                        $id,
                        -32020,
                        "Header mismatch: Mcp-Name is missing or does not match the tool name",
                    ),
                );
            }
        }

        try {
            switch ($rpc) {
                case "server/discover":
                    $result = [
                        "resultType" => "complete",
                        "supportedVersions" => self::SUPPORTED,
                        "capabilities" => ["tools" => new \stdClass()],
                        "_meta" => [
                            "io.modelcontextprotocol/serverInfo" => [
                                "name" => $this->name,
                                "title" => $this->title,
                                "version" => $this->version,
                            ],
                        ],
                        "ttlMs" => 3600000,
                        "cacheScope" => "public",
                    ];
                    if ("" !== $this->instructions) {
                        $result["instructions"] = $this->instructions;
                    }
                    break;
                case "tools/list":
                    $result = [
                        "resultType" => "complete",
                        "tools" => $this->tool_list(),
                        "ttlMs" => 300000,
                        "cacheScope" => "public",
                    ];
                    break;
                case "tools/call":
                    $result =
                        ["resultType" => "complete"] +
                        $this->call_tool($params);
                    break;
                default:
                    // Not implemented here (including ping, initialize, resources/*, prompts/*).
                    return $this->http(
                        404,
                        $this->rpc_error(
                            $id,
                            -32601,
                            "Method not found: " . substr($rpc, 0, 60),
                        ),
                    );
            }
            return $this->http(
                200,
                $this->encode([
                    "jsonrpc" => "2.0",
                    "id" => $id,
                    "result" => $result,
                ]),
            );
        } catch (Mcp_Rpc_Error $e) {
            return $this->http(
                200,
                $this->rpc_error($id, $e->getCode(), $e->getMessage()),
            );
        } catch (\Throwable $e) {
            return $this->http(
                200,
                $this->rpc_error($id, -32603, "Internal error"),
            );
        }
    }

    /** Mcp-Name may arrive as plain ASCII or as =?base64?...?= when it holds unusual characters. */
    private static function decode_header_value(?string $v): ?string
    {
        if (null === $v || "" === $v) {
            return null;
        }
        if (preg_match('/^=\?base64\?(.*)\?=$/', $v, $m)) {
            $d = base64_decode($m[1], true);
            return false === $d ? null : $d;
        }
        return $v;
    }

    // ───────────────────────────────────────────────────────────── legacy era (initialize handshake)

    private function handle_legacy(string $body): array
    {
        $msg = json_decode($body, true);
        if (null === $msg && JSON_ERROR_NONE !== json_last_error()) {
            return $this->http(
                400,
                $this->rpc_error(null, -32700, "Parse error"),
            );
        }
        if (!is_array($msg) || [] === $msg) {
            return $this->http(
                400,
                $this->rpc_error(null, -32600, "Invalid request"),
            );
        }

        $is_batch = array_keys($msg) === range(0, count($msg) - 1);
        $items = $is_batch ? $msg : [$msg];

        $out = [];
        foreach ($items as $item) {
            $r = $this->dispatch($item);
            if (null !== $r) {
                $out[] = $r;
            }
        }

        if (!$out) {
            // only notifications / client responses: nothing to send back
            return $this->http(202, "");
        }
        return $this->http(200, $this->encode($is_batch ? $out : $out[0]));
    }

    // ───────────────────────────────────────────────────────────── JSON-RPC

    private function dispatch($m): ?array
    {
        if (!is_array($m) || ($m["jsonrpc"] ?? null) !== "2.0") {
            return $this->envelope(
                $m["id"] ?? null,
                null,
                -32600,
                "Invalid request",
            );
        }
        $id = $m["id"] ?? null;

        if (!array_key_exists("method", $m)) {
            return null; // a response object sent by the client: ignore
        }
        if (!is_string($m["method"])) {
            return $this->envelope($id, null, -32600, "Invalid request");
        }
        if (null === $id) {
            return null; // notification (e.g. notifications/initialized)
        }

        $params =
            isset($m["params"]) && is_array($m["params"]) ? $m["params"] : [];
        try {
            return [
                "jsonrpc" => "2.0",
                "id" => $id,
                "result" => $this->call_method($m["method"], $params),
            ];
        } catch (Mcp_Rpc_Error $e) {
            return $this->envelope($id, null, $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            return $this->envelope($id, null, -32603, "Internal error");
        }
    }

    private function call_method(string $method, array $params)
    {
        switch ($method) {
            case "initialize":
                $asked = (string) ($params["protocolVersion"] ?? "");
                $info = [
                    "name" => $this->name,
                    "title" => $this->title,
                    "version" => $this->version,
                ];
                $out = [
                    "protocolVersion" => in_array($asked, self::LEGACY, true)
                        ? $asked
                        : self::LEGACY[0],
                    "capabilities" => ["tools" => ["listChanged" => false]],
                    "serverInfo" => $info,
                ];
                if ("" !== $this->instructions) {
                    $out["instructions"] = $this->instructions;
                }
                return $out;

            case "ping":
                return new \stdClass();

            case "tools/list":
                return ["tools" => $this->tool_list()];

            case "tools/call":
                return $this->call_tool($params);
        }
        throw new Mcp_Rpc_Error(
            "Method not found: " . substr($method, 0, 60),
            -32601,
        );
    }

    private function tool_list(): array
    {
        $list = [];
        foreach ($this->tools as $t) {
            $list[] = [
                "name" => $t["name"],
                "title" => $t["title"],
                "description" => $t["description"],
                "inputSchema" => $t["schema"],
                "annotations" => $t["annotations"] ?: new \stdClass(),
            ];
        }
        return $list;
    }

    private function call_tool(array $params): array
    {
        $name = $params["name"] ?? null;
        if (!is_string($name) || !isset($this->tools[$name])) {
            throw new Mcp_Rpc_Error(
                "Unknown tool: " .
                    (is_string($name) ? substr($name, 0, 60) : "?"),
                -32602,
            );
        }
        $tool = $this->tools[$name];
        $args =
            isset($params["arguments"]) && is_array($params["arguments"])
                ? $params["arguments"]
                : [];

        $t0 = microtime(true);
        try {
            $error = null;
            $args = self::check($tool["schema"], $args, "arguments", $error);
            if (null !== $error) {
                // Reported INSIDE the result so the model can read it and try again.
                throw new Mcp_Tool_Error("Invalid arguments: " . $error);
            }
            $data = $tool["handler"]($args);
            $result = [
                "content" => [
                    ["type" => "text", "text" => $this->encode($data)],
                ],
                "structuredContent" => $data,
                "isError" => false,
            ];
            $this->notify($name, true, $t0, null);
            return $result;
        } catch (Mcp_Tool_Error $e) {
            $this->notify($name, false, $t0, $e->getMessage());
            return [
                "content" => [["type" => "text", "text" => $e->getMessage()]],
                "isError" => true,
            ];
        } catch (\Throwable $e) {
            $this->notify(
                $name,
                false,
                $t0,
                get_class($e) . ": " . $e->getMessage(),
            );
            return [
                "content" => [
                    [
                        "type" => "text",
                        "text" =>
                            "The store could not complete that request. Try again shortly.",
                    ],
                ],
                "isError" => true,
            ];
        }
    }

    private function notify(
        string $tool,
        bool $ok,
        float $t0,
        ?string $error,
    ): void {
        if ($this->observer) {
            try {
                ($this->observer)([
                    "tool" => $tool,
                    "ok" => $ok,
                    "ms" => (int) round((microtime(true) - $t0) * 1000),
                    "error" => $error,
                ]);
            } catch (\Throwable $e) {
                // monitoring must never break a tool call
            }
        }
    }

    // ───────────────────────────────────────────────────────────── arguments

    /**
     * Validate AND gently coerce arguments against a flat JSON Schema. Models often send
     * "3000" for 3000 or "true" for true, so numbers and booleans are coerced; anything
     * else wrong becomes a short message the model can act on. Unknown extra keys are ignored.
     */
    private static function check(
        array $schema,
        $value,
        string $path,
        ?string &$error,
    ) {
        if (null !== $error) {
            return $value;
        }
        $type = $schema["type"] ?? null;

        if ("object" === $type) {
            if (!is_array($value)) {
                $error = "$path must be an object";
                return $value;
            }
            foreach ($schema["required"] ?? [] as $key) {
                if (
                    !array_key_exists($key, $value) ||
                    null === $value[$key] ||
                    "" === $value[$key]
                ) {
                    $error = "$path.$key is required";
                    return $value;
                }
            }
            foreach ($schema["properties"] ?? [] as $key => $sub) {
                if (array_key_exists($key, $value) && null !== $value[$key]) {
                    $value[$key] = self::check(
                        $sub,
                        $value[$key],
                        "$path.$key",
                        $error,
                    );
                } elseif (array_key_exists($key, $value)) {
                    unset($value[$key]);
                } elseif (array_key_exists("default", $sub)) {
                    $value[$key] = $sub["default"];
                }
            }
            return $value;
        }

        if ("string" === $type) {
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (!is_string($value)) {
                $error = "$path must be a string";
                return $value;
            }
            if (
                isset($schema["maxLength"]) &&
                mb_strlen($value) > $schema["maxLength"]
            ) {
                $error = "$path is too long (max {$schema["maxLength"]} characters)";
            }
            if (
                isset($schema["enum"]) &&
                !in_array($value, $schema["enum"], true)
            ) {
                $error =
                    "$path must be one of: " . implode(", ", $schema["enum"]);
            }
            return $value;
        }

        if ("integer" === $type || "number" === $type) {
            if (is_string($value) && is_numeric($value)) {
                $value = $value + 0;
            }
            if (!is_int($value) && !is_float($value)) {
                $error = "$path must be a number";
                return $value;
            }
            if ("integer" === $type) {
                if (floor($value) != $value) {
                    $error = "$path must be a whole number";
                    return $value;
                }
                $value = (int) $value;
            }
            if (isset($schema["minimum"]) && $value < $schema["minimum"]) {
                $error = "$path must be at least {$schema["minimum"]}";
            }
            if (isset($schema["maximum"]) && $value > $schema["maximum"]) {
                $error = "$path must be at most {$schema["maximum"]}";
            }
            return $value;
        }

        if ("boolean" === $type) {
            if (
                is_string($value) &&
                in_array(strtolower($value), ["true", "false"], true)
            ) {
                $value = "true" === strtolower($value);
            }
            if (!is_bool($value)) {
                $error = "$path must be true or false";
            }
            return $value;
        }

        if ("array" === $type) {
            if (!is_array($value)) {
                $error = "$path must be a list";
                return $value;
            }
            if (
                isset($schema["maxItems"]) &&
                count($value) > $schema["maxItems"]
            ) {
                $error = "$path has too many items (max {$schema["maxItems"]})";
                return $value;
            }
            if (isset($schema["items"])) {
                foreach ($value as $i => $item) {
                    $value[$i] = self::check(
                        $schema["items"],
                        $item,
                        "{$path}[{$i}]",
                        $error,
                    );
                }
            }
            return $value;
        }

        return $value;
    }

    // ───────────────────────────────────────────────────────────── plumbing

    private function encode($data): string
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_INVALID_UTF8_SUBSTITUTE |
                JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        return false === $json ? "{}" : $json;
    }

    private function envelope(
        $id,
        $result,
        int $code,
        string $message,
        ?array $data = null,
    ): array {
        $error = ["code" => $code, "message" => $message];
        if (null !== $data) {
            $error["data"] = $data;
        }
        return ["jsonrpc" => "2.0", "id" => $id, "error" => $error];
    }

    private function rpc_error(
        $id,
        int $code,
        string $message,
        ?array $data = null,
    ): string {
        return $this->encode(
            $this->envelope($id, null, $code, $message, $data),
        );
    }

    private function http(int $status, string $body, array $extra = []): array
    {
        $headers = $extra;
        if ("" !== $body) {
            $headers["Content-Type"] = "application/json";
        }
        return ["status" => $status, "headers" => $headers, "body" => $body];
    }
}
