<?php

declare(strict_types=1);

namespace QrRally\Repository;

use PDO;
use QrRally\Domain\ApplicationInput;

final class ApplicationRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return list<array<string, mixed>> */
    public function fields(): array
    {
        return $this->database->query('SELECT * FROM application_fields ORDER BY display_order, id')->fetchAll();
    }

    /** @param array<string, array{enabled: bool, required: bool}> $fields */
    public function saveSettings(bool $enabled, ?string $deadlineUtc, string $purpose, array $fields): void
    {
        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare("UPDATE events SET application_enabled=:enabled, application_deadline_at=:deadline, privacy_purpose_text=:purpose, updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id=1");
            $statement->execute(['enabled' => $enabled ? 1 : 0, 'deadline' => $deadlineUtc, 'purpose' => $purpose]);
            $update = $this->database->prepare('UPDATE application_fields SET is_enabled=:enabled, is_required=:required WHERE field_type=:type');
            foreach ($fields as $type => $values) {
                $update->execute(['type' => $type, 'enabled' => $values['enabled'] ? 1 : 0, 'required' => $values['required'] ? 1 : 0]);
            }
            $this->database->commit();
        } catch (\Throwable $error) {
            $this->database->rollBack();
            throw $error;
        }
    }

    /** @return array<string, mixed>|null */
    public function findForParticipant(int $participantId): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM applications WHERE participant_id=:id');
        $statement->execute(['id' => $participantId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param list<array<string, mixed>> $fields */
    public function save(int $participantId, ApplicationInput $input, array $fields): array
    {
        $existing = $this->findForParticipant($participantId);
        $values = ['name' => null, 'email' => null, 'address' => null, 'phone' => null];
        foreach ($fields as $field) {
            $type = (string) $field['field_type'];
            if ((bool) $field['is_enabled']) {
                $values[$type] = $input->values[$type] === '' ? null : $input->values[$type];
            }
        }
        $number = $existing['application_number'] ?? strtoupper(bin2hex(random_bytes(6)));
        $statement = $this->database->prepare(
            "INSERT INTO applications(participant_id,application_number,name,email,address,phone,privacy_accepted_at,submitted_at,updated_at) VALUES(:participant_id,:number,:name,:email,:address,:phone,strftime('%Y-%m-%dT%H:%M:%fZ','now'),strftime('%Y-%m-%dT%H:%M:%fZ','now'),strftime('%Y-%m-%dT%H:%M:%fZ','now')) ON CONFLICT(participant_id) DO UPDATE SET name=excluded.name,email=excluded.email,address=excluded.address,phone=excluded.phone,privacy_accepted_at=excluded.privacy_accepted_at,updated_at=excluded.updated_at",
        );
        $statement->execute(array_merge($values, ['participant_id' => $participantId, 'number' => $number]));
        return $this->findForParticipant($participantId) ?? [];
    }

    /** @return array{participants:int,acquisitions:int,completed:int,applications:int} */
    public function summary(): array
    {
        return [
            'participants' => (int) $this->database->query('SELECT COUNT(*) FROM participants')->fetchColumn(),
            'acquisitions' => (int) $this->database->query('SELECT COUNT(*) FROM stamp_acquisitions')->fetchColumn(),
            'completed' => (int) $this->database->query('SELECT COUNT(*) FROM participants WHERE completed_at IS NOT NULL')->fetchColumn(),
            'applications' => (int) $this->database->query('SELECT COUNT(*) FROM applications')->fetchColumn(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function spotSummary(): array
    {
        return $this->database->query('SELECT spots.name,spots.display_order,COUNT(stamp_acquisitions.id) acquisition_count FROM spots LEFT JOIN stamp_acquisitions ON stamp_acquisitions.spot_id=spots.id GROUP BY spots.id ORDER BY spots.display_order,spots.id')->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function exportRows(): array
    {
        return $this->database->query('SELECT p.nickname,p.first_seen_at,p.last_seen_at,p.completed_at,COUNT(sa.id) stamp_count,a.application_number,a.name,a.email,a.address,a.phone,a.submitted_at,a.updated_at FROM participants p LEFT JOIN stamp_acquisitions sa ON sa.participant_id=p.id LEFT JOIN applications a ON a.participant_id=p.id GROUP BY p.id ORDER BY p.id')->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function exportApplicationRows(): array
    {
        return $this->database->query('SELECT a.application_number,p.nickname,COUNT(sa.id) stamp_count,p.completed_at,a.name,a.email,a.address,a.phone,a.submitted_at,a.updated_at FROM applications a INNER JOIN participants p ON p.id=a.participant_id LEFT JOIN stamp_acquisitions sa ON sa.participant_id=p.id GROUP BY a.id ORDER BY a.id')->fetchAll();
    }
}
