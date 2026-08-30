<?php

declare(strict_types=1);

namespace QrRally\Install;

final class ConfigWriter
{
    public function __construct(private readonly string $publicConfigPath)
    {
    }

    /** @param array<string, bool|int|string> $values */
    public function write(string $privateConfigPath, array $values): void
    {
        if (is_file($this->publicConfigPath) || is_file($privateConfigPath)) {
            throw new InstallException('設定ファイルがすでに存在します。');
        }
        $privateDirectory = dirname($privateConfigPath);
        if (!is_dir($privateDirectory) && !mkdir($privateDirectory, 0700, true) && !is_dir($privateDirectory)) {
            throw new InstallException('非公開設定ディレクトリを作成できません。');
        }
        $publicDirectory = dirname($this->publicConfigPath);
        if (!is_dir($publicDirectory) || !is_writable($publicDirectory)) {
            throw new InstallException('設定ローダーの保存先へ書き込めません。');
        }

        $privateContents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($values, true) . ";\n";
        $privateTemporary = $privateConfigPath . '.tmp-' . bin2hex(random_bytes(8));
        $publicTemporary = $this->publicConfigPath . '.tmp-' . bin2hex(random_bytes(8));
        try {
            if (file_put_contents($privateTemporary, $privateContents, LOCK_EX) === false
                || !chmod($privateTemporary, 0600) || !rename($privateTemporary, $privateConfigPath)) {
                throw new InstallException('非公開設定ファイルを保存できません。');
            }
            $loader = "<?php\n\ndeclare(strict_types=1);\n\nreturn require " . var_export($privateConfigPath, true) . ";\n";
            if (file_put_contents($publicTemporary, $loader, LOCK_EX) === false
                || !chmod($publicTemporary, 0600) || !rename($publicTemporary, $this->publicConfigPath)) {
                throw new InstallException('設定ローダーを保存できません。');
            }
        } catch (\Throwable $error) {
            @unlink($privateTemporary);
            @unlink($publicTemporary);
            @unlink($privateConfigPath);
            throw $error;
        }
    }
}
