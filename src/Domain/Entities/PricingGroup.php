<?php

declare(strict_types=1);

namespace Tierza\Domain\Entities;

use DateTimeImmutable;
use Tierza\Domain\Exceptions\InvalidGroupException;
use Tierza\Domain\ValueObjects\GroupSlug;
use Tierza\Domain\ValueObjects\GroupStatus;

/**
 * Domain entity representing a customer pricing tier / group.
 */
final class PricingGroup {

	private ?int $id;
	private string $name;
	private GroupSlug $slug;
	private ?string $description;
	private GroupStatus $status;
	private int $sort_order;
	private ?DateTimeImmutable $created_at;
	private ?DateTimeImmutable $updated_at;

	public function __construct(
		string $name,
		GroupSlug|string $slug,
		?int $id = null,
		?string $description = null,
		GroupStatus|string $status = GroupStatus::ACTIVE,
		int $sort_order = 0,
		?DateTimeImmutable $created_at = null,
		?DateTimeImmutable $updated_at = null
	) {
		$trimmed_name = trim( $name );
		if ( '' === $trimmed_name ) {
			throw new InvalidGroupException( 'Group name cannot be empty.' );
		}

		if ( mb_strlen( $trimmed_name, 'UTF-8' ) > 190 ) {
			throw new InvalidGroupException( 'Group name exceeds maximum length of 190 characters.' );
		}

		$this->id          = $id;
		$this->name        = $trimmed_name;
		$this->slug        = is_string( $slug ) ? new GroupSlug( $slug ) : $slug;
		$this->description = null !== $description ? trim( $description ) : null;
		$this->status      = is_string( $status ) ? new GroupStatus( $status ) : $status;
		$this->sort_order  = $sort_order;
		$this->created_at  = $created_at;
		$this->updated_at  = $updated_at;
	}

	public function id(): ?int {
		return $this->id;
	}

	public function with_id( int $id ): self {
		$clone     = clone $this;
		$clone->id = $id;
		return $clone;
	}

	public function name(): string {
		return $this->name;
	}

	public function slug(): GroupSlug {
		return $this->slug;
	}

	public function description(): ?string {
		return $this->description;
	}

	public function status(): GroupStatus {
		return $this->status;
	}

	public function sort_order(): int {
		return $this->sort_order;
	}

	public function created_at(): ?DateTimeImmutable {
		return $this->created_at;
	}

	public function updated_at(): ?DateTimeImmutable {
		return $this->updated_at;
	}

	public function is_active(): bool {
		return $this->status->is_active();
	}

	public function activate(): self {
		$clone             = clone $this;
		$clone->status     = GroupStatus::active();
		$clone->updated_at = new DateTimeImmutable();
		return $clone;
	}

	public function deactivate(): self {
		$clone             = clone $this;
		$clone->status     = GroupStatus::inactive();
		$clone->updated_at = new DateTimeImmutable();
		return $clone;
	}

	public function update_details(
		string $name,
		GroupSlug|string $slug,
		?string $description = null,
		?int $sort_order = null
	): self {
		$trimmed_name = trim( $name );
		if ( '' === $trimmed_name ) {
			throw new InvalidGroupException( 'Group name cannot be empty.' );
		}

		if ( mb_strlen( $trimmed_name, 'UTF-8' ) > 190 ) {
			throw new InvalidGroupException( 'Group name exceeds maximum length of 190 characters.' );
		}

		$clone              = clone $this;
		$clone->name        = $trimmed_name;
		$clone->slug        = is_string( $slug ) ? new GroupSlug( $slug ) : $slug;
		$clone->description = null !== $description ? trim( $description ) : $this->description;
		if ( null !== $sort_order ) {
			$clone->sort_order = $sort_order;
		}
		$clone->updated_at = new DateTimeImmutable();
		return $clone;
	}
}
