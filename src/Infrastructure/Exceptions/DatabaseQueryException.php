<?php

declare(strict_types=1);

namespace Tierza\Infrastructure\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when a database query execution fails in the persistence layer.
 *
 * Designed to report operational database failures without leaking raw SQL statements,
 * database credentials, or private customer data into user-facing error messages.
 */
class DatabaseQueryException extends RuntimeException {
}
