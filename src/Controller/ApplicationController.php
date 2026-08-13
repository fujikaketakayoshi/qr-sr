<?php

declare(strict_types=1);

namespace QrRally\Controller;

use DateTimeImmutable;
use DateTimeZone;
use QrRally\Auth\AdminAuth;
use QrRally\Domain\ApplicationInput;
use QrRally\Domain\ApplicationValidator;
use QrRally\Http\Request;
use QrRally\Http\Response;
use QrRally\Repository\ApplicationRepository;
use QrRally\Repository\AuditLogRepository;
use QrRally\Repository\EventRepository;
use QrRally\Repository\ParticipantRepository;
use QrRally\Security\CsrfToken;
use QrRally\Security\ParticipantToken;
use QrRally\Session\Flash;
use QrRally\Support\UrlGenerator;
use QrRally\Support\ViewData;
use QrRally\Support\CsvValue;
use QrRally\View\TemplateRenderer;

final class ApplicationController
{
    private const COOKIE_NAME = 'qr_rally_participant';

    public function __construct(
        private readonly TemplateRenderer $templates,
        private readonly ViewData $views,
        private readonly UrlGenerator $urls,
        private readonly CsrfToken $csrf,
        private readonly Flash $flash,
        private readonly AdminAuth $auth,
        private readonly EventRepository $events,
        private readonly ParticipantRepository $participants,
        private readonly ApplicationRepository $applications,
        private readonly ApplicationValidator $validator,
        private readonly ParticipantToken $tokens,
        private readonly AuditLogRepository $logs,
        private readonly CsvValue $csvValues,
        private readonly string $timezone,
    ) {
    }

    public function settings(array $errors = [], array $submitted = [], int $status = 200): Response
    {
        if (($response = $this->requireAdmin()) !== null) return $response;
        $event = $this->events->find();
        $fields = $this->applications->fields();
        $timezone = $this->timezone;
        return $this->adminView('admin/applications/settings.php', compact('event', 'fields', 'errors', 'submitted', 'timezone'), $status);
    }

    public function saveSettings(Request $request): Response
    {
        if (($response = $this->guardAdminPost($request)) !== null) return $response;
        $event = $this->events->find();
        if ($event === null) {
            $this->flash->set('error', '先にイベント設定を作成してください。');
            return Response::redirect($this->urls->to('admin/applications/settings'));
        }
        $enabled = $request->boolean('application_enabled');
        $deadline = $request->input('application_deadline_at');
        $purpose = $request->input('privacy_purpose_text');
        $errors = [];
        $deadlineDate = $deadline === '' ? null : $this->parseLocal($deadline);
        if ($deadline !== '' && $deadlineDate === null) $errors['application_deadline_at'] = '応募締切日時を確認してください。';
        $endsAt = new DateTimeImmutable((string) $event['ends_at']);
        if ($deadlineDate !== null && $deadlineDate->setTimezone(new DateTimeZone('UTC')) < $endsAt) $errors['application_deadline_at'] = '応募締切はイベント終了日時以降にしてください。';
        if ($enabled && ($purpose === '' || mb_strlen($purpose) > 2000)) $errors['privacy_purpose_text'] = '個人情報の利用目的を1〜2000文字で入力してください。';
        $fields = [];
        foreach (['name', 'email', 'address', 'phone'] as $type) {
            $use = $request->input("field_{$type}");
            $fields[$type] = ['enabled' => in_array($use, ['optional', 'required'], true), 'required' => $use === 'required'];
        }
        if ($enabled && !array_filter($fields, fn ($field) => $field['enabled'])) $errors['fields'] = '応募に使用する項目を1つ以上選択してください。';
        $submitted = ['application_enabled' => $enabled, 'application_deadline_at' => $deadline, 'privacy_purpose_text' => $purpose, 'fields' => $fields];
        if ($errors !== []) return $this->settings($errors, $submitted, 422);
        $deadlineUtc = $deadlineDate?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $this->applications->saveSettings($enabled, $deadlineUtc, $purpose, $fields);
        $this->logs->record('application.settings_updated', 'admin', $this->auth->id(), 'success');
        $this->flash->set('success', '応募設定を保存しました。');
        return Response::redirect($this->urls->to('admin/applications/settings'));
    }

    public function form(Request $request, array $errors = [], ?ApplicationInput $input = null, int $status = 200): Response
    {
        [$event, $participant, $blocked] = $this->participantContext($request);
        if ($blocked !== null) return $blocked;
        $existing = $this->applications->findForParticipant((int) $participant['id']);
        $fields = $this->applications->fields();
        if (!$this->canApply($event, $participant)) return $this->participantView('participant/application-unavailable.php', compact('event', 'participant', 'existing', 'fields'), 403);
        if ($input === null) {
            $confirmed = $_SESSION['_application_confirmed'] ?? null;
            if (is_array($confirmed) && ($confirmed['participant_id'] ?? null) === (int) $participant['id']) {
                $input = new ApplicationInput((array) $confirmed['values'], true);
            }
        }
        return $this->participantView('participant/application-form.php', compact('event', 'participant', 'fields', 'existing', 'input', 'errors'));
    }

    public function confirm(Request $request): Response
    {
        if (!$this->csrf->verify($request->input('_csrf'))) return new Response($this->templates->render('errors/419.php'), 419);
        [$event, $participant, $blocked] = $this->participantContext($request);
        if ($blocked !== null) return $blocked;
        if (!$this->canApply($event, $participant)) return $this->participantView('participant/application-unavailable.php', compact('event', 'participant'), 403);
        $input = $this->applicationInput($request);
        $fields = $this->applications->fields();
        $existing = $this->applications->findForParticipant((int) $participant['id']);
        $errors = $this->validator->validate($input, $fields, $existing);
        if ($errors !== []) return $this->form($request, $errors, $input, 422);
        $_SESSION['_application_confirmed'] = ['participant_id' => (int) $participant['id'], 'values' => $input->values, 'privacy_accepted' => true];
        return $this->participantView('participant/application-confirm.php', compact('event', 'participant', 'fields', 'input'));
    }

