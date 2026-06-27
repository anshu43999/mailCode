<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/lib/api_helpers.php";
require_once dirname(__DIR__) . "/lib/cdk_store.php";
require_once dirname(__DIR__) . "/lib/security.php";

$config = api_load_config();
$method = api_request_method();

if ($method === "OPTIONS") {
    api_ok([], "ok");
}

if ($method === "GET") {
    api_ok(["service_mode" => "cdk_lookup"], "CDK service loaded.");
}

if ($method !== "POST") {
    api_fail("Only GET and POST are supported.", 405);
}

$request = api_request_data();
$action = strtolower(trim((string) ($request["action"] ?? "verify")));
$records = cdk_load_file();

if ($action === "verify") {
    security_assert_not_limited("cdk_verify", 10, 600, 1800);
    $code = cdk_normalize_code((string) ($request["cdk"] ?? ""));
    if ($code === "") {
        api_fail("请输入 CDK。", 400);
    }
    if (!isset($records[$code])) {
        security_record_failure("cdk_verify", 10, 600, 1800);
        api_fail("CDK 不存在或已失效。", 404);
    }

    $record = cdk_normalize_record($records[$code]);
    if (empty($record["enabled"])) {
        security_record_failure("cdk_verify", 10, 600, 1800);
        api_fail("CDK 不存在或已失效。", 404);
    }
    if (trim((string) ($record["content"] ?? "")) === "") {
        security_record_failure("cdk_verify", 10, 600, 1800);
        api_fail("CDK 不存在或已失效。", 404);
    }

    security_clear_failures("cdk_verify");
    api_ok(cdk_public_payload($code, $record), "验证成功。");
}

$adminPassword = trim((string) ($request["admin_password"] ?? ""));
security_require_admin_password($config, $adminPassword, "admin_cdk");

if ($action === "list") {
    api_ok(["records" => cdk_admin_payload($records)], "Loaded.");
}

if ($action === "save") {
    $code = cdk_normalize_code((string) ($request["cdk"] ?? ""));
    $title = trim((string) ($request["title"] ?? "CDK 信息"));
    $content = trim((string) ($request["content"] ?? ""));
    $enabled = (bool) ($request["enabled"] ?? true);

    if ($code === "") {
        $code = cdk_generate_code($records);
    }
    if (!preg_match("/^[A-Z0-9_-]{4,64}$/", $code)) {
        api_fail("CDK 只能包含字母、数字、下划线或横线，长度 4-64 位。", 400);
    }
    if ($content === "") {
        api_fail("CDK 信息不能为空。", 400);
    }

    $records[$code] = [
        "title" => $title !== "" ? $title : "CDK 信息",
        "content" => $content,
        "enabled" => $enabled,
        "updated_at" => date("Y-m-d H:i:s"),
    ];

    if (!cdk_save_file($records)) {
        api_fail("Failed to save CDK.", 500);
    }

    api_ok(["records" => cdk_admin_payload($records)], "Saved.");
}

if ($action === "delete") {
    $code = cdk_normalize_code((string) ($request["cdk"] ?? ""));
    if ($code === "") {
        api_fail("CDK is required.", 400);
    }

    unset($records[$code]);
    if (!cdk_save_file($records)) {
        api_fail("Failed to delete CDK.", 500);
    }

    api_ok(["records" => cdk_admin_payload($records)], "Deleted.");
}

api_fail("Unsupported action.", 400);
