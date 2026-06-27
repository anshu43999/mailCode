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
    api_ok(["service_mode" => "cdk_lookup"], "CDK 服务已加载。");
}

if ($method !== "POST") {
    api_fail("仅支持 GET 和 POST 请求。", 405);
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
    if (trim((string) ($record["first_claimed_at"] ?? "")) === "") {
        $record["first_claimed_at"] = date("Y-m-d H:i:s");
        $records[$code] = $record;
        cdk_save_file($records);
    }
    api_ok(cdk_public_payload($code, $record), "验证成功。");
}

$adminPassword = trim((string) ($request["admin_password"] ?? ""));
security_require_admin_password($config, $adminPassword, "admin_cdk");

if ($action === "list") {
    api_ok(["records" => cdk_admin_payload($records)], "已加载。");
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

    $existingRecord = isset($records[$code]) ? cdk_normalize_record($records[$code]) : [];
    $records[$code] = [
        "title" => $title !== "" ? $title : "CDK 信息",
        "content" => $content,
        "enabled" => $enabled,
        "sold" => (bool) ($request["sold"] ?? ($existingRecord["sold"] ?? false)),
        "updated_at" => date("Y-m-d H:i:s"),
        "first_claimed_at" => (string) ($existingRecord["first_claimed_at"] ?? ""),
    ];

    if (!cdk_save_file($records)) {
        api_fail("CDK 保存失败。", 500);
    }

    api_ok(["records" => cdk_admin_payload($records)], "已保存。");
}

if ($action === "set_sold") {
    $code = cdk_normalize_code((string) ($request["cdk"] ?? ""));
    if ($code === "" || !isset($records[$code])) {
        api_fail("CDK 不存在或已失效。", 404);
    }

    $record = cdk_normalize_record($records[$code]);
    $record["sold"] = (bool) ($request["sold"] ?? false);
    $record["updated_at"] = date("Y-m-d H:i:s");
    $records[$code] = $record;

    if (!cdk_save_file($records)) {
        api_fail("售出状态保存失败。", 500);
    }

    api_ok(["records" => cdk_admin_payload($records)], "售出状态已更新。");
}

if ($action === "delete") {
    $code = cdk_normalize_code((string) ($request["cdk"] ?? ""));
    if ($code === "") {
        api_fail("请填写 CDK。", 400);
    }

    unset($records[$code]);
    if (!cdk_save_file($records)) {
        api_fail("CDK 删除失败。", 500);
    }

    api_ok(["records" => cdk_admin_payload($records)], "已删除。");
}

api_fail("不支持的操作。", 400);
