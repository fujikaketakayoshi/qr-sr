<?php

declare(strict_types=1);

namespace QrRally\Domain;

use DateTimeImmutable;
use DateTimeZone;

final class EventValidator
{
    /** @return array<string, string> */
    public function validate(EventInput $input, string $timezone, ?int $registeredSpotCount = null): array
    {
        $errors = [];
        if ($input->name === '' || mb_strlen($input->name) > 100) {
            $errors['name'] = 'イベント名は1〜100文字で入力してください。';
        }
        foreach ([
            'description' => [$input->description, 2000, '説明'],
            'notice_text' => [$input->noticeText, 1000, '注意事項'],
            'pause_message' => [$input->pauseMessage, 1000, '一時停止中の案内'],
            'completion_message' => [$input->completionMessage, 1000, '達成メッセージ'],
        ] as $key => [$value, $limit, $label]) {
            if (mb_strlen($value) > $limit) {
                $errors[$key] = "{$label}は{$limit}文字以内で入力してください。";
            }
        }

        $startsAt = $this->parseLocal($input->startsAt, $timezone);
        $endsAt = $this->parseLocal($input->endsAt, $timezone);
        if ($startsAt === null) {
            $errors['starts_at'] = '開始日時を入力してください。';
        }
        if ($endsAt === null) {
            $errors['ends_at'] = '終了日時を入力してください。';
        }
        if ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt) {
            $errors['ends_at'] = '終了日時は開始日時より後にしてください。';
        }
        if ($input->requiredStampCount < 1 || $input->requiredStampCount > 20) {
            $errors['required_stamp_count'] = '達成数は1〜20で入力してください。';
        } elseif ($registeredSpotCount !== null && $registeredSpotCount > 0
            && $input->requiredStampCount > $registeredSpotCount) {
            $errors['required_stamp_count'] = "達成数は登録済みスポット数（{$registeredSpotCount}）以下にしてください。";
        }
        if ($input->isPaused && $input->pauseMessage === '') {
            $errors['pause_message'] = '一時停止中の案内を入力してください。';
        }

        return $errors;
    }

    public function toUtc(string $value, string $timezone): string
    {
        return $this->parseLocal($value, $timezone)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function parseLocal(string $value, string $timezone): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone($timezone));
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ? $date
            : null;
    }
}
