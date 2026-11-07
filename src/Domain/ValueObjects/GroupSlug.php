<?php

declare(strict_types=1);

namespace Tierza\Domain\ValueObjects;

use Tierza\Domain\Exceptions\InvalidGroupException;

/**
 * Value object representing a normalized pricing group slug.
 * Supports both Persian (Unicode) and ASCII characters safely.
 */
final class GroupSlug {

	private string $slug;

	public function __construct( string $raw_slug ) {
		$normalized = self::normalize( $raw_slug );

		if ( '' === $normalized ) {
			throw new InvalidGroupException( 'Group slug cannot be empty after normalization.' );
		}

		if ( mb_strlen( $normalized, 'UTF-8' ) > 190 ) {
			throw new InvalidGroupException( 'Group slug exceeds maximum length of 190 characters.' );
		}

		$this->slug = $normalized;
	}

	/**
	 * Normalize raw input string into a valid slug.
	 */
	public static function normalize( string $input ): string {
		// Trim surrounding whitespace.
		$slug = trim( $input );

		// Convert ASCII characters to lowercase.
		$slug = mb_strtolower( $slug, 'UTF-8' );

		// Replace underscores, spaces, and tabs with hyphens.
		$slug = (string) preg_replace( '/[\s_]+/u', '-', $slug );

		// Remove any characters that are not letters (including Arabic/Persian), numbers, or hyphens.
		// \p{L} matches any Unicode letter, \p{N} matches numbers.
		$slug = (string) preg_replace( '/[^\p{L}\p{N}\-]/u', '', $slug );

		// Collapse multiple consecutive hyphens into one.
		$slug = (string) preg_replace( '/-+/', '-', $slug );

		// Trim leading and trailing hyphens.
		return trim( $slug, '-' );
	}

	public function value(): string {
		return $this->slug;
	}

	public function equals( GroupSlug $other ): bool {
		return $this->slug === $other->value();
	}

	public function __toString(): string {
		return $this->slug;
	}
}
