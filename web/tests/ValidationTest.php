<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\Validation;
use GfServer\ValidationException;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function testAcceptsAValidUsername(): void
    {
        Validation::username('player_01');
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsTooShortUsername(): void
    {
        $this->expectException(ValidationException::class);
        Validation::username('abc');
    }

    public function testRejectsUsernameWithIllegalCharacters(): void
    {
        $this->expectException(ValidationException::class);
        Validation::username('bad name!');
    }

    public function testRejectsTooShortPassword(): void
    {
        $this->expectException(ValidationException::class);
        Validation::password('short');
    }

    public function testRejectsPasswordOverBcryptLimit(): void
    {
        $this->expectException(ValidationException::class);
        Validation::password(str_repeat('a', 73));
    }

    public function testRejectsCharacterNameWithWhitespace(): void
    {
        $this->expectException(ValidationException::class);
        Validation::characterName('Sir Lancelot');
    }

    public function testEmailAcceptsValidAndRejectsInvalid(): void
    {
        Validation::email('player@example.com');

        $this->expectException(ValidationException::class);
        Validation::email('not-an-email');
    }
}
