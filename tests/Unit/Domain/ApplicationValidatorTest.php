<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use QrRally\Domain\ApplicationInput;
use QrRally\Domain\ApplicationValidator;

final class ApplicationValidatorTest extends TestCase
{
    private array $fields = [
        ['field_type'=>'name','is_enabled'=>1,'is_required'=>1],
        ['field_type'=>'email','is_enabled'=>1,'is_required'=>0],
        ['field_type'=>'address','is_enabled'=>0,'is_required'=>0],
    ];

    public function testValidatesRequiredEmailConfirmationAndConsent(): void
    {
        $validator = new ApplicationValidator();
        $errors = $validator->validate(new ApplicationInput(['name'=>'','email'=>'bad','email_confirmation'=>'other'], false), $this->fields);
        self::assertArrayHasKey('name', $errors);
        self::assertArrayHasKey('email', $errors);
        self::assertArrayHasKey('email_confirmation', $errors);
        self::assertArrayHasKey('privacy_accepted', $errors);
    }

    public function testIgnoresDisabledField(): void
    {
        $errors = (new ApplicationValidator())->validate(new ApplicationInput(['name'=>'山田','email'=>'','email_confirmation'=>'','address'=>str_repeat('a',600)], true), $this->fields);
        self::assertSame([], $errors);
    }

    public function testDoesNotRetroactivelyRequireNewFieldForExistingApplication(): void
    {
        $errors = (new ApplicationValidator())->validate(
            new ApplicationInput(['name'=>'山田','email'=>'','email_confirmation'=>''], true),
            [['field_type'=>'name','is_enabled'=>1,'is_required'=>1], ['field_type'=>'email','is_enabled'=>1,'is_required'=>1]],
            ['name'=>'山田', 'email'=>null],
        );
        self::assertSame([], $errors);
    }
}
