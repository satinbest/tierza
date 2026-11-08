<?php

declare(strict_types=1);

namespace Tierza\Domain\Exceptions;

use DomainException;

/**
 * Exception thrown when a base price is missing or zero/unresolvable when evaluating pricing.
 */
class MissingBasePriceException extends DomainException {
}
