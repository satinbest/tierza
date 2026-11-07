<?php

declare(strict_types=1);

namespace Tierza\Domain\Exceptions;

use DomainException;

/**
 * Exception thrown when attempting to create or activate a conflicting duplicate pricing rule.
 */
class DuplicateRuleException extends DomainException {
}
