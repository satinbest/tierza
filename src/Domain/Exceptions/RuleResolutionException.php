<?php

declare(strict_types=1);

namespace Tierza\Domain\Exceptions;

use DomainException;

/**
 * Exception thrown when the rule resolver encounters an invalid or unresolvable internal state.
 */
class RuleResolutionException extends DomainException {
}
