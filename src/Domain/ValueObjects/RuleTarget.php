<?php

declare(strict_types=1);

namespace Tierza\Domain\ValueObjects;

use Tierza\Domain\Exceptions\InvalidRuleException;

/**
 * Value object representing a pricing rule target (customer group or specific user).
 */
final class RuleTarget {

	public const TYPE_GROUP = 'group';
	public const TYPE_USER  = 'user';

	private const ALLOWED_TYPES = [
		self::TYPE_GROUP,
		self::TYPE_USER,
	];

	private string $type;
	private int $id;

	public function __construct( string $type, int $id ) {
		$normalized_type = strtolower( trim( $type ) );

		if ( ! in_array( $normalized_type, self::ALLOWED_TYPES, true ) ) {
			throw new InvalidRuleException(
				sprintf( 'Invalid target type "%s". Allowed: %s', $type, implode( ', ', self::ALLOWED_TYPES ) )
			);
		}

		if ( $id <= 0 ) {
			throw new InvalidRuleException(
				sprintf( 'Target ID must be a positive integer, got %d.', $id )
			);
		}

		$this->type = $normalized_type;
		$this->id   = $id;
	}

	public static function group( int $group_id ): self {
		return new self( self::TYPE_GROUP, $group_id );
	}

	public static function user( int $user_id ): self {
		return new self( self::TYPE_USER, $user_id );
	}

	public function is_group(): bool {
		return self::TYPE_GROUP === $this->type;
	}

	public function is_user(): bool {
		return self::TYPE_USER === $this->type;
	}

	public function type(): string {
		return $this->type;
	}

	public function id(): int {
		return $this->id;
	}

	public function equals( RuleTarget $other ): bool {
		return $this->type === $other->type() && $this->id === $other->id();
	}
}
