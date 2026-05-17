<?php

declare(strict_types=1);

namespace GfServer\App;

/** Test double: records messages instead of sending them. */
final class FakeMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $body): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }

    /** @return array{to: string, subject: string, body: string}|null */
    public function last(): ?array
    {
        if ($this->sent === []) {
            return null;
        }

        return $this->sent[array_key_last($this->sent)];
    }
}
