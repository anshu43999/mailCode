<?php
declare(strict_types=1);

require_once __DIR__ . "/api_helpers.php";

function security_events_file_path(): string
{
    return dirname(__DIR__) . "/config/security_events.json";
}

function security_client_ip(): string
{
    $candidates = [
        (string) ($_SERVER["HTTP_CF_CONNECTING_IP"] ?? ""),
        (string) ($_SERVER["HTTP_X_REAL_IP"] ?? ""),
        (string) ($_SERVER["REMOTE_ADDR"] ?? ""),
    ];
    foreach ($candidates as $candidate) {
        $ip = trim(explode(",", $candidate)[0] ?? "");
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return "unknown";
}

function security_load_events(): array
{
    $file = security_events_file_path();
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $decoded = json_decode($raw ?: "{}", true);
    return is_array($decoded) ? $decoded : [];
}

function security_save_events(array $events): bool
{
    $file = security_events_file_path();
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return false;
    }
    $json = json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    return file_put_contents($file, $json . PHP_EOL, LOCK_EX) !== false;
}

function security_key(string $scope, ?string $ip = null): string
{
    return preg_replace("/[^a-zA-Z0-9_.:-]+/", "_", $scope . "|" . ($ip ?: security_client_ip())) ?? $scope;
}

function security_prune_attempts(array $attempts, int $windowSeconds, int $now): array
{
    return array_values(array_filter($attempts, static fn($time): bool => is_int($time) && $time >= ($now - $windowSeconds)));
}

function security_assert_not_limited(string $scope, int $maxAttempts, int $windowSeconds, int $lockSeconds): void
{
    $now = time();
    $events = security_load_events();
    $key = security_key($scope);
    $entry = is_array($events[$key] ?? null) ? $events[$key] : [];
    $lockedUntil = (int) ($entry["locked_until"] ?? 0);

    if ($lockedUntil > $now) {
        api_fail("操作过于频繁，请稍后再试。", 429, ["retry_after_seconds" => $lockedUntil - $now]);
    }

    $attempts = security_prune_attempts(is_array($entry["attempts"] ?? null) ? $entry["attempts"] : [], $windowSeconds, $now);
    if (count($attempts) >= $maxAttempts) {
        $events[$key] = [
            "attempts" => $attempts,
            "locked_until" => $now + $lockSeconds,
        ];
        security_save_events($events);
        api_fail("操作过于频繁，请稍后再试。", 429, ["retry_after_seconds" => $lockSeconds]);
    }
}

function security_record_failure(string $scope, int $maxAttempts, int $windowSeconds, int $lockSeconds): void
{
    $now = time();
    $events = security_load_events();
    $key = security_key($scope);
    $entry = is_array($events[$key] ?? null) ? $events[$key] : [];
    $attempts = security_prune_attempts(is_array($entry["attempts"] ?? null) ? $entry["attempts"] : [], $windowSeconds, $now);
    $attempts[] = $now;
    $events[$key] = [
        "attempts" => $attempts,
        "locked_until" => count($attempts) >= $maxAttempts ? $now + $lockSeconds : (int) ($entry["locked_until"] ?? 0),
    ];
    security_save_events($events);
}

function security_clear_failures(string $scope): void
{
    $events = security_load_events();
    $key = security_key($scope);
    if (isset($events[$key])) {
        unset($events[$key]);
        security_save_events($events);
    }
}

function security_require_admin_password(array $config, string $adminPassword, string $scope = "admin"): void
{
    security_assert_not_limited($scope, 5, 600, 1800);
    $expectedPassword = trim((string) ($config["admin_panel"]["password"] ?? ""));
    $blockedDefaults = ["admin123", "change-this-admin-password"];
    if ($expectedPassword === "" || in_array($expectedPassword, $blockedDefaults, true) || strlen($expectedPassword) < 12) {
        api_fail("管理员密码未安全配置，请先修改 config/mail.php。", 500);
    }
    if ($adminPassword === "" || !hash_equals($expectedPassword, $adminPassword)) {
        security_record_failure($scope, 5, 600, 1800);
        api_fail("认证失败。", 403);
    }
    security_clear_failures($scope);
}
