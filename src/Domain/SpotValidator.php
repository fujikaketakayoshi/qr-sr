<?php

declare(strict_types=1);

namespace QrRally\Domain;

final class SpotValidator
{
    /** @return array<string, string> */
    public function validate(SpotInput $input): array
    {
        $errors = [];
        if ($input->name === '' || mb_strlen($input->name) > 100) {
            $errors['name'] = 'スポット名は1〜100文字で入力してください。';
        }
        if (mb_strlen($input->description) > 1000) {
            $errors['description'] = '説明は1000文字以内で入力してください。';
        }

        return $errors;
    }
}
