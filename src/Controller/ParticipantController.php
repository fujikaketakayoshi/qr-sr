<?php

declare(strict_types=1);

namespace QrRally\Controller;

use DateTimeImmutable;
use DateTimeZone;
use QrRally\Domain\EventStatus;
use QrRally\Http\Request;
use QrRally\Http\Response;
use QrRally\Repository\AuditLogRepository;
use QrRally\Repository\EventRepository;
use QrRally\Repository\ParticipantRepository;
use QrRally\Repository\SpotRepository;
use QrRally\Security\CsrfToken;
use QrRally\Security\ParticipantToken;
use QrRally\Support\UrlGenerator;
use QrRally\Support\ViewData;
use QrRally\View\TemplateRenderer;

final class ParticipantController
{
    private const COOKIE_NAME = 'qr_rally_participant';

    public function __construct(
        private readonly TemplateRenderer $templates,
        private readonly ViewData $views,
        private readonly UrlGenerator $urls,
        private readonly CsrfToken $csrf,
        private readonly EventRepository $events,
        private readonly SpotRepository $spots,
        private readonly ParticipantRepository $participants,
        private readonly ParticipantToken $tokens,
        private readonly AuditLogRepository $logs,
        private readonly string $appKey,
        private readonly bool $cookieSecure,
        private readonly string $cookiePath,
    ) {
    }

    public function home(Request $request): Response
    {
        $event = $this->events->find();
        if ($event === null) {
            return $this->view('participant/home.php', ['event' => null, 'participant' => null]);
        }
        $participant = $this->currentParticipant($request);
        if ($participant === null) {
            return $this->joinForm('', []);
        }

        return $this->board($event, $participant);
    }

    /** @param array<string, string> $errors */
    public function joinForm(string $spotToken = '', array $errors = [], string $nickname = '', int $status = 200): Response
    {
        if ($spotToken !== '' && $this->spots->findByPublicToken($spotToken) !== null) {
            $_SESSION['_participant_pending_spot'] = $spotToken;
        }

        return $this->view('participant/join.php', [
            'event' => $this->events->find(),
            'spotToken' => $spotToken,
            'nickname' => $nickname,
            'errors' => $errors,
        ], $status);
    }

