<?php

declare(strict_types=1);

namespace Tierza\Domain\Exceptions;

use DomainException;

/**
 * Exception thrown when attempting to use a group slug that is already registered.
 */
class DuplicateSlugException extends DomainException {
}
