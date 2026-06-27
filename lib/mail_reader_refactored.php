<?php
declare(strict_types=1);

require_once __DIR__ . "/outlook_graph_reader.php";

function mr_value(array $source, string $key, $default = null)
{
    return array_key_exists($key, $source) ? $source[$key] : $default;
}

function mr_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }
    return in_array(strtolower(trim((string) $value)), ["1", "true", "yes", "on"], true);
}

function mr_lower(string $text): string
{
    return function_exists("mb_strtolower") ? mb_strtolower($text, "UTF-8") : strtolower($text);
}

function mr_contains(string $haystack, string $needle): bool
{
    return $needle === "" || strpos(mr_lower($haystack), mr_lower($needle)) !== false;
}

function mr_decode_header(?string $value): string
{
    $value = (string) $value;
    if ($value === "" || !function_exists("imap_mime_header_decode")) {
        return $value;
    }
    $parts = @imap_mime_header_decode($value);
    if (!is_array($parts) || $parts === []) {
        return $value;
    }
    $decoded = "";
    foreach ($parts as $part) {
        $text = isset($part->text) ? (string) $part->text : "";
        $charset = strtoupper((string) ($part->charset ?? "UTF-8"));
        if ($text !== "" && $charset !== "DEFAULT" && $charset !== "UTF-8" && function_exists("iconv")) {
            $converted = @iconv($charset, "UTF-8//IGNORE", $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $decoded .= $text;
    }
    return $decoded !== "" ? $decoded : $value;
}

function mr_normalize_text(string $text, string $charset = "UTF-8"): string
{
    $charset = strtoupper(trim($charset));
    if ($text !== "" && $charset !== "" && $charset !== "UTF-8" && $charset !== "DEFAULT" && function_exists("iconv")) {
        $converted = @iconv($charset, "UTF-8//IGNORE", $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    return trim(str_replace(["\r\n", "\r"], "\n", $text));
}

function mr_decode_transfer(string $body, int $encoding): string
{
    if ($encoding === 2 && function_exists("imap_binary")) {
        return imap_binary($body);
    }
    if ($encoding === 3) {
        $decoded = base64_decode($body, true);
        return $decoded !== false ? $decoded : $body;
    }
    if ($encoding === 4) {
        return quoted_printable_decode($body);
    }
    return $body;
}

function mr_part_charset(object $part): string
{
    foreach (["parameters", "dparameters"] as $property) {
        if (!isset($part->{$property}) || !is_array($part->{$property})) {
            continue;
        }
        foreach ($part->{$property} as $parameter) {
            if (strcasecmp((string) ($parameter->attribute ?? ""), "charset") === 0) {
                return (string) ($parameter->value ?? "UTF-8");
            }
        }
    }
    return "UTF-8";
}

function mr_html_to_text(string $html): string
{
    $html = preg_replace("/<\s*br\s*\/?\s*>/i", "\n", $html) ?? $html;
    $html = preg_replace("/<\s*\/p\s*>/i", "\n\n", $html) ?? $html;
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, "UTF-8");
    $html = preg_replace("/\n{3,}/", "\n\n", $html) ?? $html;
    return trim($html);
}

function mr_fetch_part($imap, int $messageNumber, object $part, string $partNumber): string
{
    $raw = $partNumber === "" ? @imap_body($imap, $messageNumber, FT_PEEK) : @imap_fetchbody($imap, $messageNumber, $partNumber, FT_PEEK);
    $raw = $raw === false ? "" : (string) $raw;
    $body = mr_decode_transfer($raw, (int) ($part->encoding ?? 0));
    $body = mr_normalize_text($body, mr_part_charset($part));
    return strtolower((string) ($part->subtype ?? "")) === "html" ? mr_html_to_text($body) : $body;
}

function mr_collect_bodies($imap, int $messageNumber, ?object $structure = null, string $partNumber = ""): array
{
    $structure = $structure ?: @imap_fetchstructure($imap, $messageNumber);
    if (!$structure) {
        return [];
    }
    $bodies = [];
    $type = (int) ($structure->type ?? -1);
    $subtype = strtolower((string) ($structure->subtype ?? ""));
    if ($type === 0 && in_array($subtype, ["plain", "html"], true)) {
        $body = mr_fetch_part($imap, $messageNumber, $structure, $partNumber);
        if ($body !== "") {
            $bodies[$subtype] = $body;
        }
    }
    if (isset($structure->parts) && is_array($structure->parts)) {
        foreach ($structure->parts as $index => $part) {
            $nextPartNumber = $partNumber === "" ? (string) ($index + 1) : $partNumber . "." . ($index + 1);
            foreach (mr_collect_bodies($imap, $messageNumber, $part, $nextPartNumber) as $kind => $body) {
                if (!isset($bodies[$kind]) && $body !== "") {
                    $bodies[$kind] = $body;
                }
            }
        }
    }
    return $bodies;
}

function mr_best_body($imap, int $messageNumber): string
{
    $bodies = mr_collect_bodies($imap, $messageNumber);
    if (!empty($bodies["plain"])) {
        return $bodies["plain"];
    }
    if (!empty($bodies["html"])) {
        return $bodies["html"];
    }
    $fallback = @imap_body($imap, $messageNumber, FT_PEEK);
    return mr_html_to_text(mr_normalize_text($fallback === false ? "" : (string) $fallback));
}

function mr_format_address(?object $address): string
{
    if (!$address) {
        return "";
    }
    $name = mr_decode_header((string) ($address->personal ?? ""));
    $mailbox = (string) ($address->mailbox ?? "");
    $host = (string) ($address->host ?? "");
    $email = trim($mailbox . "@" . $host, "@");
    if ($name !== "" && $email !== "") {
        return $name . " <" . $email . ">";
    }
    return $email !== "" ? $email : $name;
}

function mr_format_address_list($addresses): string
{
    if (!is_array($addresses)) {
        return "";
    }
    $formatted = [];
    foreach ($addresses as $address) {
        if (is_object($address)) {
            $value = mr_format_address($address);
            if ($value !== "") {
                $formatted[] = $value;
            }
        }
    }
    return implode(", ", $formatted);
}

function mr_truncate(string $text, int $length = 180): string
{
    $text = preg_replace("/\s+/u", " ", trim($text)) ?? trim($text);
    if ($text === "") {
        return "";
    }
    if (function_exists("mb_strlen") && function_exists("mb_substr")) {
        return mb_strlen($text, "UTF-8") > $length ? mb_substr($text, 0, $length, "UTF-8") . "..." : $text;
    }
    return strlen($text) > $length ? substr($text, 0, $length) . "..." : $text;
}

function mr_is_openai_sender(string $from): bool
{
    $from = strtolower(trim($from));
    if ($from === "") {
        return false;
    }
    foreach (["openai", "chatgpt", "noreply@openai.com", "no-reply@openai.com", "@openai.com"] as $needle) {
        if (strpos($from, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function mr_extract_verification_codes(string $text): array
{
    $candidates = [];
    $patterns = [
        1 => "/(?:code\s*(?:is|:)?\s*|verification\s*code\s*:?\s*|verify\s*:?\s*|enter\s+(?:this\s+)?code\s*:?\s*)(\d{6})/iu",
        2 => "/(?<!\d)(\d{6})(?!\d)/u",
    ];
    foreach ($patterns as $priority => $regex) {
        if (!preg_match_all($regex, $text, $matches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($matches as $match) {
            $code = preg_replace("/\D+/", "", (string) ($match[1] ?? "")) ?? "";
            if (preg_match("/^\d{6}$/", $code) && (!isset($candidates[$code]) || $priority < $candidates[$code])) {
                $candidates[$code] = $priority;
            }
        }
    }
    asort($candidates, SORT_NUMERIC);
    return array_keys($candidates);
}

function mr_sanitize_host(string $host): string
{
    return preg_replace("/[^A-Za-z0-9.\-]/", "", trim($host)) ?? "";
}

function mr_sanitize_port($port): int
{
    $port = (int) $port;
    return $port >= 1 && $port <= 65535 ? $port : 0;
}

function mr_sanitize_flags(string $flags): string
{
    $flags = preg_replace("/[^A-Za-z0-9\/\-]/", "", str_replace(["{", "}", "\r", "\n", " "], "", trim($flags))) ?? "";
    return $flags !== "" && strpos($flags, "/") !== 0 ? "/" . $flags : $flags;
}

function mr_sanitize_folder(string $folder): string
{
    $folder = str_replace(["\r", "\n", "{", "}"], "", trim($folder));
    return $folder === "" ? "INBOX" : $folder;
}

function mr_resolve_config_defaults(array $config): array
{
    $backend = is_array($config["backend_mailbox"] ?? null) ? $config["backend_mailbox"] : [];
    $outlookGraph = is_array($config["outlook_graph"] ?? null) ? $config["outlook_graph"] : [];
    $lookup = is_array($config["lookup"] ?? null) ? $config["lookup"] : [];
    $maxLimit = max(1, (int) mr_value($lookup, "max_limit", 20));
    $defaultLimit = min(max(1, (int) mr_value($lookup, "default_limit", 10)), $maxLimit);
    $provider = mr_lower(trim((string) mr_value($config, "mail_provider", mr_value($lookup, "provider", "imap"))));
    if (!in_array($provider, ["imap", "outlook_graph"], true)) {
        $provider = "imap";
    }
    return [
        "mail_provider" => $provider,
        "backend_mailbox" => [
            "imap_host" => mr_sanitize_host((string) mr_value($backend, "imap_host", "imap.qq.com")),
            "imap_port" => mr_sanitize_port(mr_value($backend, "imap_port", 993)),
            "imap_flags" => mr_sanitize_flags((string) mr_value($backend, "imap_flags", "/imap/ssl")),
            "mail_folder" => mr_sanitize_folder((string) mr_value($backend, "mail_folder", "INBOX")),
            "email" => trim((string) mr_value($backend, "email", "")),
            "auth_code" => trim((string) mr_value($backend, "auth_code", "")),
        ],
        "outlook_graph" => [
            "tenant_id" => ogr_sanitize_tenant((string) mr_value($outlookGraph, "tenant_id", "common")),
            "client_id" => trim((string) mr_value($outlookGraph, "client_id", "")),
            "client_secret" => trim((string) mr_value($outlookGraph, "client_secret", "")),
            "refresh_token" => trim((string) mr_value($outlookGraph, "refresh_token", "")),
            "access_token" => trim((string) mr_value($outlookGraph, "access_token", "")),
            "scopes" => trim((string) mr_value($outlookGraph, "scopes", "https://graph.microsoft.com/Mail.Read offline_access")),
            "mail_folder" => mr_sanitize_folder((string) mr_value($outlookGraph, "mail_folder", "inbox")),
            "graph_base_url" => rtrim(trim((string) mr_value($outlookGraph, "graph_base_url", "https://graph.microsoft.com/v1.0")), "/"),
        ],
        "lookup" => [
            "default_limit" => $defaultLimit,
            "max_limit" => $maxLimit,
            "scan_limit" => max(1, (int) mr_value($lookup, "scan_limit", 200)),
            "max_age_seconds" => max(1, (int) mr_value($lookup, "max_age_seconds", 900)),
            "unread_only" => mr_bool(mr_value($lookup, "unread_only", true)),
            "delete_after_read" => mr_bool(mr_value($lookup, "delete_after_read", true)),
        ],
    ];
}

function mr_backend_mailbox_configured(array $backend): bool
{
    return $backend["imap_host"] !== "" && $backend["imap_port"] > 0 && $backend["imap_flags"] !== "" && $backend["mail_folder"] !== "" && $backend["email"] !== "" && $backend["auth_code"] !== "";
}

function mr_resolve_request(array $request, array $config): array
{
    $resolved = mr_resolve_config_defaults($config);
    $lookup = $resolved["lookup"];
    $limit = min(max(1, (int) mr_value($request, "limit", $lookup["default_limit"])), (int) $lookup["max_limit"]);
    $provider = mr_lower(trim((string) mr_value($request, "provider", $resolved["mail_provider"])));
    if (!in_array($provider, ["imap", "outlook_graph"], true)) {
        $provider = $resolved["mail_provider"];
    }
    return [
        "provider" => $provider,
        "target_email" => mr_lower(trim((string) mr_value($request, "target_email", mr_value($request, "email", "")))),
        "limit" => $limit,
        "scan_limit" => $lookup["scan_limit"],
        "max_age_seconds" => $lookup["max_age_seconds"],
        "unread_only" => $lookup["unread_only"],
        "delete_after_read" => $lookup["delete_after_read"],
        "backend_mailbox" => $resolved["backend_mailbox"],
        "outlook_graph" => $resolved["outlook_graph"],
    ];
}

function mr_public_config(array $config): array
{
    $resolved = mr_resolve_config_defaults($config);
    return [
        "service_mode" => "backend_secret_forward_lookup",
        "mail_provider" => $resolved["mail_provider"],
        "backend_configured" => mr_backend_mailbox_configured($resolved["backend_mailbox"]),
        "outlook_graph_configured" => ogr_configured($resolved["outlook_graph"]),
        "lookup" => $resolved["lookup"],
    ];
}

function mr_validate_request(array $params): string
{
    if (!function_exists("imap_open")) {
        return "PHP IMAP extension is not enabled. Enable extension=imap first.";
    }
    if (!mr_backend_mailbox_configured($params["backend_mailbox"])) {
        return "Backend mailbox is not configured. Fill config/mail.php first.";
    }
    if (!filter_var($params["target_email"], FILTER_VALIDATE_EMAIL)) {
        return "Target email is invalid.";
    }
    return "";
}

function mr_mailbox_path(array $backend): string
{
    $port = $backend["imap_port"] > 0 ? ":" . $backend["imap_port"] : "";
    return "{" . $backend["imap_host"] . $port . $backend["imap_flags"] . "}" . $backend["mail_folder"];
}

function mr_search_text($imap, int $messageNumber, ?object $overview, $header, string $body): string
{
    $parts = [];
    foreach (["subject", "from", "to"] as $field) {
        if (is_object($overview) && isset($overview->{$field})) {
            $parts[] = mr_decode_header((string) $overview->{$field});
        }
    }
    if (is_object($header)) {
        foreach (["from", "to", "cc", "bcc", "reply_to", "sender"] as $field) {
            $parts[] = mr_format_address_list($header->{$field} ?? null);
        }
        foreach (["toaddress", "fromaddress", "ccaddress", "bccaddress"] as $field) {
            if (isset($header->{$field})) {
                $parts[] = mr_decode_header((string) $header->{$field});
            }
        }
    }
    $rawHeader = @imap_fetchheader($imap, $messageNumber);
    if ($rawHeader !== false) {
        $parts[] = mr_normalize_text((string) $rawHeader);
    }
    $parts[] = $body;
    return implode("\n", array_filter($parts, static fn($value): bool => trim((string) $value) !== ""));
}

function mr_message_from(?object $overview, $header): string
{
    if (is_object($header) && isset($header->from[0]) && is_object($header->from[0])) {
        $from = mr_format_address($header->from[0]);
        if ($from !== "") {
            return $from;
        }
    }
    return is_object($overview) && isset($overview->from) ? mr_decode_header((string) $overview->from) : "";
}

function mr_message_subject(?object $overview, $header): string
{
    if (is_object($overview) && isset($overview->subject)) {
        $subject = mr_decode_header((string) $overview->subject);
        if ($subject !== "") {
            return $subject;
        }
    }
    if (is_object($header) && isset($header->subject)) {
        $subject = mr_decode_header((string) $header->subject);
        if ($subject !== "") {
            return $subject;
        }
    }
    return "(no subject)";
}

function mr_delete_message($imap, int $messageId): array
{
    if (@imap_delete($imap, (string) $messageId) === false) {
        return [false, imap_last_error() ?: "Failed to delete verification email."];
    }
    if (@imap_expunge($imap) === false) {
        return [false, imap_last_error() ?: "Failed to delete verification email."];
    }
    return [true, ""];
}

function mr_read_messages(array $params): array
{
    if (($params["provider"] ?? "imap") === "outlook_graph") {
        return ogr_read_messages($params);
    }

    $validation = mr_validate_request($params);
    if ($validation !== "") {
        return ["success" => false, "message" => $validation, "data" => null];
    }
    @imap_errors();
    @imap_alerts();
    $imap = @imap_open(mr_mailbox_path($params["backend_mailbox"]), $params["backend_mailbox"]["email"], $params["backend_mailbox"]["auth_code"], 0);
    if ($imap === false) {
        $errors = imap_errors();
        $lastError = is_array($errors) && $errors !== [] ? end($errors) : imap_last_error();
        return ["success" => false, "message" => "Failed to connect backend mailbox: " . ($lastError ?: "check config/mail.php"), "data" => null];
    }

    $messageNumbers = @imap_search($imap, "ALL");
    $messageNumbers = is_array($messageNumbers) ? $messageNumbers : [];
    rsort($messageNumbers, SORT_NUMERIC);
    $messageNumbers = array_slice($messageNumbers, 0, (int) $params["scan_limit"]);
    $unreadMatches = [];
    $readMatches = [];
    $unreadFallbacks = [];
    $readFallbacks = [];
    $now = time();

    foreach ($messageNumbers as $messageNumber) {
        $messageNumber = (int) $messageNumber;
        $overviewList = @imap_fetch_overview($imap, (string) $messageNumber, 0);
        $overview = is_array($overviewList) && isset($overviewList[0]) ? $overviewList[0] : null;
        $header = @imap_headerinfo($imap, $messageNumber);
        $from = mr_message_from($overview, $header);
        if (!mr_is_openai_sender($from)) {
            continue;
        }
        $dateText = is_object($overview) && isset($overview->date) ? (string) $overview->date : "";
        $timestamp = $dateText !== "" ? strtotime($dateText) : false;
        if ($timestamp === false || $now - $timestamp > (int) $params["max_age_seconds"]) {
            continue;
        }
        $body = mr_best_body($imap, $messageNumber);
        $subject = mr_message_subject($overview, $header);
        $searchText = mr_search_text($imap, $messageNumber, $overview, $header, $body);
        $targetPrefix = explode("@", $params["target_email"])[0] ?? "";
        $targetMatched = mr_contains($searchText, $params["target_email"]) || ($targetPrefix !== "" && mr_contains($searchText, $targetPrefix));
        $codes = mr_extract_verification_codes($subject . "\n" . $body);
        $bestCode = $codes[0] ?? "";
        $isSeen = is_object($overview) ? !empty($overview->seen) : false;
        $message = [
            "id" => $messageNumber,
            "subject" => $subject,
            "from" => $from !== "" ? $from : "unknown sender",
            "date" => date("Y-m-d H:i:s", $timestamp),
            "seen" => $isSeen,
            "preview" => mr_truncate($body !== "" ? $body : "No readable body."),
            "body" => $body !== "" ? $body : "No readable body.",
            "verification_codes" => $codes,
            "best_verification_code" => $bestCode,
        ];
        if ($targetMatched) {
            $isSeen ? $readMatches[] = $message : $unreadMatches[] = $message;
        } elseif ($bestCode !== "") {
            $isSeen ? $readFallbacks[] = $message : $unreadFallbacks[] = $message;
        }
    }

    $messages = $unreadMatches;
    $fallbackCandidates = $unreadFallbacks;
    $readFallbackUsed = false;
    $fallbackUsed = false;
    if ($params["unread_only"] && $messages === [] && $fallbackCandidates === []) {
        $messages = $readMatches;
        $fallbackCandidates = $readFallbacks;
        $readFallbackUsed = true;
    }
    if ($messages === [] && count($fallbackCandidates) === 1) {
        $messages[] = $fallbackCandidates[0];
        $fallbackUsed = true;
    }
    $messages = array_slice($messages, 0, (int) $params["limit"]);
    $latestCode = "";
    $latestMessageId = null;
    $latestMessageDate = "";
    foreach ($messages as $message) {
        if (!empty($message["best_verification_code"])) {
            $latestCode = (string) $message["best_verification_code"];
            $latestMessageId = (int) $message["id"];
            $latestMessageDate = (string) $message["date"];
            break;
        }
    }

    $deletedAfterRead = false;
    $deletedMessageId = null;
    $deleteError = "";
    if ($latestCode !== "" && $latestMessageId !== null && $params["delete_after_read"]) {
        [$deletedAfterRead, $deleteError] = mr_delete_message($imap, $latestMessageId);
        if ($deletedAfterRead) {
            $deletedMessageId = $latestMessageId;
        }
    }
    @imap_close($imap);

    if ($messages === []) {
        $messageText = "No matching OpenAI verification email was found in the configured time window.";
    } elseif ($latestCode === "") {
        $messageText = $readFallbackUsed ? "Unread emails did not match. Read emails were checked, but no code was extracted." : "A related OpenAI email was found, but no code was extracted.";
    } elseif ($fallbackUsed) {
        $messageText = $deletedAfterRead ? "Code extracted from the only fallback OpenAI email, and the email was deleted." : "Code extracted from the only fallback OpenAI email.";
    } else {
        $messageText = $deletedAfterRead ? "OpenAI verification code extracted, and the email was deleted." : "OpenAI verification code extracted.";
    }

    return [
        "success" => true,
        "message" => $messageText,
        "data" => [
            "query" => [
                "target_email" => $params["target_email"],
                "display_limit" => $params["limit"],
                "scan_limit" => $params["scan_limit"],
                "unread_only" => $params["unread_only"],
                "max_age_seconds" => $params["max_age_seconds"],
                "delete_after_read" => $params["delete_after_read"],
            ],
            "latest_verification_email" => $params["target_email"],
            "latest_verification_code" => $latestCode,
            "fallback_used" => $fallbackUsed,
            "read_fallback_used" => $readFallbackUsed,
            "deleted_after_read" => $deletedAfterRead,
            "deleted_message_id" => $deletedMessageId,
            "delete_error" => $deleteError,
            "latest_verification_message_id" => $latestMessageId,
            "latest_verification_message_date" => $latestMessageDate,
            "count" => count($messages),
            "messages" => $messages,
        ],
    ];
}
