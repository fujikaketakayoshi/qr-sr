<?php

declare(strict_types=1);

namespace QrRally\Controller;

use DateTimeImmutable;
use DateTimeZone;
use QrRally\Auth\AdminAuth;
use QrRally\Auth\PasswordResetter;
use QrRally\Domain\EventInput;
use QrRally\Domain\EventStatus;
use QrRally\Domain\EventValidator;
use QrRally\Http\Request;
use QrRally\Http\Response;
use QrRally\Repository\AuditLogRepository;
use QrRally\Repository\EventRepository;
use QrRally\Repository\SpotRepository;
use QrRally\Repository\ApplicationRepository;
use QrRally\Security\CsrfToken;
use QrRally\Session\Flash;
use QrRally\Support\UrlGenerator;
use QrRally\Support\ViewData;
use QrRally\View\TemplateRenderer;

final class AdminController
{
    public function __construct(
        private readonly TemplateRenderer $templates,
        private readonly ViewData $views,
        private readonly UrlGenerator $urls,
        private readonly CsrfToken $csrf,
        private readonly Flash $flash,
        private readonly AdminAuth $auth,
        private readonly PasswordResetter $passwordResetter,
        private readonly EventRepository $events,
        private readonly SpotRepository $spots,
        private readonly ApplicationRepository $applications,
        private readonly EventValidator $eventValidator,
        private readonly AuditLogRepository $logs,
        private readonly string $timezone,
    ) {
    }

    public function loginForm(): Response
    {
        if ($this->auth->id() !== null) {
            return Response::redirect($this->urls->to('admin/'));
        }

        return $this->view('auth/login.php', ['title' => '管理者ログイン'], false);
    }

    public function login(Request $request): Response
    {
        if (!$this->csrf->verify($request->input('_csrf'))) {
            return $this->csrfFailure();
        }

        $result = $this->auth->attempt(
            $request->input('email'),
            $request->input('password'),
            $request->clientIp(),
        );
        if (!$result['success']) {
            return $this->view('auth/login.php', [
                'title' => '管理者ログイン',
                'error' => $result['message'],
                'email' => $request->input('email'),
            ], false, 422);
        }

        $this->flash->set('success', $result['message']);
        return Response::redirect($this->urls->to('admin/'));
    }

    public function logout(Request $request): Response
    {
        if (!$this->csrf->verify($request->input('_csrf'))) {
            return $this->csrfFailure();
        }
        $this->auth->logout();

        return Response::redirect($this->urls->to('admin/login'));
    }

    public function resetForm(): Response
    {
        return $this->view('auth/reset.php', ['title' => 'パスワード再設定'], false);
    }

    public function reset(Request $request): Response
    {
        if (!$this->csrf->verify($request->input('_csrf'))) {
            return $this->csrfFailure();
        }

        if ($request->input('password') !== $request->input('password_confirmation')) {
            return $this->view('auth/reset.php', [
                'title' => 'パスワード再設定',
                'error' => '新しいパスワードが確認入力と一致しません。',
                'email' => $request->input('email'),
            ], false, 422);
        }

        $result = $this->passwordResetter->reset(
            $request->input('email'),
            $request->input('recovery_key'),
            $request->input('password'),
        );
        if (!$result['success']) {
            return $this->view('auth/reset.php', [
                'title' => 'パスワード再設定',
                'error' => $result['message'],
                'email' => $request->input('email'),
            ], false, 422);
        }

        $this->csrf->rotate();
        return $this->view('auth/recovery-key.php', [
            'title' => '新しい復旧キー',
            'recoveryKey' => $result['recovery_key'],
            'message' => $result['message'],
        ], false);
    }

