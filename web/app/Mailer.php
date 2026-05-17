<?php

declare(strict_types=1);

namespace GfServer\App;

/** Sends a transactional email. */
interface Mailer
{
    public function send(string $to, string $subject, string $body): void;
}