    public function join(Request $request): Response
    {
        if (!$this->csrf->verify($request->input('_csrf'))) {
            return new Response($this->templates->render('errors/419.php'), 419);
        }
        $nickname = $request->input('nickname');
        $spotToken = $request->input('spot_token');
        if ($spotToken === '') {
            $pendingSpot = $_SESSION['_participant_pending_spot'] ?? '';
            $spotToken = is_string($pendingSpot) ? $pendingSpot : '';
        }
        $errors = [];
        if ($nickname === '' || mb_strlen($nickname) > 50) {
            $errors['nickname'] = 'ニックネームは1〜50文字で入力してください。';
        }
        if (!$request->boolean('notice_accepted')) {
            $errors['notice_accepted'] = '注意事項を確認し、同意してください。';
        }
        if ($spotToken !== '' && $this->spots->findByPublicToken($spotToken) === null) {
            $errors['spot_token'] = 'QRコードが無効です。';
        }
        if ($errors !== []) {
            return $this->joinForm($spotToken, $errors, $nickname, 422);
        }

        $token = $this->tokens->generate();
        $participantId = $this->participants->create($token, $nickname);
        $this->logs->record('participant.joined', 'participant', $participantId, 'success');
        $destination = $spotToken === '' ? '' : 'spot/' . $spotToken;
        unset($_SESSION['_participant_pending_spot']);

        return new Response('', 303, [
            'Location' => $this->urls->to($destination),
            'Set-Cookie' => $this->cookieHeader($token),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function spot(Request $request, string $token): Response
    {
        $spot = $this->spots->findByPublicToken($token);
        if ($spot === null) {
            return new Response($this->templates->render('errors/404.php'), 404);
        }
        $participant = $this->currentParticipant($request);
        if ($participant === null) {
            return $this->joinForm($token);
        }
        $event = $this->events->find();
        if ($event === null) {
            return $this->spotResult($event, $participant, $spot, 'イベントが設定されていないため取得できません。', 'error');
        }
        $status = $this->eventStatus($event);
        if ($status !== EventStatus::Active) {
            $message = $status === EventStatus::Paused && (string) $event['pause_message'] !== ''
                ? (string) $event['pause_message']
                : match ($status) {
                    EventStatus::Paused => 'イベントは現在一時停止中です。',
                    EventStatus::Upcoming => 'イベントはまだ始まっていません。',
                    EventStatus::Ended => 'イベントは終了しました。',
                    default => '',
                };
            $this->logs->record('stamp.rejected', 'participant', (int) $participant['id'], $status->value, [], 'spot', (int) $spot['id']);
            return $this->spotResult($event, $participant, $spot, $message, 'error');
        }
        if (!(bool) $spot['is_active']) {
            $this->logs->record('stamp.rejected', 'participant', (int) $participant['id'], 'spot_inactive', [], 'spot', (int) $spot['id']);
            return $this->spotResult($event, $participant, $spot, 'このスポットは現在停止中です。', 'error');
        }

        $ipHash = hash_hmac('sha256', $request->clientIp(), $this->appKey);
        $result = $this->participants->acquire((int) $participant['id'], (int) $spot['id'], $ipHash);
        $this->logs->record('stamp.' . $result, 'participant', (int) $participant['id'], $result, [], 'spot', (int) $spot['id']);
        $completed = $this->participants->markCompletedIfEligible((int) $participant['id'], (int) $event['required_stamp_count']);
        $message = $result === 'duplicate' ? 'このスポットは取得済みです。' : 'スタンプを取得しました！';
        if ($completed) {
            $message .= ' 達成おめでとうございます！';
        }

        return $this->spotResult($event, $participant, $spot, $message, $result === 'duplicate' ? 'notice' : 'success');
    }

    public function notices(): Response
    {
        return $this->view('participant/notices.php', ['event' => $this->events->find()]);
    }

    /** @param array<string, mixed> $event @param array<string, mixed> $participant */
    private function board(array $event, array $participant): Response
    {
        $completed = $this->participants->markCompletedIfEligible((int) $participant['id'], (int) $event['required_stamp_count']);
        return $this->view('participant/home.php', [
            'event' => $event,
            'participant' => $participant,
            'spots' => $this->participants->stampBoard((int) $participant['id']),
            'acquisitionCount' => $this->participants->acquisitionCount((int) $participant['id']),
            'completed' => $completed,
            'eventStatus' => $this->eventStatus($event),
        ]);
    }

    /** @param array<string, mixed>|null $event @param array<string, mixed> $participant @param array<string, mixed> $spot */
    private function spotResult(?array $event, array $participant, array $spot, string $message, string $kind): Response
    {
        return $this->view('participant/spot.php', compact('event', 'participant', 'spot', 'message', 'kind'));
    }

    /** @return array<string, mixed>|null */
    private function currentParticipant(Request $request): ?array
    {
        $token = $request->cookie(self::COOKIE_NAME);
        return $token === null ? null : $this->participants->findByToken($token);
    }

    /** @param array<string, mixed> $event */
    private function eventStatus(array $event): EventStatus
    {
        return EventStatus::calculate((bool) $event['is_paused'], new DateTimeImmutable((string) $event['starts_at']), new DateTimeImmutable((string) $event['ends_at']), new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    private function cookieHeader(string $token): string
    {
        return self::COOKIE_NAME . '=' . $token . '; Path=' . $this->cookiePath . '; Max-Age=31536000; HttpOnly; SameSite=Lax' . ($this->cookieSecure ? '; Secure' : '');
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, array $data, int $status = 200): Response
    {
        $data = array_merge($this->views->common(), $data);
        return new Response($this->templates->renderWithLayout($template, $data, 'participant/layout.php'), $status, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
