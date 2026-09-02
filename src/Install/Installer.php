<?php

declare(strict_types=1);

namespace QrRally\Install;

use PDO;
use QrRally\Auth\PasswordPolicy;
use QrRally\Auth\RecoveryKey;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Repository\AdminRepository;
use QrRally\Repository\AuditLogRepository;
use QrRally\Backup\BackupManager;
use QrRally\Backup\BackupException;
use Throwable;

final class Installer
{
    public function __construct(
        private readonly string $applicationRoot,
        private readonly string $publicConfigPath,
        private readonly EnvironmentInspector $inspector,
        private readonly PasswordPolicy $passwordPolicy = new PasswordPolicy(),
        private readonly RecoveryKey $recoveryKeys = new RecoveryKey(),
    ) {
    }

    /** @param array<string, mixed> $server
     *  @return array{recovery_key: string, login_url: string, path_diagnostics: array<string, bool>}
     */
    public function install(InstallInput $input, array $server): array
    {
        $errors = $this->inspector->validateInput($input, $server);
        if (!filter_var($input->email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = '有効な管理者メールアドレスを入力してください。';
        }
        $passwordError = $this->passwordPolicy->validate($input->password);
        if ($passwordError !== null) {
            $errors['password'] = $passwordError;
        }
        if ($input->password !== $input->passwordConfirmation) {
            $errors['password_confirmation'] = '確認用パスワードが一致しません。';
        }
        if ($errors !== []) {
            throw new InstallException('入力内容にエラーがあります。各項目の赤いメッセージを確認してください。', $errors);
        }
        $basic = $this->inspector->basic($server);
        if (!$basic['requirements_met']) {
            throw new InstallException('必須環境を満たしていないためインストールできません。');
        }
        $pathDiagnostics = $this->inspector->inspectPrivatePath($input->privatePath, $server);
        $result = $this->withInstallLock(function () use ($input): array {
            return $this->performNewInstall($input);
        });
        $result['path_diagnostics'] = $pathDiagnostics;
        return $result;
    }

    /** @param array<string, mixed> $server
     *  @return array{work_directory: string, database_path: string, archive_hash: string, manifest: array<string, mixed>, path_diagnostics: array<string, bool>}
     */
    public function validateRestore(InstallInput $input, array $server, string $uploadedPath, int $size): array
    {
        $errors = $this->inspector->validateInput($input, $server);
        if ($errors !== []) {
            throw new InstallException('入力内容にエラーがあります。各項目の赤いメッセージを確認してください。', $errors);
        }
        if (!$this->inspector->basic($server)['requirements_met']) {
            throw new InstallException('必須環境を満たしていないため復元できません。');
        }
        $pathDiagnostics = $this->inspector->inspectPrivatePath($input->privatePath, $server);
        $workRoot = rtrim($input->privatePath, DIRECTORY_SEPARATOR) . '/storage/backups';
        try {
            $manager = new BackupManager('', $workRoot, $input->baseUrl);
            $manager->cleanupExpired();
            $validated = $manager->validateUploadedForInstall($uploadedPath, $size);
            $validated['path_diagnostics'] = $pathDiagnostics;
            return $validated;
        } catch (BackupException $error) {
            throw new InstallException($error->getMessage(), [], $error);
        }
    }

    /** @param array<string, mixed> $server
     *  @param array<string, mixed> $manifest
     *  @return array{login_url: string, source_base_url: string}
     */
    public function restore(InstallInput $input, array $server, string $candidatePath, array $manifest): array
    {
        $errors = $this->inspector->validateInput($input, $server);
        if ($errors !== []) {
            throw new InstallException('入力内容が現在の設置先と一致しません。', $errors);
        }
        $this->inspector->inspectPrivatePath($input->privatePath, $server);
        return $this->withInstallLock(function () use ($input, $candidatePath, $manifest): array {
            $root = rtrim($input->privatePath, DIRECTORY_SEPARATOR);
            $storage = $root . '/storage';
            $candidateReal = realpath($candidatePath);
            $backupRootReal = realpath($storage . '/backups');
            if ($candidateReal === false || $backupRootReal === false
                || !str_starts_with($candidateReal, $backupRootReal . DIRECTORY_SEPARATOR)) {
                throw new InstallException('復元用データの保存場所を確認できません。もう一度ZIPを検証してください。');
            }
            $databasePath = $storage . '/app.sqlite';
            $privateConfigPath = $root . '/config/app.php';
            if (file_exists($databasePath) || file_exists($privateConfigPath)) {
                throw new InstallException('指定した非公開領域には既存のDBまたは設定があります。');
            }
            foreach ([$storage, $storage . '/backups', $storage . '/logs', $storage . '/runtime', dirname($privateConfigPath)] as $directory) {
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new InstallException('非公開領域の必要なディレクトリを作成できません。');
                }
            }
            try {
                $manager = new BackupManager('', $storage . '/backups', $input->baseUrl);
                $manager->installValidatedDatabase($candidatePath, $databasePath, $manifest);
                $database = (new ConnectionFactory())->connect($databasePath, 5000);
                $values = $this->configuration(
                    $input,
                    $databasePath,
                    $manager->consumeSpotTokenKey($database),
                );
                if ($database->query('PRAGMA integrity_check')->fetchColumn() !== 'ok'
                    || $database->query('PRAGMA foreign_key_check')->fetch() !== false) {
                    throw new InstallException('復元後のDB整合性を確認できません。');
                }
                $database->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                unset($database);
                (new ConfigWriter($this->publicConfigPath))->write(
                    $privateConfigPath,
                    $values,
                );
                $manager->cleanup(dirname($candidatePath));
                return [
                    'login_url' => $input->baseUrl . 'admin/login',
                    'source_base_url' => (string) ($manifest['source_base_url'] ?? ''),
                ];
            } catch (Throwable $error) {
                @unlink($databasePath);
                @unlink($databasePath . '-wal');
                @unlink($databasePath . '-shm');
                @unlink($privateConfigPath);
                if (isset($manager)) {
                    $manager->cleanup(dirname($candidatePath));
                }
                throw $error instanceof InstallException
                    ? $error
                    : new InstallException('新しい環境への復元に失敗しました。設定は作成されていません。', [], $error);
            }
        });
    }

