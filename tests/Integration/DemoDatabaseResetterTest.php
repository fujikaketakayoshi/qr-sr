<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Ops\Demo\DemoDatabaseResetter;

require_once dirname(__DIR__, 2) . '/ops/demo-vps/lib/DemoDatabaseResetter.php';

final class DemoDatabaseResetterTest extends TestCase
{
    private string $directory;
    private string $databasePath;
    private DemoDatabaseResetter $resetter;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/demo-reset-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->databasePath = $this->directory . '/app.sqlite';
        $database = (new ConnectionFactory())->connect($this->databasePath);
        (new Migrator($database, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->seed($database);
        unset($database);
        $this->resetter = new DemoDatabaseResetter(
            $this->databasePath,
            $this->directory . '/demo/baseline.sqlite',
            $this->directory . '/backups/restore-state.json',
            $this->directory . '/runtime/demo-reset.lock',
            $this->directory . '/logs/demo-reset.log',
            'Asia/Tokyo',
        );
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testCapturesAndAtomicallyRestoresBaselineWithFreshDates(): void
    {
        $this->resetter->captureBaseline();
        $database = new PDO('sqlite:' . $this->databasePath);
        $database->exec("UPDATE spots SET name = '変更後'");
        $database->exec("INSERT INTO spots (public_token_hash, name, description, display_order, is_active, created_at, updated_at, token_version) VALUES ('extra', '追加', '', 4, 1, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', 1)");
        unset($database);

        $this->resetter->reset(new DateTimeImmutable('2026-10-01 12:30:00+00:00'));

        $restored = new PDO('sqlite:' . $this->databasePath);
        self::assertSame(3, (int) $restored->query('SELECT COUNT(*) FROM spots')->fetchColumn());
        self::assertSame('まちの電気屋さん', $restored->query('SELECT name FROM spots ORDER BY display_order LIMIT 1')->fetchColumn());
        $event = $restored->query('SELECT starts_at, ends_at, application_deadline_at, application_enabled, is_paused FROM events')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('2026-09-30T12:30:00Z', $event['starts_at']);
        self::assertSame('2026-10-31T12:30:00Z', $event['ends_at']);
        self::assertSame('2026-11-01T12:30:00Z', $event['application_deadline_at']);
        self::assertSame(1, (int) $event['application_enabled']);
        self::assertSame(0, (int) $event['is_paused']);
        self::assertStringContainsString('status=completed', (string) file_get_contents($this->directory . '/logs/demo-reset.log'));
    }

    private function seed(PDO $database): void
    {
        $database->exec("INSERT INTO admins (email, password_hash, recovery_key_hash, created_at, updated_at, auth_version) VALUES ('qr-sr-demo@example.com', 'hash', 'recovery', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', 1)");
        $database->exec("INSERT INTO events (id, name, description, notice_text, starts_at, ends_at, required_stamp_count, application_enabled, application_deadline_at, created_at, updated_at) VALUES (1, '商店街スタンプラリー', '', '', '2026-01-01T00:00:00Z', '2026-12-31T00:00:00Z', 3, 1, '2027-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
        foreach (['まちの電気屋さん', 'ひだまりベーカリー', 'さくら書店'] as $index => $name) {
            $order = $index + 1;
            $database->exec("INSERT INTO spots (public_token_hash, name, description, display_order, is_active, created_at, updated_at, token_version) VALUES ('spot{$order}', " . $database->quote($name) . ", '', {$order}, 1, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', 1)");
        }
        $database->exec("INSERT INTO participants (token_hash, nickname, first_seen_at, last_seen_at, completed_at, created_at, updated_at) VALUES ('participant', 'デモ参加者', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
        $database->exec("INSERT INTO applications (participant_id, application_number, name, privacy_accepted_at, submitted_at, updated_at) VALUES (1, 'DEMO-001', '山田 デモ', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $this->remove($path . '/' . $entry);
        @rmdir($path);
    }
}
