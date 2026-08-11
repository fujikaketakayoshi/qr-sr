<?php

declare(strict_types=1);

namespace QrRally\Repository;

use PDO;
use QrRally\Domain\EventInput;

final class EventRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(): ?array
    {
        $event = $this->database->query('SELECT * FROM events WHERE id = 1')->fetch();

        return is_array($event) ? $event : null;
    }

    public function save(EventInput $input, string $startsAtUtc, string $endsAtUtc): bool
    {
        $exists = $this->find() !== null;
        $statement = $this->database->prepare(
            'INSERT INTO events '
            . '(id, name, description, notice_text, starts_at, ends_at, is_paused, pause_message, '
            . 'required_stamp_count, completion_message, application_enabled, privacy_purpose_text, created_at, updated_at) '
            . "VALUES (1, :name, :description, :notice_text, :starts_at, :ends_at, :is_paused, :pause_message, "
            . ":required_stamp_count, :completion_message, 0, '', strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now')) "
            . 'ON CONFLICT(id) DO UPDATE SET '
            . 'name = excluded.name, description = excluded.description, notice_text = excluded.notice_text, '
            . 'starts_at = excluded.starts_at, ends_at = excluded.ends_at, is_paused = excluded.is_paused, '
            . 'pause_message = excluded.pause_message, required_stamp_count = excluded.required_stamp_count, '
            . "completion_message = excluded.completion_message, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')",
        );
        $statement->execute([
            'name' => $input->name,
            'description' => $input->description,
            'notice_text' => $input->noticeText,
            'starts_at' => $startsAtUtc,
            'ends_at' => $endsAtUtc,
            'is_paused' => $input->isPaused ? 1 : 0,
            'pause_message' => $input->pauseMessage,
            'required_stamp_count' => $input->requiredStampCount,
            'completion_message' => $input->completionMessage,
        ]);

        return !$exists;
    }
}
