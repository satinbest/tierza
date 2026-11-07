<?php

declare(strict_types=1);

namespace Tierza\Domain\ValueObjects;

use Tierza\Domain\Exceptions\InvalidGroupException;

/**
 * Value object representing pricing group status.
 */
final class GroupStatus {

	public const ACTIVE   = 'active';
	public const INACTIVE = 'inactive';

	private const ALLOWED = [
		self::ACTIVE,
		self::INACTIVE,
	];

	private string $value;

	public function __construct( string $value ) {
		$normalized = strtolower( trim( $value ) );
		if ( ! in_array( $normalized, self::ALLOWED, true ) ) {
			throw new InvalidGroupException(
				sprintf( 'Invalid group status "%s". Allowed: %s', $value, implode( ', ', self::ALLOWED ) )
			);
		}
		$this->value = $normalized;
	}

	public static function active(): self {
		return new self( self::ACTIVE );
	}

	public static function inactive(): self {
		return new self( self::INACTIVE );
	}

	public function is_active(): bool {
		return self::ACTIVE === $this->value;
	}

	public function value(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
