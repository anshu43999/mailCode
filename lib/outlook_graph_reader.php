<?php
declare(strict_types=1);

function ogr_value(array $source, string $key, $default = null)
{
    return array_key_exists($key, $source) ? $source[$key] : $default;
}

function ogr_sanitize_tenant(string $tenant): string
{
    $tenant = trim($tenant);
    return preg_match("/^[A-Za-z0-9_.-]+$/", $tenant) ? $tenant : "common";
}

function ogr_configured(array $graph): bool
{
    $hasStaticToken = trim((string) ($graph["access_token"] ?? "")) !== "";
    $hasRefreshToken = trim((string) ($graph["client_id"] ?? "")) !== "" && trim((string) ($graph["refresh_token"] ?? "")) !== "";
    return $hasStaticToken || $hasRefreshToken;
}

function ogr_public_config(array $params): array
{
    $graph = is_array($params["outlook_graph"] ?? null) ? $params["outlook_graph"] : [];
    return [
        "provider" => "outlook_graph",
        "backend_configured" => ogr_configured($graph),
        "lookup" => [
            "default_limit" => $params["limit"] ?? null,
            "scan_limit" => $params["scan_limit"] ?? null,
            "max_age_seconds" => $params["max_age_seconds"] ?? null,
            "unread_only" => $params["unread_only"] ?? null,
            "delete_after_read" => $params["delete_after_read"] ?? null,
        ],
    ];
}

function ogr_http_json(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $headers[] = "Accept: application/json";
    $responseHeaders = [];
    $statusCode = 0;
    $responseText = false;

    if (function_exists("curl_init")) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $responseText = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($responseText === false) {
            return ["ok" => false, "status" => 0, "json" => null, "text" => "", "error" => $error ?: "HTTP 请求失败。"];
        }
    } else {
        $context = stream_context_create([
            "http" => [
                "method" => $method,
                "header" => implode("\r\n", $headers),
                "content" => $body ?? "",
                "ignore_errors" => true,
                "timeout" => 20,
            ],
        ]);
        $responseText = @file_get_contents($url, false, $context);
        $responseHeaders = is_array($http_response_header ?? null) ? $http_response_header : [];
        foreach ($responseHeaders as $headerLine) {
            if (preg_match("/^HTTP\/\S+\s+(\d+)/", $headerLine, $matches)) {
                $statusCode = (int) $matches[1];
                break;
            }
        }
        if ($responseText === false) {
            $lastError = error_get_last();
            return ["ok" => false, "status" => $statusCode, "json" => null, "text" => "", "error" => (string) ($lastError["message"] ?? "HTTP 请求失败。")];
        }
    }

    $decoded = json_decode((string) $responseText, true);
    $ok = $statusCode >= 200 && $statusCode < 300;
    return [
        "ok" => $ok,
        "status" => $statusCode,
        "json" => is_array($decoded) ? $decoded : null,
        "text" => (string) $responseText,
        "error" => $ok ? "" : "HTTP " . $statusCode,
    ];
}

function ogr_token_endpoint(array $graph): string
{
    $tenant = ogr_sanitize_tenant((string) ogr_value($graph, "tenant_id", "common"));
    return "https://login.microsoftonline.com/" . rawurlencode($tenant) . "/oauth2/v2.0/token";
}

function ogr_access_token(array $graph): array
{
    $staticToken = trim((string) ogr_value($graph, "access_token", ""));
    if ($staticToken !== "") {
        return [true, $staticToken, false, ""];
    }

    $clientId = trim((string) ogr_value($graph, "client_id", ""));
    $refreshToken = trim((string) ogr_value($graph, "refresh_token", ""));
    if ($clientId === "" || $refreshToken === "") {
        return [false, "", false, "Outlook Graph 未配置，请提供 access_token，或同时提供 client_id 和 refresh_token。"];
    }

    $form = [
        "client_id" => $clientId,
        "grant_type" => "refresh_token",
        "refresh_token" => $refreshToken,
        "scope" => trim((string) ogr_value($graph, "scopes", "https://graph.microsoft.com/Mail.Read offline_access")),
    ];
    $clientSecret = trim((string) ogr_value($graph, "client_secret", ""));
    if ($clientSecret !== "") {
        $form["client_secret"] = $clientSecret;
    }

    $response = ogr_http_json("POST", ogr_token_endpoint($graph), [
        "Content-Type: application/x-www-form-urlencoded",
    ], http_build_query($form));

    $json = is_array($response["json"] ?? null) ? $response["json"] : [];
    $accessToken = trim((string) ($json["access_token"] ?? ""));
    if (!$response["ok"] || $accessToken === "") {
        $description = (string) ($json["error_description"] ?? ($json["error"] ?? ($response["error"] ?? "刷新访问令牌失败。")));
        return [false, "", false, "刷新 Outlook Graph 令牌失败：" . $description];
    }

    return [true, $accessToken, true, ""];
}

