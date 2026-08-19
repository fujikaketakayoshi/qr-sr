<?php

declare(strict_types=1);

namespace QrRally\Backup;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class RestoreStateStore
{
    public function __construct(private readonly string $path)
    {
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $json = file_get_contents($this->path);
        if ($json === false) {
            return null;
        }
        try {
            $state = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($state) || !isset($state['expires_at']) || !is_string($state['expires_at'])) {
            return null;
        }
        if (new DateTimeImmutable($state['expires_at']) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            $this->clear($state);
            return null;
        }

        return $state;
    }

    /** @param array<string, mixed> $values */
    public function write(array $values): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new BackupException('復元用の一時保存先を作成できません。');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $state = array_merge($values, [
            'updated_at' => $now->format(DATE_ATOM),
            'expires_at' => $now->modify('+1 hour')->format(DATE_ATOM),
        ]);
        if (!isset($state['started_at'])) {
            $state['started_at'] = $now->format(DATE_ATOM);
        }
        $temporary = $this->path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !chmod($temporary, 0600)
            || !rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new BackupException('復元状態を保存できません。');
        }
    }

    /** @param array<string, mixed>|null $state */
    public function clear(?array $state = null): void
    {
        $state ??= $this->readWithoutExpiry();
        if (is_array($state) && isset($state['work_directory']) && is_string($state['work_directory'])) {
            $this->removeDirectory($state['work_directory']);
        }
        @unlink($this->path);
    }

    public function isMaintenance(): bool
    {
        return ($this->read()['maintenance'] ?? false) === true;
    }

    /** @return array<string, mixed>|null */
    private function readWithoutExpiry(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function removeDirectory(string $directory): void
    {
        $real = realpath($directory);
        $root = realpath(dirname($this->path));
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return;
        }
        foreach (glob($real . '/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }
        }
        @rmdir($real);
    }
}
