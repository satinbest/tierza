<?php

declare(strict_types=1);

namespace Tierza\Domain\Exceptions;

use DomainException;

/**
 * Exception thrown when a pricing rule has invalid attributes or configuration.
 */
class InvalidRuleException extends DomainException {
}
