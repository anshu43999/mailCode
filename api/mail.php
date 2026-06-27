<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/lib/api_helpers.php";
require_once dirname(__DIR__) . "/lib/account_store.php";
require_once dirname(__DIR__) . "/lib/mail_reader_refactored.php";

$config = api_load_config();
$method = api_request_method();

if ($method === "OPTIONS") {
    api_ok([], "ok");
}

if ($method === "GET") {
    api_ok(mr_public_config($config), "服务信息已加载。");
}

if ($method !== "POST") {
    api_fail("仅支持 GET 和 POST 请求。", 405);
}

$request = api_request_data();
$targetEmail = api_normalize_email((string) ($request["target_email"] ?? ($request["email"] ?? "")));
$accessPassword = trim((string) ($request["access_password"] ?? ""));

accounts_require_access($config, $targetEmail, $accessPassword);

$params = mr_resolve_request($request, $config);
$result = mr_read_messages($params);

api_send_json($result, !empty($result["success"]) ? 200 : 400);
