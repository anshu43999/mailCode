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

if (!in_array($action, ["list", "save", "delete"], true)) {
    api_fail("Unsupported action.", 400);
}

$accounts = accounts_load_file();

if ($action === "save") {
    $email = api_normalize_email((string) ($request["email"] ?? ""));
    $password = trim((string) ($request["access_password"] ?? ""));

    if ($email === "") {
        api_fail("Email is required.", 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_fail("Email is invalid.", 400);
    }
    if ($password === "") {
        api_fail("Query password is required.", 400);
    }

    $accounts[$email] = [
        "hash" => accounts_hash_password($password),
        "secret" => accounts_encrypt_password($password, $adminPassword, $email),
    ];
    if (!accounts_save_file($accounts)) {
        api_fail("Failed to save account.", 500);
    }

    api_ok(["accounts" => accounts_payload($accounts, $adminPassword)], "Saved.");
}

if ($action === "delete") {
    $email = api_normalize_email((string) ($request["email"] ?? ""));
    if ($email === "") {
        api_fail("Email is required.", 400);
    }

    unset($accounts[$email]);
    if (!accounts_save_file($accounts)) {
        api_fail("Failed to delete account.", 500);
    }

    api_ok(["accounts" => accounts_payload($accounts, $adminPassword)], "Deleted.");
}

api_ok(["accounts" => accounts_payload($accounts, $adminPassword)], "Loaded.");
