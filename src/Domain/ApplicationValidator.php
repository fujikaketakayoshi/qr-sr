<?php

declare(strict_types=1);

namespace QrRally\Domain;

final class ApplicationValidator
{
    /** @param list<array<string, mixed>> $fields @return array<string, string> */
    public function validate(ApplicationInput $input, array $fields, ?array $existing = null): array
    {
        $errors = [];
        $limits = ['name' => 100, 'email' => 254, 'address' => 500, 'phone' => 50];
        foreach ($fields as $field) {
            if (!(bool) $field['is_enabled']) {
                continue;
            }
            $type = (string) $field['field_type'];
            $value = $input->values[$type] ?? '';
            $newlyRequiredForExisting = $existing !== null && ($existing[$type] ?? null) === null;
            if ((bool) $field['is_required'] && $value === '' && !$newlyRequiredForExisting) {
                $errors[$type] = $this->label($type) . 'を入力してください。';
            } elseif (mb_strlen($value) > $limits[$type]) {
                $errors[$type] = $this->label($type) . "は{$limits[$type]}文字以内で入力してください。";
            }
        }
        $email = $input->values['email'] ?? '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'メールアドレスの形式を確認してください。';
        }
        if ($email !== '' && $email !== ($input->values['email_confirmation'] ?? '')) {
            $errors['email_confirmation'] = 'メールアドレスが確認入力と一致しません。';
        }
        if (!$input->privacyAccepted) {
            $errors['privacy_accepted'] = '個人情報の取り扱いへの同意が必要です。';
        }

        return $errors;
    }

    public function label(string $type): string
    {
        return ['name' => '氏名', 'email' => 'メールアドレス', 'address' => '住所', 'phone' => '電話番号'][$type] ?? $type;
    }
}
