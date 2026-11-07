<?php

declare(strict_types=1);

namespace Tierza\Domain\Exceptions;

use DomainException;

/**
 * Exception thrown when attempting to delete a pricing group that has assigned users or active rules.
 */
class GroupDeletionSafeguardException extends DomainException {
}
