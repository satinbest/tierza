<?php

declare(strict_types=1);

namespace Tierza\Domain\Exceptions;

use DomainException;

/**
 * Exception thrown when a PricingContext is created with invalid or missing required attributes.
 */
class InvalidPricingContextException extends DomainException {
}
