<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\App\FakeMailer;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    public function testFakeMailerRecordsSentMessages(): void
    {
        $mailer = new FakeMailer();
        $mailer->send('player@example.com', 'Subject', 'Body text');

        $this->assertCount(1, $mailer->sent);
        $this->assertSame('player@example.com', $mailer->sent[0]['to']);
        $this->assertSame('Subject', $mailer->sent[0]['subject']);
        $this->assertSame('Body text', $mailer->sent[0]['body']);
    }

    public function testFakeMailerLastReturnsTheMostRecentMessage(): void
    {
        $mailer = new FakeMailer();
        $mailer->send('a@example.com', 'First', 'one');
        $mailer->send('b@example.com', 'Second', 'two');

        $this->assertSame('b@example.com', $mailer->last()['to']);
        $this->assertNull((new FakeMailer())->last());
    }
}
