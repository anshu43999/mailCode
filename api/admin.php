<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/lib/api_helpers.php";
require_once dirname(__DIR__) . "/lib/account_store.php";

$config = api_load_config();
$request = api_request_data();
$action = strtolower(trim((string) ($request["action"] ?? "list")));
$adminPassword = trim((string) ($request["admin_password"] ?? ""));
$expectedPassword = trim((string) ($config["admin_panel"]["password"] ?? "admin123"));

if ($adminPassword === "" || !hash_equals($expectedPassword, $adminPassword)) {
    api_fail("Admin password is incorrect.", 403);
}

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

    $accounts[$email] = accounts_hash_password($password);
    if (!accounts_save_file($accounts)) {
        api_fail("Failed to save account.", 500);
    }

    api_ok(["accounts" => accounts_payload($accounts)], "Saved.");
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

    api_ok(["accounts" => accounts_payload($accounts)], "Deleted.");
}

api_ok(["accounts" => accounts_payload($accounts)], "Loaded.");
