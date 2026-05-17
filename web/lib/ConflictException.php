<?php

declare(strict_types=1);

namespace GfServer;

/** Thrown when an operation conflicts with existing data (e.g. duplicate username). */
final class ConflictException extends \RuntimeException
{
}
