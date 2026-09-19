<?php

declare(strict_types=1);

namespace QrRally\Support;

use PDO;
use RuntimeException;

final class ProductVersion
{
    public function __construct(private readonly string $filePath)
    {
    }

    public static function current(): string
    {
        return (new self(dirname(__DIR__, 2) . '/VERSION'))->value();
    }

    public function value(): string
    {
        $version = is_file($this->filePath) ? trim((string) file_get_contents($this->filePath)) : '';
        if (!preg_match(
            '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
            . '(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D',
            $version,
        )) {
            throw new RuntimeException('VERSIONファイルの製品バージョンが不正です。');
        }

        return $version;
    }

    public function synchronize(PDO $database): void
    {
        $current = $database->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'product_version'",
        )->fetchColumn();
        if (!is_string($current)) {
            throw new RuntimeException('DBに製品バージョン情報がありません。');
        }

        $version = $this->value();
        if (hash_equals($version, $current)) {
            return;
        }

        $statement = $database->prepare(
            "UPDATE app_settings SET setting_value = :version, "
            . "updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') "
            . "WHERE setting_key = 'product_version'",
        );
        $statement->execute(['version' => $version]);
    }
}
