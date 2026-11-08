<?php

declare(strict_types=1);

namespace Tierza\Domain\Exceptions;

use DomainException;

/**
 * Exception thrown when price calculation encounters an invalid state or invariant violation.
 */
class PricingCalculationException extends DomainException {
}
