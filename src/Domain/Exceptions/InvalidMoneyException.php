<?php

declare(strict_types=1);

namespace Tierza\Domain\Exceptions;

use DomainException;

/**
 * Exception thrown when a monetary value or currency code is invalid.
 */
class InvalidMoneyException extends DomainException {
}
