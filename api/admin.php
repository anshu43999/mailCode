<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/lib/api_helpers.php";
require_once dirname(__DIR__) . "/lib/account_store.php";
require_once dirname(__DIR__) . "/lib/cdk_store.php";
require_once dirname(__DIR__) . "/lib/security.php";

$config = api_load_config();
$request = api_request_data();
$action = strtolower(trim((string) ($request["action"] ?? "list")));
$adminPassword = trim((string) ($request["admin_password"] ?? ""));

security_require_admin_password($config, $adminPassword, "admin_accounts");

if (!in_array($action, ["list", "save", "delete", "set_used", "add_to_cdk"], true)) {
    api_fail("不支持的操作。", 400);
}

$accounts = accounts_load_file();

if ($action === "save") {
    $email = api_normalize_email((string) ($request["email"] ?? ""));
    $password = trim((string) ($request["access_password"] ?? ""));

    if ($email === "") {
        api_fail("请填写邮箱。", 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_fail("邮箱格式不正确。", 400);
    }
    if ($password === "") {
        api_fail("请填写查询密码。", 400);
    }

    $accounts[$email] = [
        "hash" => accounts_hash_password($password),
        "secret" => accounts_encrypt_password($password, $adminPassword, $email),
        "used" => !empty($accounts[$email]["used"]),
    ];
    if (!accounts_save_file($accounts)) {
        api_fail("账号保存失败。", 500);
    }

    api_ok(["accounts" => accounts_payload($accounts, $adminPassword)], "已保存。");
}

if ($action === "delete") {
    $email = api_normalize_email((string) ($request["email"] ?? ""));
    if ($email === "") {
        api_fail("请填写邮箱。", 400);
    }

    unset($accounts[$email]);
    if (!accounts_save_file($accounts)) {
        api_fail("账号删除失败。", 500);
    }

    api_ok(["accounts" => accounts_payload($accounts, $adminPassword)], "已删除。");
}

if ($action === "set_used") {
    $email = api_normalize_email((string) ($request["email"] ?? ""));
    if ($email === "") {
        api_fail("请填写邮箱。", 400);
    }
    if (!isset($accounts[$email])) {
        api_fail("账号不存在。", 404);
    }

    $accounts[$email]["used"] = (bool) ($request["used"] ?? false);
    if (!accounts_save_file($accounts)) {
        api_fail("账号状态保存失败。", 500);
    }

    api_ok(["accounts" => accounts_payload($accounts, $adminPassword)], "状态已更新。");
}

if ($action === "add_to_cdk") {
    $email = api_normalize_email((string) ($request["email"] ?? ""));
    $password = trim((string) ($request["source_password"] ?? ($request["access_password"] ?? "")));
    $title = trim((string) ($request["title"] ?? "账号 -密码-邮箱接码地址-邮件查询密码"));
    $url = trim((string) ($request["url"] ?? "http://49.51.182.250:8090/index.html"));

    if ($email === "") {
        api_fail("请先选择账号。", 400);
    }
    if (!isset($accounts[$email])) {
        api_fail("账号不存在。", 404);
    }
    if ($password === "") {
        api_fail("账号密码不能为空。", 400);
    }

    $records = cdk_load_file();
    $code = cdk_generate_code($records);
    $records[$code] = [
        "title" => $title !== "" ? $title : "账号 -密码-邮箱接码地址-邮件查询密码",
        "content" => $email . " --- " . $url . " --- " . $password,
        "enabled" => true,
        "sold" => false,
        "updated_at" => date("Y-m-d H:i:s"),
        "first_claimed_at" => "",
    ];

    if (!cdk_save_file($records)) {
        api_fail("添加到 CDK 失败。", 500);
    }

    api_ok([
        "accounts" => accounts_payload($accounts, $adminPassword),
        "records" => cdk_admin_payload($records),
        "cdk" => cdk_public_payload($code, $records[$code]),
    ], "已添加到 CDK。");
}

api_ok(["accounts" => accounts_payload($accounts, $adminPassword)], "已加载。");