    /** @param callable(): array<string, mixed> $operation
     *  @return array<string, mixed>
     */
    private function withInstallLock(callable $operation): array
    {
        $lockDirectory = dirname($this->publicConfigPath);
        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0755, true) && !is_dir($lockDirectory)) {
            throw new InstallException('設定ローダーの保存先を作成できません。');
        }
        $lockPath = $lockDirectory . DIRECTORY_SEPARATOR . '.install-running';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new InstallException('別のインストール処理が実行中です。少し待ってから確認してください。');
        }
        try {
            if (is_file($this->publicConfigPath)) {
                throw new InstallException('このアプリはすでにインストールされています。');
            }
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
    }

    /** @return array{recovery_key: string, login_url: string} */
    private function performNewInstall(InstallInput $input): array
    {
        $root = rtrim($input->privatePath, DIRECTORY_SEPARATOR);
        $storage = $root . DIRECTORY_SEPARATOR . 'storage';
        $configDirectory = $root . DIRECTORY_SEPARATOR . 'config';
        $databasePath = $storage . DIRECTORY_SEPARATOR . 'app.sqlite';
        $privateConfigPath = $configDirectory . DIRECTORY_SEPARATOR . 'app.php';
        if (file_exists($databasePath) || file_exists($privateConfigPath)) {
            throw new InstallException('指定した非公開領域には既存のDBまたは設定があります。');
        }
        foreach ([$storage, $storage . '/backups', $storage . '/logs', $storage . '/runtime', $configDirectory] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new InstallException('非公開領域の必要なディレクトリを作成できません。');
            }
        }
        $temporaryDatabase = $storage . DIRECTORY_SEPARATOR . '.install-' . bin2hex(random_bytes(8)) . '.sqlite';
        $recoveryKey = $this->recoveryKeys->generate();
        try {
            $database = (new ConnectionFactory())->connect($temporaryDatabase, 5000);
            (new Migrator($database, $this->applicationRoot . '/database/migrations'))->migrate();
            $admins = new AdminRepository($database);
            if ($admins->count() !== 0) {
                throw new InstallException('一時DBに既存管理者が見つかりました。');
            }
            $adminId = $admins->create(
                $input->email,
                password_hash($input->password, PASSWORD_DEFAULT),
                password_hash($recoveryKey, PASSWORD_DEFAULT),
            );
            (new AuditLogRepository($database))->record('admin.created', 'system', null, 'success', [], 'admin', $adminId);
            $requiredTables = [
                'admins', 'app_settings', 'events', 'application_fields', 'spots', 'participants',
                'stamp_acquisitions', 'applications', 'audit_logs', 'backup_operations',
                'admin_login_attempts', 'schema_migrations',
            ];
            $tables = array_map('strval', $database->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
            )->fetchAll(PDO::FETCH_COLUMN));
            if ($database->query('PRAGMA integrity_check')->fetchColumn() !== 'ok'
                || $database->query('PRAGMA foreign_key_check')->fetch() !== false
                || $admins->count() !== 1
                || array_diff($requiredTables, $tables) !== []) {
                throw new InstallException('作成したDBの整合性を確認できません。');
            }
            $database->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            unset($database, $admins);
            if (!rename($temporaryDatabase, $databasePath) || !chmod($databasePath, 0600)) {
                throw new InstallException('作成したDBを正式な保存先へ配置できません。');
            }
            $values = $this->configuration($input, $databasePath);
            (new ConfigWriter($this->publicConfigPath))->write($privateConfigPath, $values);
            return ['recovery_key' => $recoveryKey, 'login_url' => $input->baseUrl . 'admin/login'];
        } catch (Throwable $error) {
            @unlink($temporaryDatabase);
            @unlink($temporaryDatabase . '-wal');
            @unlink($temporaryDatabase . '-shm');
            @unlink($databasePath);
            @unlink($databasePath . '-wal');
            @unlink($databasePath . '-shm');
            @unlink($privateConfigPath);
            throw $error instanceof InstallException
                ? $error
                : new InstallException('インストールに失敗しました。正式なDBと設定は作成されていません。', [], $error);
        }
    }

    /** @return array<string, bool|int|string> */
    public function configuration(InstallInput $input, string $databasePath, ?string $spotTokenKey = null): array
    {
        $secure = str_starts_with($input->baseUrl, 'https://');
        $development = $input->environment === 'development';
        return [
            'env' => $input->environment,
            'base_url' => $input->baseUrl,
            'app_key' => bin2hex(random_bytes(32)),
            'spot_token_key' => $spotTokenKey ?? bin2hex(random_bytes(32)),
            'timezone' => $input->timezone,
            'debug' => $development,
            'log_level' => $development ? 'debug' : 'error',
            'cookie_secure' => $secure,
            'allow_development_tools' => $development,
            'database_path' => $databasePath,
            'database_busy_timeout_ms' => 5000,
            'session_name' => 'qr_rally_admin',
            'session_lifetime_seconds' => 7200,
        ];
    }
}