    public function dashboard(): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }

        $event = $this->events->find();
        return $this->view('admin/dashboard.php', [
            'title' => 'ダッシュボード',
            'event' => $event,
            'status' => $event === null ? null : $this->eventStatus($event),
            'displayStartsAt' => $event === null ? null : $this->displayDate((string) $event['starts_at']),
            'displayEndsAt' => $event === null ? null : $this->displayDate((string) $event['ends_at']),
            'summary' => $this->applications->summary(),
        ]);
    }

    public function eventForm(array $errors = [], ?EventInput $submitted = null, int $status = 200): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }

        $event = $this->events->find();
        $values = $submitted === null
            ? $this->eventValues($event)
            : $this->inputValues($submitted);

        return $this->view('admin/event.php', [
            'title' => 'イベント設定',
            'errors' => $errors,
            'values' => $values,
            'eventExists' => $event !== null,
            'applicationDeadlineNote' => $event !== null && $event['application_deadline_at'] !== null
                ? '設定済みの応募締切：' . $this->displayDate((string) $event['application_deadline_at'])
                : '応募締切はイベント終了日時と同じになります。',
        ], true, $status);
    }

    public function saveEvent(Request $request): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }
        if (!$this->csrf->verify($request->input('_csrf'))) {
            return $this->csrfFailure();
        }

        $input = new EventInput(
            $request->input('name'),
            $request->input('description'),
            $request->input('notice_text'),
            $request->input('starts_at'),
            $request->input('ends_at'),
            $request->boolean('is_paused'),
            $request->input('pause_message'),
            filter_var($request->input('required_stamp_count'), FILTER_VALIDATE_INT) ?: 0,
            $request->input('completion_message'),
        );
        $before = $this->events->find();
        $errors = $this->eventValidator->validate(
            $input,
            $this->timezone,
            $this->spots->count(),
            $before === null ? null : $before['application_deadline_at'],
        );
        if ($errors !== []) {
            return $this->eventForm($errors, $input, 422);
        }

        $created = $this->events->save(
            $input,
            $this->eventValidator->toUtc($input->startsAt, $this->timezone),
            $this->eventValidator->toUtc($input->endsAt, $this->timezone),
        );
        $eventType = $created ? 'event.created' : 'event.updated';
        $context = [];
        if ($before !== null && (bool) $before['is_paused'] !== $input->isPaused) {
            $context['pause_changed_to'] = $input->isPaused;
        }
        $this->logs->record($eventType, 'admin', $this->auth->id(), 'success', $context, 'event', 1);
        $this->flash->set('success', 'イベント設定を保存しました。');

        return Response::redirect($this->urls->to('admin/event'));
    }

    public function logs(): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }

        return $this->view('admin/logs.php', [
            'title' => '操作ログ',
            'logs' => $this->logs->recent(),
        ]);
    }

    private function requireAdmin(): ?Response
    {
        return $this->auth->id() === null
            ? Response::redirect($this->urls->to('admin/login'), 302)
            : null;
    }

    private function csrfFailure(): Response
    {
        return $this->view('errors/419.php', ['title' => '操作を続けられません'], false, 419);
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, array $data, bool $adminLayout = true, int $status = 200): Response
    {
        $data = array_merge($this->views->common(), $data);
        $html = $adminLayout
            ? $this->templates->renderWithLayout($template, $data)
            : $this->templates->renderWithLayout($template, $data, 'auth/layout.php');

        return new Response($html, $status);
    }

    /** @param array<string, mixed> $event */
    private function eventStatus(array $event): EventStatus
    {
        return EventStatus::calculate(
            (bool) $event['is_paused'],
            new DateTimeImmutable((string) $event['starts_at']),
            new DateTimeImmutable((string) $event['ends_at']),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    /** @param array<string, mixed>|null $event
     *  @return array<string, mixed>
     */
    private function eventValues(?array $event): array
    {
        if ($event === null) {
            $now = new DateTimeImmutable('now', new DateTimeZone($this->timezone));
            return [
                'name' => '', 'description' => '', 'notice_text' => '',
                'starts_at' => $now->modify('+1 day')->setTime(10, 0)->format('Y-m-d\TH:i'),
                'ends_at' => $now->modify('+2 days')->setTime(17, 0)->format('Y-m-d\TH:i'),
                'is_paused' => false, 'pause_message' => '',
                'required_stamp_count' => 1, 'completion_message' => '',
            ];
        }

        $zone = new DateTimeZone($this->timezone);
        return [
            'name' => $event['name'], 'description' => $event['description'],
            'notice_text' => $event['notice_text'],
            'starts_at' => (new DateTimeImmutable((string) $event['starts_at']))->setTimezone($zone)->format('Y-m-d\TH:i'),
            'ends_at' => (new DateTimeImmutable((string) $event['ends_at']))->setTimezone($zone)->format('Y-m-d\TH:i'),
            'is_paused' => (bool) $event['is_paused'], 'pause_message' => $event['pause_message'],
            'required_stamp_count' => $event['required_stamp_count'], 'completion_message' => $event['completion_message'],
        ];
    }

    /** @return array<string, mixed> */
    private function inputValues(EventInput $input): array
    {
        return [
            'name' => $input->name, 'description' => $input->description,
            'notice_text' => $input->noticeText, 'starts_at' => $input->startsAt,
            'ends_at' => $input->endsAt, 'is_paused' => $input->isPaused,
            'pause_message' => $input->pauseMessage,
            'required_stamp_count' => $input->requiredStampCount,
            'completion_message' => $input->completionMessage,
        ];
    }

    private function displayDate(string $utc): string
    {
        return (new DateTimeImmutable($utc))
            ->setTimezone(new DateTimeZone($this->timezone))
            ->format('Y年n月j日 H:i');
    }
}
