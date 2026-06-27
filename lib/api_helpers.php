<?php
declare(strict_types=1);

ini_set("display_errors", "0");
ini_set("display_startup_errors", "0");
ini_set("html_errors", "0");
ini_set("log_errors", "1");

$timezone = getenv("APP_TIMEZONE") ?: "Asia/Shanghai";
if (in_array($timezone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($timezone);
}

if (ob_get_level() === 0) {
    ob_start();
}

function api_send_json(array $payload, int $statusCode = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function api_load_config(?string $configPath = null): array
{
    $configPath = $configPath ?: dirname(__DIR__) . "/config/mail.php";
    if (!is_file($configPath)) {
        return [];
    }

    $config = require $configPath;
    return is_array($config) ? $config : [];
}

function api_request_data(): array
{
    $contentType = (string) ($_SERVER["CONTENT_TYPE"] ?? "");
    if (stripos($contentType, "application/json") !== false) {
        $raw = file_get_contents("php://input");
        $decoded = json_decode($raw ?: "[]", true);
        return is_array($decoded) ? $decoded : [];
    }

    return is_array($_POST) ? $_POST : [];
}

function api_request_method(): string
{
    return strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
}

function api_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function api_ok(array $data = [], string $message = "OK", int $statusCode = 200): void
{
    api_send_json([
        "success" => true,
        "message" => $message,
        "data" => $data,
    ], $statusCode);
}

function api_fail(string $message, int $statusCode = 400, $data = null): void
{
    api_send_json([
        "success" => false,
        "message" => $message,
        "data" => $data,
    ], $statusCode);
}
