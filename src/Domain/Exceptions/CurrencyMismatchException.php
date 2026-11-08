<?php

declare(strict_types=1);

namespace Tierza\Domain\Exceptions;

use DomainException;

/**
 * Exception thrown when monetary operations or comparisons are attempted across differing currencies.
 */
class CurrencyMismatchException extends DomainException {
}
