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
    foreach ($accounts as $email => $passwordHash) {
        $normalizedEmail = api_normalize_email((string) $email);
        if ($normalizedEmail === "") {
            continue;
        }
        $normalized[$normalizedEmail] = (string) $passwordHash;
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

function accounts_payload(array $accounts): array
{
    return array_values(array_map(static fn(string $email): array => ["email" => $email], array_keys(accounts_normalize_map($accounts))));
}

function accounts_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function accounts_password_matches(string $storedValue, string $password): bool
{
    if ($storedValue === "" || $password === "") {
        return false;
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
        api_fail("Target email is required.", 400);
    }
    if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
        api_fail("Target email is invalid.", 400);
    }
    $accounts = accounts_load_allowed($config);
    if (!isset($accounts[$targetEmail])) {
        api_fail("This email is not allowed.", 403);
    }
    if (!accounts_password_matches((string) $accounts[$targetEmail], $password)) {
        api_fail("Query password is incorrect.", 403);
    }
}
