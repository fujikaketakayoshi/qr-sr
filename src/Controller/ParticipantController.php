<?php

declare(strict_types=1);

namespace QrRally\Controller;

use DateTimeImmutable;
use DateTimeZone;
use QrRally\Domain\ApplicationEligibility;
use QrRally\Domain\EventStatus;
use QrRally\Http\Request;
use QrRally\Http\Response;
use QrRally\Repository\AuditLogRepository;
use QrRally\Repository\ApplicationRepository;
use QrRally\Repository\EventRepository;
use QrRally\Repository\ParticipantRepository;
use QrRally\Repository\SpotRepository;
use QrRally\Security\CsrfToken;
use QrRally\Security\ParticipantToken;
use QrRally\Security\ParticipantCookie;
use QrRally\Security\TrafficMonitor;
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
        private readonly ApplicationRepository $applications,
        private readonly ParticipantToken $tokens,
        private readonly AuditLogRepository $logs,
        private readonly string $appKey,
        private readonly string $timezone,
        private readonly ParticipantCookie $participantCookie,
        private readonly TrafficMonitor $traffic,
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
            [$participant, $token] = $this->createParticipant();
            return $this->board($event, $participant)->withHeader('Set-Cookie', $this->participantCookie->header($token));
        }

        return $this->board($event, $participant);
    }

    public function join(Request $request): Response
    {
        if (!$this->csrf->verify($request->input('_csrf'))) {
            return $this->csrfFailure();
        }
        $event = $this->events->find();
        $participant = $this->currentParticipant($request);
        if ($event === null || $participant === null || $participant['nickname'] !== '') {
            return Response::redirect($this->urls->to());
        }
        $nickname = $request->input('nickname');
        if (mb_strlen($nickname) > 50) {
            return $this->board($event, $participant, ['nickname' => 'ニックネームは50文字以内で入力してください。'], $nickname, 422);
        }
        if ($nickname !== '') {
            $this->participants->registerNickname((int) $participant['id'], $nickname);
            $this->logs->record('participant.nickname_registered', 'participant', (int) $participant['id'], 'success');
        }
        return Response::redirect($this->urls->to())->withHeader('Cache-Control', 'private, no-store');
    }

    public function spot(Request $request, string $token): Response
    {
        $spot = $this->spots->findByPublicToken($token);
        if ($spot === null) {
            return new Response($this->templates->render('errors/404.php'), 404);
        }
        $participant = $this->currentParticipant($request);
        if ($participant === null) {
            if ($this->events->find() === null) {
                return $this->view('participant/home.php', ['event' => null, 'participant' => null]);
            }
            [$participant, $participantToken] = $this->createParticipant();
            return $this->acquireSpot($request, $spot, $participant)
                ->withHeader('Set-Cookie', $this->participantCookie->header($participantToken));
        }

        return $this->acquireSpot($request, $spot, $participant);
    }

    /** @param array<string, mixed> $spot @param array<string, mixed> $participant */
    private function acquireSpot(Request $request, array $spot, array $participant): Response
    {
        $warning = $this->traffic->recordSpotAccess((int) $participant['id'], (int) $spot['id'], $request->clientIp());
        if ($warning !== null) {
            $this->logs->record(
                'participant.rapid_spot_access',
                'participant',
                (int) $participant['id'],
                'warning',
                $warning,
            );
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
        $showApplicationLink = ApplicationEligibility::canApply($event, $completed);

        return $this->spotResult(
            $event,
            $participant,
            $spot,
            $message,
            $result === 'duplicate' ? 'notice' : 'success',
            $showApplicationLink,
        );
    }

    public function notices(): Response
    {
        return $this->view('participant/notices.php', ['event' => $this->events->find()]);
    }

    /** @param array<string, mixed> $event @param array<string, mixed> $participant @param array<string, string> $errors */
    private function board(array $event, array $participant, array $errors = [], ?string $nickname = null, int $status = 200): Response
    {
        $completed = $this->participants->markCompletedIfEligible((int) $participant['id'], (int) $event['required_stamp_count']);
        $application = $this->applications->findForParticipant((int) $participant['id']);
        $applicationDeadlinePassed = ApplicationEligibility::deadlinePassed($event);
        return $this->view('participant/home.php', [
            'event' => $event,
            'participant' => $participant,
            'errors' => $errors,
            'nickname' => $nickname ?? (string) $participant['nickname'],
            'spots' => $this->participants->stampBoard((int) $participant['id']),
            'acquisitionCount' => $this->participants->acquisitionCount((int) $participant['id']),
            'completed' => $completed,
            'showApplicationLink' => ApplicationEligibility::canApply($event, $completed),
            'application' => $application,
            'applicationEditable' => $application !== null && (bool) $event['application_enabled'] && !$applicationDeadlinePassed,
            'applicationDeadlinePassed' => $applicationDeadlinePassed,
            'eventStatus' => $this->eventStatus($event),
            'displayStartsAt' => $this->displayDate((string) $event['starts_at']),
            'displayEndsAt' => $this->displayDate((string) $event['ends_at']),
            'displayApplicationDeadline' => $this->displayDate((string) ($event['application_deadline_at'] ?: $event['ends_at'])),
        ], $status);
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function createParticipant(): array
    {
        $token = $this->tokens->generate();
        $id = $this->participants->create($token, '');
        $this->logs->record('participant.joined', 'participant', $id, 'success');
        $participant = $this->participants->findByToken($token);
        if ($participant === null) {
            throw new \RuntimeException('参加情報を作成できませんでした。');
        }
        return [$participant, $token];
    }

    /** @param array<string, mixed>|null $event @param array<string, mixed> $participant @param array<string, mixed> $spot */
    private function spotResult(
        ?array $event,
        array $participant,
        array $spot,
        string $message,
        string $kind,
        bool $showApplicationLink = false,
    ): Response
    {
        return $this->view('participant/spot.php', compact(
            'event',
            'participant',
            'spot',
            'message',
            'kind',
            'showApplicationLink',
        ));
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

    private function displayDate(string $date): string
    {
        return (new DateTimeImmutable($date))
            ->setTimezone(new DateTimeZone($this->timezone))
            ->format('Y年n月j日 H:i');
    }

    private function csrfFailure(): Response
    {
        return $this->view('errors/participant-419.php', ['event' => $this->events->find()], 419);
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