function ogr_graph_base_url(array $graph): string
{
    $baseUrl = rtrim(trim((string) ogr_value($graph, "graph_base_url", "https://graph.microsoft.com/v1.0")), "/");
    return $baseUrl !== "" ? $baseUrl : "https://graph.microsoft.com/v1.0";
}

function ogr_messages_url(array $graph, int $scanLimit): string
{
    $baseUrl = ogr_graph_base_url($graph);
    $folder = trim((string) ogr_value($graph, "mail_folder", "inbox"));
    $folder = $folder !== "" ? $folder : "inbox";
    $query = http_build_query([
        "\$top" => min(max($scanLimit, 1), 100),
        "\$orderby" => "receivedDateTime desc",
        "\$select" => "id,subject,from,toRecipients,ccRecipients,bccRecipients,receivedDateTime,isRead,bodyPreview,body",
    ]);
    return $baseUrl . "/me/mailFolders/" . rawurlencode($folder) . "/messages?" . $query;
}

function ogr_address_value(array $address): string
{
    $emailAddress = is_array($address["emailAddress"] ?? null) ? $address["emailAddress"] : [];
    $name = trim((string) ($emailAddress["name"] ?? ""));
    $email = trim((string) ($emailAddress["address"] ?? ""));
    if ($name !== "" && $email !== "") {
        return $name . " <" . $email . ">";
    }
    return $email !== "" ? $email : $name;
}

function ogr_recipients_text(array $message): string
{
    $parts = [];
    foreach (["toRecipients", "ccRecipients", "bccRecipients"] as $field) {
        $items = is_array($message[$field] ?? null) ? $message[$field] : [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $value = ogr_address_value($item);
                if ($value !== "") {
                    $parts[] = $value;
                }
            }
        }
    }
    return implode(", ", $parts);
}

function ogr_message_body(array $message): string
{
    $body = is_array($message["body"] ?? null) ? $message["body"] : [];
    $content = (string) ($body["content"] ?? "");
    $contentType = strtolower((string) ($body["contentType"] ?? ""));
    if ($content !== "" && $contentType === "html") {
        return mr_html_to_text($content);
    }
    if ($content !== "") {
        return trim(str_replace(["\r\n", "\r"], "\n", $content));
    }
    return trim((string) ($message["bodyPreview"] ?? ""));
}

function ogr_delete_message(array $graph, string $accessToken, string $messageId): array
{
    $url = ogr_graph_base_url($graph) . "/me/messages/" . rawurlencode($messageId);
    $response = ogr_http_json("DELETE", $url, [
        "Authorization: Bearer " . $accessToken,
    ]);
    if (!($response["status"] === 204 || $response["ok"])) {
        $json = is_array($response["json"] ?? null) ? $response["json"] : [];
        $error = is_array($json["error"] ?? null) ? (string) ($json["error"]["message"] ?? "") : "";
        return [false, $error !== "" ? $error : (string) ($response["error"] ?? "邮件删除失败。")];
    }
    return [true, ""];
}

