<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/lib/api_helpers.php";
require_once dirname(__DIR__) . "/lib/security.php";
require_once dirname(__DIR__) . "/lib/mail_reader_refactored.php";

$config = api_load_config();
$method = api_request_method();

if ($method === "OPTIONS") {
    api_ok([], "ok");
}

if ($method !== "POST") {
    api_fail("仅支持 POST 请求。", 405);
}

$request = api_request_data();
$adminPassword = trim((string) ($request["admin_password"] ?? ""));
security_require_admin_password($config, $adminPassword, "admin_debug");

$targetEmail = api_normalize_email((string) ($request["target_email"] ?? ""));
if ($targetEmail === "") {
    api_fail("请填写目标邮箱。", 400);
}
if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
    api_fail("目标邮箱格式不正确。", 400);
}

$params = mr_resolve_request([
    "target_email" => $targetEmail,
], $config);

$debugLimit = (int) ($request["debug_limit"] ?? 50);
$result = mr_debug_messages($params, $debugLimit);

api_send_json($result, !empty($result["success"]) ? 200 : 400);