    public function submit(Request $request): Response
    {
        if (!$this->csrf->verify($request->input('_csrf'))) return new Response($this->templates->render('errors/419.php'), 419);
        [$event, $participant, $blocked] = $this->participantContext($request);
        if ($blocked !== null) return $blocked;
        $confirmed = $_SESSION['_application_confirmed'] ?? null;
        if (!$this->canApply($event, $participant) || !is_array($confirmed) || ($confirmed['participant_id'] ?? null) !== (int) $participant['id']) return Response::redirect($this->urls->to('application'));
        $input = new ApplicationInput((array) $confirmed['values'], true);
        $fields = $this->applications->fields();
        $existing = $this->applications->findForParticipant((int) $participant['id']);
        if ($this->validator->validate($input, $fields, $existing) !== []) return Response::redirect($this->urls->to('application'));
        $application = $this->applications->save((int) $participant['id'], $input, $fields);
        unset($_SESSION['_application_confirmed']);
        $this->logs->record($existing === null ? 'application.submitted' : 'application.updated', 'participant', (int) $participant['id'], 'success', [], 'application', (int) $application['id']);
        return Response::redirect($this->urls->to('application/complete'));
    }

    public function complete(Request $request): Response
    {
        [$event, $participant, $blocked] = $this->participantContext($request);
        if ($blocked !== null) return $blocked;
        $application = $this->applications->findForParticipant((int) $participant['id']);
        if ($application === null) return Response::redirect($this->urls->to('application'));
        return $this->participantView('participant/application-complete.php', ['event' => $event, 'participant' => $participant, 'application' => $application, 'fields' => $this->applications->fields(), 'editable' => !$this->deadlinePassed($event)]);
    }

    public function report(): Response
    {
        if (($response = $this->requireAdmin()) !== null) return $response;
        return $this->adminView('admin/applications/report.php', ['summary' => $this->applications->summary(), 'spots' => $this->applications->spotSummary()]);
    }

    public function csv(): Response
    {
        if (($response = $this->requireAdmin()) !== null) return $response;
        $fields = array_filter($this->applications->fields(), fn ($field) => (bool) $field['is_enabled']);
        $columns = ['nickname' => 'ニックネーム', 'stamp_count' => '取得数', 'first_seen_at' => '参加日時', 'last_seen_at' => '最終アクセス日時', 'completed_at' => '達成日時', 'application_number' => '応募番号'];
        foreach ($fields as $field) $columns[(string) $field['field_type']] = $this->validator->label((string) $field['field_type']);
        $columns += ['submitted_at' => '応募日時', 'updated_at' => '応募更新日時'];
        $stream = fopen('php://temp', 'r+'); fwrite($stream, "\xEF\xBB\xBF"); fputcsv($stream, array_values($columns));
        foreach ($this->applications->exportRows() as $row) {
            $line = [];
            foreach (array_keys($columns) as $key) $line[] = $this->csvValues->safe((string) ($row[$key] ?? ''));
            fputcsv($stream, $line);
        }
        rewind($stream); $body = stream_get_contents($stream); fclose($stream);
        $this->logs->record('participants.csv_exported', 'admin', $this->auth->id(), 'success');
        return new Response((string) $body, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="qr-rally-participants.csv"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function applicationInput(Request $request): ApplicationInput { return new ApplicationInput(['name'=>$request->input('name'),'email'=>$request->input('email'),'email_confirmation'=>$request->input('email_confirmation'),'address'=>$request->input('address'),'phone'=>$request->input('phone')], $request->boolean('privacy_accepted')); }
    private function canApply(array $event, array $participant): bool { return (bool) $event['application_enabled'] && $participant['completed_at'] !== null && !$this->deadlinePassed($event); }
    private function deadlinePassed(array $event): bool { $deadline = $event['application_deadline_at'] ?: $event['ends_at']; return new DateTimeImmutable('now', new DateTimeZone('UTC')) > new DateTimeImmutable((string) $deadline); }
    private function parseLocal(string $value): ?DateTimeImmutable { $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone($this->timezone)); $e=DateTimeImmutable::getLastErrors(); return $date!==false&&($e===false||($e['warning_count']===0&&$e['error_count']===0))?$date:null; }
    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:?Response} */
    private function participantContext(Request $request): array { $event=$this->events->find(); $token=$request->cookie(self::COOKIE_NAME); $participant=$token===null?null:$this->participants->findByToken($token); if($event===null||$participant===null)return [$event??[],$participant??[],Response::redirect($this->urls->to(''))]; return [$event,$participant,null]; }
    private function requireAdmin(): ?Response { return $this->auth->id()===null?Response::redirect($this->urls->to('admin/login'),302):null; }
    private function guardAdminPost(Request $request): ?Response { if(($r=$this->requireAdmin())!==null)return $r; return !$this->csrf->verify($request->input('_csrf'))?new Response($this->templates->render('errors/419.php'),419):null; }
    private function adminView(string $template,array $data,int $status=200):Response{return new Response($this->templates->renderWithLayout($template,array_merge($this->views->common(),$data)),$status);}
    private function participantView(string $template,array $data,int $status=200):Response{return new Response($this->templates->renderWithLayout($template,array_merge($this->views->common(),$data),'participant/layout.php'),$status,['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'private, no-store']);}
}
