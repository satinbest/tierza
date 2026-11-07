<?php

declare(strict_types=1);

namespace Tierza\Domain\Entities;

use DateTimeImmutable;
use Tierza\Domain\Exceptions\InvalidAssignmentException;

/**
 * Domain entity representing assignment of a user to a primary pricing group.
 */
final class UserGroupAssignment {

	private ?int $id;
	private int $user_id;
	private int $group_id;
	private ?DateTimeImmutable $created_at;
	private ?DateTimeImmutable $updated_at;

	public function __construct(
		int $user_id,
		int $group_id,
		?int $id = null,
		?DateTimeImmutable $created_at = null,
		?DateTimeImmutable $updated_at = null
	) {
		if ( $user_id <= 0 ) {
			throw new InvalidAssignmentException(
				sprintf( 'User ID must be a positive integer, got %d.', $user_id )
			);
		}

		if ( $group_id <= 0 ) {
			throw new InvalidAssignmentException(
				sprintf( 'Group ID must be a positive integer, got %d.', $group_id )
			);
		}

		$this->id         = $id;
		$this->user_id    = $user_id;
		$this->group_id   = $group_id;
		$this->created_at = $created_at;
		$this->updated_at = $updated_at;
	}

	public function id(): ?int {
		return $this->id;
	}

	public function with_id( int $id ): self {
		$clone     = clone $this;
		$clone->id = $id;
		return $clone;
	}

	public function user_id(): int {
		return $this->user_id;
	}

	public function group_id(): int {
		return $this->group_id;
	}

	public function created_at(): ?DateTimeImmutable {
		return $this->created_at;
	}

	public function updated_at(): ?DateTimeImmutable {
		return $this->updated_at;
	}

	public function reassign_to_group( int $new_group_id ): self {
		if ( $new_group_id <= 0 ) {
			throw new InvalidAssignmentException(
				sprintf( 'New group ID must be a positive integer, got %d.', $new_group_id )
			);
		}

		$clone             = clone $this;
		$clone->group_id   = $new_group_id;
		$clone->updated_at = new DateTimeImmutable();
		return $clone;
	}
}
