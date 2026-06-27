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
    $publicConfig = mr_public_config($config);
    $publicConfig["service_mode"] = "verification_code_only";
    api_ok($publicConfig, "Verification service info loaded.");
}

if ($method !== "POST") {
    api_fail("Only GET and POST are supported.", 405);
}

$request = api_request_data();
$targetEmail = api_normalize_email((string) ($request["target_email"] ?? ($request["email"] ?? "")));
$accessPassword = trim((string) ($request["access_password"] ?? ""));

accounts_require_access($config, $targetEmail, $accessPassword);

$result = mr_read_messages(mr_resolve_request($request, $config));
if (empty($result["success"])) {
    api_send_json($result, 400);
}

$data = is_array($result["data"] ?? null) ? $result["data"] : [];
$verificationCode = (string) ($data["latest_verification_code"] ?? "");

api_ok([
    "target_email" => (string) ($data["latest_verification_email"] ?? ($data["query"]["target_email"] ?? "")),
    "verification_code" => $verificationCode,
    "updated_at" => (string) ($data["latest_verification_message_date"] ?? ""),
    "checked_at" => date("Y-m-d H:i:s"),
    "message_id" => $data["latest_verification_message_id"] ?? null,
    "found" => $verificationCode !== "",
    "fallback_used" => !empty($data["fallback_used"]),
    "read_fallback_used" => !empty($data["read_fallback_used"]),
    "deleted_after_read" => !empty($data["deleted_after_read"]),
    "deleted_message_id" => $data["deleted_message_id"] ?? null,
    "delete_error" => (string) ($data["delete_error"] ?? ""),
    "matched_mail_count" => (int) ($data["count"] ?? 0),
], (string) ($result["message"] ?? "Lookup complete."));
