<?php

declare(strict_types=1);

namespace Tierza\Domain\Exceptions;

use DomainException;

/**
 * Exception thrown when a product and variation relationship in pricing context is inconsistent.
 */
class InvalidProductVariationException extends DomainException {
}
