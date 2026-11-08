<?php

declare(strict_types=1);

namespace Tierza\Domain\Exceptions;

use RuntimeException;

/**
 * Exception thrown when database schema installation or verification fails.
 */
class SchemaMigrationException extends RuntimeException {
}
