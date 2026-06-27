<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/lib/api_helpers.php";
require_once dirname(__DIR__) . "/lib/account_store.php";
require_once dirname(__DIR__) . "/lib/security.php";

$config = api_load_config();
$request = api_request_data();
$action = strtolower(trim((string) ($request["action"] ?? "list")));
$adminPassword = trim((string) ($request["admin_password"] ?? ""));

security_require_admin_password($config, $adminPassword, "admin_accounts");

if (!in_array($action, ["list", "save", "delete", "set_used"], true)) {
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

api_ok(["accounts" => accounts_payload($accounts, $adminPassword)], "已加载。");
