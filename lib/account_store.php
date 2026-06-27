<?php
declare(strict_types=1);

require_once __DIR__ . "/api_helpers.php";

function accounts_file_path(): string
{
    return dirname(__DIR__) . "/config/access_accounts.json";
}

function accounts_normalize_map(array $accounts): array
{
    $normalized = [];
    foreach ($accounts as $email => $record) {
        $normalizedEmail = api_normalize_email((string) $email);
        if ($normalizedEmail === "") {
            continue;
        }
        if (is_array($record)) {
            $hash = (string) ($record["hash"] ?? $record["password_hash"] ?? $record["password"] ?? "");
            $secret = array_key_exists("secret", $record) ? (string) $record["secret"] : "";
        } else {
            $hash = (string) $record;
            $secret = "";
        }
        $normalized[$normalizedEmail] = [
            "hash" => $hash,
            "secret" => $secret,
        ];
    }
    ksort($normalized);
    return $normalized;
}

function accounts_load_file(): array
{
    $file = accounts_file_path();
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $decoded = json_decode($raw ?: "{}", true);
    return is_array($decoded) ? accounts_normalize_map($decoded) : [];
}

function accounts_load_allowed(array $config): array
{
    $configured = $config["access_accounts"] ?? [];
    if (!is_array($configured)) {
        $configured = [];
    }
    return accounts_normalize_map(array_merge($configured, accounts_load_file()));
}

function accounts_save_file(array $accounts): bool
{
    $file = accounts_file_path();
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return false;
    }
    $json = json_encode(accounts_normalize_map($accounts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    return file_put_contents($file, $json . PHP_EOL, LOCK_EX) !== false;
}

function accounts_payload(array $accounts, ?string $adminPassword = null): array
{
    return accounts_payload_with_admin_password($accounts, $adminPassword);
}

function accounts_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function accounts_admin_key(string $adminPassword, string $email): string
{
    return hash("sha256", "mailCode|" . api_normalize_email($email) . "|" . $adminPassword, true);
}

function accounts_encrypt_password(string $password, string $adminPassword, string $email): string
{
    $iv = random_bytes(12);
    $tag = "";
    $ciphertext = openssl_encrypt(
        $password,
        "aes-256-gcm",
        accounts_admin_key($adminPassword, $email),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if ($ciphertext === false) {
        return "";
    }
    return base64_encode($iv . $tag . $ciphertext);
}

function accounts_decrypt_password(string $secret, string $adminPassword, string $email): string
{
    $raw = base64_decode($secret, true);
    if ($raw === false || strlen($raw) < 28) {
        return "";
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plain = openssl_decrypt(
        $ciphertext,
        "aes-256-gcm",
        accounts_admin_key($adminPassword, $email),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    return $plain === false ? "" : (string) $plain;
}

function accounts_password_matches(array|string $storedValue, string $password): bool
{
    if ($storedValue === "" || $password === "") {
        return false;
    }
    if (is_array($storedValue)) {
        $storedValue = (string) ($storedValue["hash"] ?? "");
    }
    $info = password_get_info($storedValue);
    if (($info["algo"] ?? 0) !== 0) {
        return password_verify($password, $storedValue);
    }
    return hash_equals($storedValue, $password);
}

function accounts_require_access(array $config, string $targetEmail, string $password): void
{
    if ($targetEmail === "") {
        api_fail("请填写目标邮箱。", 400);
    }
    if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
        api_fail("目标邮箱格式不正确。", 400);
    }
    $accounts = accounts_load_allowed($config);
    if (!isset($accounts[$targetEmail])) {
        api_fail("该邮箱未配置，暂不能查询。", 403);
    }
    if (!accounts_password_matches($accounts[$targetEmail], $password)) {
        api_fail("查询密码不正确。", 403);
    }
}

function accounts_payload_with_admin_password(array $accounts, ?string $adminPassword): array
{
    $normalized = accounts_normalize_map($accounts);
    $items = [];
    foreach ($normalized as $email => $record) {
        $item = ["email" => $email];
        if ($adminPassword !== null) {
            $secret = (string) ($record["secret"] ?? "");
            $item["access_password"] = $secret !== "" ? accounts_decrypt_password($secret, $adminPassword, $email) : "";
        }
        $items[] = $item;
    }
    return $items;
}
