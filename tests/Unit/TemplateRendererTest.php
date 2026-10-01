<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QrRally\Domain\EventStatus;
use QrRally\View\TemplateRenderer;

final class TemplateRendererTest extends TestCase
{
    public function testAdminNavigationUsesDashboardLabel(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('admin/layout.php', [
            'title' => '管理画面',
            'content' => '',
            'flash' => null,
            'csrfToken' => 'test-token',
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ]);

        self::assertStringContainsString('>ダッシュボード</a>', $html);
        self::assertStringNotContainsString('>概要</a>', $html);
    }

    public function testDemoBannerShowsSharedEnvironmentAndNextReset(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('admin/layout.php', [
            'title' => '管理画面',
            'content' => '',
            'flash' => null,
            'csrfToken' => 'test-token',
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
            'demoMode' => true,
            'demoNextResetAt' => '2026年10月1日 22:00',
        ]);

        self::assertStringContainsString('公開デモ環境です', $html);
        self::assertStringContainsString('本名・実在する住所', $html);
        self::assertStringContainsString('2026年10月1日 22:00', $html);
    }

    public function testDemoBackupPageDoesNotRenderOperationForms(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('admin/backup.php', [
            'demoMode' => true,
            'error' => null,
        ]);

        self::assertStringContainsString('公開デモでは使用できません', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('backup_file', $html);
    }

    public function testDemoApplicationFormRequiresDemoAcknowledgement(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('participant/application-form.php', [
            'demoMode' => true,
            'demoAccepted' => false,
            'input' => null,
            'existing' => null,
            'fields' => [],
            'event' => ['privacy_purpose_text' => '利用目的'],
            'errors' => [],
            'csrfToken' => 'test-token',
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ]);

        self::assertStringContainsString('必ず架空の情報を使用してください', $html);
        self::assertStringContainsString('name="demo_accepted"', $html);
        self::assertStringContainsString('架空の情報だけを入力しています', $html);
    }

    public function testEscapedHomeTemplateOutput(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');

        $html = $renderer->render('home.php', [
            'assetUrl' => 'http://example.test/assets/app.css',
            'environment' => '<production>',
            'migrationCount' => 1,
            'adminUrl' => 'http://example.test/admin/',
        ]);

        self::assertStringContainsString('&lt;production&gt;', $html);
        self::assertStringNotContainsString('<production>', $html);
        self::assertStringContainsString('QRコードスタンプラリー', $html);
        self::assertStringNotContainsString('QRデジタルスタンプラリー', $html);
    }

    public function testApplicationSettingsShowsErrorSummaryWhenValidationFails(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');

        $html = $renderer->render('admin/applications/settings.php', [
            'event' => [
                'application_enabled' => 1,
                'application_deadline_at' => null,
                'privacy_purpose_text' => '',
                'ends_at' => '2026-08-13T12:00:00Z',
            ],
            'fields' => [],
            'errors' => ['fields' => '応募に使用する項目を1つ以上選択してください。'],
            'submitted' => [
                'application_enabled' => true,
                'application_deadline_at' => '',
                'privacy_purpose_text' => '',
                'fields' => [],
            ],
            'timezone' => 'Asia/Tokyo',
            'defaultPurpose' => '既定の利用目的',
            'csrfToken' => 'test-token',
        ]);

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('入力内容にエラーがあります。', $html);
        self::assertStringContainsString('応募に使用する項目を1つ以上選択してください。', $html);
        self::assertStringContainsString('イベント終了日時', $html);
        self::assertStringContainsString('2026年8月13日 21:00', $html);
        self::assertStringContainsString('締切を空欄にする', $html);
        self::assertStringContainsString("document.getElementById('application_deadline_at').value = ''", $html);
    }

    public function testSpotIndexShowsRegisteredSpotCountIncludingInactiveSpots(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('admin/spots/index.php', [
            'spots' => [
                [
                    'management_token' => 'active-spot',
                    'name' => '有効スポット',
                    'description' => '',
                    'is_active' => 1,
                    'acquisition_count' => 0,
                ],
                [
                    'management_token' => 'inactive-spot',
                    'name' => '停止中スポット',
                    'description' => '',
                    'is_active' => 0,
                    'acquisition_count' => 0,
                ],
            ],
            'event' => null,
            'canDelete' => false,
            'csrfToken' => 'test-token',
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ]);

        self::assertStringContainsString('登録スポット数：<strong>2件</strong>', $html);
    }

    public function testSpotIndexShowsZeroRegisteredSpots(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('admin/spots/index.php', [
            'spots' => [],
            'event' => null,
            'canDelete' => false,
            'csrfToken' => 'test-token',
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ]);

        self::assertStringContainsString('登録スポット数：<strong>0件</strong>', $html);
    }

    public function testBackupRestoreConfirmationShowsSpotCountFromManifest(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('admin/backup.php', [
            'error' => null,
            'event' => ['name' => 'テストイベント'],
            'summary' => ['participants' => 9, 'applications' => 4],
            'estimatedSize' => '1 MB',
            'csrfToken' => 'test-token',
            'manifest' => [
                'created_at' => '2026-09-11T12:00:00+00:00',
                'product_version' => '0.1.0-dev',
                'schema_version' => 1,
                'source_base_url' => 'https://example.test/',
                'table_counts' => [
                    'spots' => 3,
                    'participants' => 2,
                    'applications' => 1,
                ],
            ],
            'state' => ['status' => 'ready'],
            'sourceUrlDiffers' => false,
            'operations' => [],
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ]);

        self::assertStringContainsString('<dt>スポット</dt><dd>3件</dd>', $html);
        self::assertStringNotContainsString('<dt>スポット</dt><dd>9件</dd>', $html);
    }

    public function testInstallRestoreConfirmationShowsBackupCountsAndCriticalUrlWarning(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('install/index.php', [
            'diagnostics' => [
                'php' => true,
                'extensions' => [],
                'minimum_sqlite_version' => '3.26.0',
                'pdo_sqlite_version' => '3.26.0',
                'sqlite3_version' => '3.26.0',
                'recommended_extensions' => [],
                'optional_extensions' => [],
                'https' => true,
                'requirements_met' => true,
            ],
            'values' => [
                'base_url' => 'https://new.example/qr-sr/',
                'environment' => 'production',
                'timezone' => 'Asia/Tokyo',
                'private_path' => '/private/qr-sr',
                'admin_email' => '',
            ],
            'errors' => [],
            'csrf' => 'test-token',
            'productVersion' => '0.1.0-rc.2',
            'restoreState' => [
                'manifest' => [
                    'created_at' => '2026-09-11T12:00:00+00:00',
                    'product_version' => '0.1.0-dev',
                    'source_base_url' => 'https://old.example/',
                    'table_counts' => [
                        'spots' => 3,
                        'participants' => 3,
                        'applications' => 1,
                    ],
                ],
                'path_diagnostics' => [],
            ],
        ]);

        self::assertStringContainsString('インストールする製品バージョン：<strong>0.1.0-rc.2</strong>', $html);
        self::assertStringContainsString('<dt>バックアップの製品バージョン</dt><dd>0.1.0-dev</dd>', $html);
        self::assertStringContainsString('<dt>スポット</dt><dd>3件</dd>', $html);
        self::assertStringContainsString('<dt>参加者</dt><dd>3人</dd>', $html);
        self::assertStringContainsString('<dt>応募者</dt><dd>1人</dd>', $html);
        self::assertStringContainsString('class="error" role="alert"', $html);
        self::assertStringContainsString('すべて交換してください', $html);
    }

    public function testInstallRestoreCompletionUsesCriticalUrlWarning(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('install/restore-complete.php', [
            'sourceBaseUrl' => 'https://old.example/',
            'baseUrl' => 'https://new.example/qr-sr/',
            'loginUrl' => 'https://new.example/qr-sr/admin/login',
        ]);

        self::assertStringContainsString('class="error" role="alert"', $html);
        self::assertStringContainsString('ベースURLが変わりました', $html);
    }

    public function testParticipantCsrfFailureLinksBackToEventEntry(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->renderWithLayout('errors/participant-419.php', [
            'event' => ['name' => 'テストイベント'],
            'url' => static fn (string $path = ''): string => 'http://example.test/qr-sr/' . $path,
        ], 'participant/layout.php');

        self::assertStringContainsString('入力内容は送信されていません', $html);
        self::assertStringContainsString('http://example.test/qr-sr/', $html);
        self::assertStringContainsString('イベント入口へ戻る', $html);
        self::assertStringNotContainsString('管理者ログインへ戻る', $html);
    }

    public function testParticipantBoardShowsEventPeriodAndApplicationDeadline(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('participant/home.php', [
            'event' => [
                'name' => 'テストイベント',
                'description' => '説明',
                'pause_message' => '',
                'completion_message' => 'おめでとうございます。',
                'required_stamp_count' => 2,
            ],
            'participant' => ['nickname' => '参加者'],
            'spots' => [],
            'acquisitionCount' => 2,
            'completed' => true,
            'showApplicationLink' => true,
            'application' => null,
            'applicationEditable' => false,
            'applicationDeadlinePassed' => false,
            'eventStatus' => EventStatus::Active,
            'displayStartsAt' => '2026年9月10日 10:00',
            'displayEndsAt' => '2026年9月17日 18:00',
            'displayApplicationDeadline' => '2026年9月18日 20:00',
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ]);

        self::assertStringContainsString('開催期間', $html);
        self::assertStringContainsString('2026年9月10日 10:00 ～ 2026年9月17日 18:00', $html);
        self::assertStringContainsString('応募締切', $html);
        self::assertStringContainsString('2026年9月18日 20:00', $html);
        self::assertStringContainsString('応募フォームを開く', $html);
    }

    public function testParticipantBoardHidesApplicationDeadlineWithoutApplicationLink(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('participant/home.php', [
            'event' => [
                'name' => 'テストイベント',
                'description' => '説明',
                'pause_message' => '',
                'completion_message' => 'おめでとうございます。',
                'required_stamp_count' => 2,
            ],
            'participant' => ['nickname' => '参加者'],
            'spots' => [],
            'acquisitionCount' => 2,
            'completed' => true,
            'showApplicationLink' => false,
            'application' => null,
            'applicationEditable' => false,
            'applicationDeadlinePassed' => true,
            'eventStatus' => EventStatus::Ended,
            'displayStartsAt' => '2026年9月10日 10:00',
            'displayEndsAt' => '2026年9月17日 18:00',
            'displayApplicationDeadline' => '2026年9月18日 20:00',
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ]);

        self::assertStringContainsString('開催期間', $html);
        self::assertStringNotContainsString('応募締切', $html);
        self::assertStringNotContainsString('2026年9月18日 20:00', $html);
    }

    public function testParticipantBoardShowsSubmittedApplicationAsEditable(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('participant/home.php', $this->participantBoardData([
            'application' => ['application_number' => 'ABC<123'],
            'applicationEditable' => true,
            'applicationDeadlinePassed' => false,
        ]));

        self::assertStringContainsString('応募済みです', $html);
        self::assertStringContainsString('応募番号', $html);
        self::assertStringContainsString('ABC&lt;123', $html);
        self::assertStringNotContainsString('ABC<123', $html);
        self::assertStringContainsString('応募締切', $html);
        self::assertStringContainsString('応募内容を確認・編集する', $html);
        self::assertStringNotContainsString('応募フォームを開く', $html);
    }

    public function testParticipantBoardShowsSubmittedApplicationAsReadOnlyAfterDeadline(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('participant/home.php', $this->participantBoardData([
            'application' => ['application_number' => 'ABC123'],
            'applicationEditable' => false,
            'applicationDeadlinePassed' => true,
        ]));

        self::assertStringContainsString('応募済みです', $html);
        self::assertStringContainsString('ABC123', $html);
        self::assertStringContainsString('応募受付は終了しました。', $html);
        self::assertStringContainsString('応募内容を確認する', $html);
        self::assertStringNotContainsString('応募内容を確認・編集する', $html);
    }

    public function testParticipantBoardShowsSubmittedApplicationAsReadOnlyWhenApplicationsDisabled(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('participant/home.php', $this->participantBoardData([
            'application' => ['application_number' => 'ABC123'],
            'applicationEditable' => false,
            'applicationDeadlinePassed' => false,
        ]));

        self::assertStringContainsString('応募済みです', $html);
        self::assertStringContainsString('ABC123', $html);
        self::assertStringContainsString('応募内容を確認する', $html);
        self::assertStringNotContainsString('応募受付は終了しました。', $html);
        self::assertStringNotContainsString('応募締切', $html);
    }

    public function testApplicationUnavailableShowsSuppliedEditReason(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('participant/application-unavailable.php', [
            'event' => ['application_enabled' => false],
            'participant' => ['completed_at' => '2026-09-03T12:00:00Z'],
            'existing' => [
                'application_number' => 'ABC123',
                'name' => '参加者',
            ],
            'fields' => [[
                'is_enabled' => 1,
                'field_type' => 'name',
            ]],
            'editUnavailableMessage' => '現在、応募受付は停止されているため、内容は変更できません。',
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ]);

        self::assertStringContainsString('ABC123', $html);
        self::assertStringContainsString('現在、応募受付は停止されているため、内容は変更できません。', $html);
        self::assertStringNotContainsString('応募締切を過ぎているため', $html);
    }

    public function testApplicationCompleteShowsSuppliedReasonInsteadOfEditLink(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('participant/application-complete.php', [
            'application' => [
                'application_number' => 'ABC123',
                'name' => '参加者',
            ],
            'fields' => [[
                'is_enabled' => 1,
                'field_type' => 'name',
            ]],
            'editable' => false,
            'editUnavailableMessage' => '現在、応募受付は停止されているため、内容は変更できません。',
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ]);

        self::assertStringContainsString('現在、応募受付は停止されているため、内容は変更できません。', $html);
        self::assertStringNotContainsString('応募内容を編集', $html);
    }

    public function testCompletedSpotResultShowsApplicationAsPrimaryAction(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('participant/spot.php', [
            'spot' => ['name' => 'スポット1', 'description' => '説明'],
            'message' => 'スタンプを取得しました！ 達成おめでとうございます！',
            'kind' => 'success',
            'showApplicationLink' => true,
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ]);

        self::assertStringContainsString('href="https://example.test/application"', $html);
        self::assertStringContainsString('>応募フォームを開く</a>', $html);
        self::assertStringContainsString('class="button secondary" href="https://example.test/"', $html);
        self::assertStringContainsString('>スタンプ台紙を見る</a>', $html);
    }

    public function testSpotResultWithoutApplicationDoesNotShowApplicationLink(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('participant/spot.php', [
            'spot' => ['name' => 'スポット1', 'description' => '説明'],
            'message' => 'スタンプを取得しました！',
            'kind' => 'success',
            'showApplicationLink' => false,
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ]);

        self::assertStringNotContainsString('応募フォームを開く', $html);
        self::assertStringContainsString('class="button" href="https://example.test/"', $html);
        self::assertStringContainsString('>スタンプ台紙を見る</a>', $html);
    }

    public function testLoginShowsRestoreCompletionMessageAndKeepsItOnSubmit(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('auth/login.php', [
            'restored' => true,
            'csrfToken' => 'test-token',
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ]);

        self::assertStringContainsString('バックアップの復元が完了しました。', $html);
        self::assertStringContainsString('バックアップ作成時点の管理者パスワードでログインしてください。', $html);
        self::assertStringContainsString('action="https://example.test/admin/login?restored=1"', $html);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function participantBoardData(array $overrides): array
    {
        return array_merge([
            'event' => [
                'name' => 'テストイベント',
                'description' => '説明',
                'pause_message' => '',
                'completion_message' => 'おめでとうございます。',
                'required_stamp_count' => 2,
            ],
            'participant' => ['nickname' => '参加者'],
            'spots' => [],
            'acquisitionCount' => 2,
            'completed' => true,
            'showApplicationLink' => false,
            'application' => null,
            'applicationEditable' => false,
            'applicationDeadlinePassed' => false,
            'eventStatus' => EventStatus::Active,
            'displayStartsAt' => '2026年9月10日 10:00',
            'displayEndsAt' => '2026年9月17日 18:00',
            'displayApplicationDeadline' => '2026年9月18日 20:00',
            'url' => static fn (string $path = ''): string => 'https://example.test/' . $path,
        ], $overrides);
    }
}