function ogr_read_messages(array $params): array
{
    if (!filter_var($params["target_email"], FILTER_VALIDATE_EMAIL)) {
        return ["success" => false, "message" => "目标邮箱格式不正确。", "data" => null];
    }

    $graph = is_array($params["outlook_graph"] ?? null) ? $params["outlook_graph"] : [];
    [$tokenOk, $accessToken, $tokenRefreshed, $tokenError] = ogr_access_token($graph);
    if (!$tokenOk) {
        return ["success" => false, "message" => $tokenError, "data" => null];
    }

    $response = ogr_http_json("GET", ogr_messages_url($graph, (int) $params["scan_limit"]), [
        "Authorization: Bearer " . $accessToken,
        "Prefer: outlook.body-content-type=\"text\"",
    ]);
    if (!$response["ok"]) {
        $json = is_array($response["json"] ?? null) ? $response["json"] : [];
        $error = is_array($json["error"] ?? null) ? (string) ($json["error"]["message"] ?? "") : "";
        return ["success" => false, "message" => "读取 Outlook 邮件失败：" . ($error !== "" ? $error : (string) $response["error"]), "data" => null];
    }

    $json = is_array($response["json"] ?? null) ? $response["json"] : [];
    $items = is_array($json["value"] ?? null) ? $json["value"] : [];
    $unreadMatches = [];
    $readMatches = [];
    $unreadFallbacks = [];
    $readFallbacks = [];
    $now = time();
    $targetPrefix = explode("@", $params["target_email"])[0] ?? "";

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $from = is_array($item["from"] ?? null) ? ogr_address_value($item["from"]) : "";
        if (!mr_is_openai_sender($from)) {
            continue;
        }

        $dateText = (string) ($item["receivedDateTime"] ?? "");
        $timestamp = $dateText !== "" ? strtotime($dateText) : false;
        if ($timestamp === false || $now - $timestamp > (int) $params["max_age_seconds"]) {
            continue;
        }

        $subject = trim((string) ($item["subject"] ?? ""));
        $subject = $subject !== "" ? $subject : "（无主题）";
        $body = ogr_message_body($item);
        $searchText = implode("\n", array_filter([
            $subject,
            $from,
            ogr_recipients_text($item),
            $body,
        ], static fn($value): bool => trim((string) $value) !== ""));
        $targetMatched = mr_contains($searchText, $params["target_email"]) || ($targetPrefix !== "" && mr_contains($searchText, $targetPrefix));
        $codes = mr_extract_verification_codes($subject . "\n" . $body);
        $bestCode = $codes[0] ?? "";
        $isSeen = !empty($item["isRead"]);
        $message = [
            "id" => (string) ($item["id"] ?? ""),
            "subject" => $subject,
            "from" => $from !== "" ? $from : "未知发件人",
            "date" => date("Y-m-d H:i:s", $timestamp),
            "seen" => $isSeen,
            "preview" => mr_truncate($body !== "" ? $body : (string) ($item["bodyPreview"] ?? "邮件正文不可读取。")),
            "body" => $body !== "" ? $body : "邮件正文不可读取。",
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
            $latestMessageId = (string) $message["id"];
            $latestMessageDate = (string) $message["date"];
            break;
        }
    }

    $deletedAfterRead = false;
    $deletedMessageId = null;
    $deleteError = "";
    if ($latestCode !== "" && $latestMessageId !== null && $params["delete_after_read"]) {
        [$deletedAfterRead, $deleteError] = ogr_delete_message($graph, $accessToken, $latestMessageId);
        if ($deletedAfterRead) {
            $deletedMessageId = $latestMessageId;
        }
    }

    if ($messages === []) {
        $messageText = "在配置的 Outlook 时间范围内没有找到匹配的 OpenAI 验证邮件。";
    } elseif ($latestCode === "") {
        $messageText = $readFallbackUsed ? "未读 Outlook 邮件未命中，已检查已读邮件，但没有提取到验证码。" : "已找到相关 Outlook 邮件，但没有提取到验证码。";
    } elseif ($fallbackUsed) {
        $messageText = $deletedAfterRead ? "已从唯一的候选 Outlook 邮件中提取验证码，并已删除该邮件。" : "已从唯一的候选 Outlook 邮件中提取验证码。";
    } else {
        $messageText = $deletedAfterRead ? "已从 Outlook 提取 OpenAI 验证码，并已删除该邮件。" : "已从 Outlook 提取 OpenAI 验证码。";
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
                "provider" => "outlook_graph",
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
            "token_refreshed" => $tokenRefreshed,
            "count" => count($messages),
            "messages" => $messages,
        ],
    ];
}
