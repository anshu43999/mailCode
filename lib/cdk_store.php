<?php
declare(strict_types=1);

require_once __DIR__ . "/api_helpers.php";

function cdk_file_path(): string
{
    return dirname(__DIR__) . "/config/cdk_records.json";
}

function cdk_normalize_code(string $code): string
{
    return strtoupper(trim($code));
}

function cdk_generate_code(array $existingRecords, int $groups = 4, int $groupLength = 4): string
{
    $alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $existing = cdk_normalize_map($existingRecords);
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $parts = [];
        for ($group = 0; $group < $groups; $group++) {
            $part = "";
            for ($i = 0; $i < $groupLength; $i++) {
                $part .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $parts[] = $part;
        }
        $code = implode("-", $parts);
        if (!isset($existing[$code])) {
            return $code;
        }
    }
    return "CDK-" . strtoupper(bin2hex(random_bytes(8)));
}

function cdk_normalize_record(array|string $record): array
{
    if (is_string($record)) {
        return [
            "title" => "CDK 信息",
            "content" => $record,
            "enabled" => true,
            "updated_at" => "",
        ];
    }

    return [
        "title" => trim((string) ($record["title"] ?? "CDK 信息")),
        "content" => trim((string) ($record["content"] ?? "")),
        "enabled" => (bool) ($record["enabled"] ?? true),
        "updated_at" => trim((string) ($record["updated_at"] ?? "")),
    ];
}

function cdk_normalize_map(array $records): array
{
    $normalized = [];
    foreach ($records as $code => $record) {
        $normalizedCode = cdk_normalize_code((string) $code);
        if ($normalizedCode === "") {
            continue;
        }
        $normalized[$normalizedCode] = cdk_normalize_record(is_array($record) ? $record : (string) $record);
    }
    ksort($normalized);
    return $normalized;
}

function cdk_load_file(): array
{
    $file = cdk_file_path();
    if (!is_file($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    $decoded = json_decode($raw ?: "{}", true);
    return is_array($decoded) ? cdk_normalize_map($decoded) : [];
}

function cdk_save_file(array $records): bool
{
    $file = cdk_file_path();
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return false;
    }

    $json = json_encode(cdk_normalize_map($records), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    return file_put_contents($file, $json . PHP_EOL, LOCK_EX) !== false;
}

function cdk_public_payload(string $code, array $record): array
{
    return [
        "cdk" => cdk_normalize_code($code),
        "title" => (string) ($record["title"] ?? "CDK 信息"),
        "content" => (string) ($record["content"] ?? ""),
        "updated_at" => (string) ($record["updated_at"] ?? ""),
    ];
}

function cdk_admin_payload(array $records): array
{
    $items = [];
    foreach (cdk_normalize_map($records) as $code => $record) {
        $items[] = [
            "cdk" => $code,
            "title" => (string) ($record["title"] ?? "CDK 信息"),
            "content" => (string) ($record["content"] ?? ""),
            "enabled" => (bool) ($record["enabled"] ?? true),
            "updated_at" => (string) ($record["updated_at"] ?? ""),
        ];
    }
    return $items;
}
