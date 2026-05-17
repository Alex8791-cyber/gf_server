<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * Sends transactional mail via PHP's mail(), which relies on a host-level
 * MTA / SMTP relay being configured on the server. The From identity comes
 * from the GF_MAIL_FROM / GF_MAIL_FROM_NAME environment variables.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    /** Build an SmtpMailer from the GF_MAIL_FROM / GF_MAIL_FROM_NAME environment variables. */
    public static function fromEnv(): self
    {
        $from = getenv('GF_MAIL_FROM') ?: 'noreply@localhost';
        $name = getenv('GF_MAIL_FROM_NAME') ?: 'Grand Fantasia';

        return new self($from, $name);
    }

    public function send(string $to, string $subject, string $body): void
    {
        $headers = [
            'From' => sprintf('%s <%s>', $this->fromName, $this->fromAddress),
            'Content-Type' => 'text/plain; charset=UTF-8',
            'MIME-Version' => '1.0',
        ];
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $ok = mail($to, $subject, $body, implode("\r\n", $headerLines));
        if ($ok === false) {
            throw new \RuntimeException("Failed to send mail to {$to}.");
        }
    }
}
