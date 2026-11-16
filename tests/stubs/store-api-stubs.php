<?php
/**
 * WooCommerce Store API stubs for unit tests and static analysis.
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\StoreApi\Exceptions;

if ( ! class_exists( RouteException::class ) ) {
	class RouteException extends \Exception {
		protected string $error_code;
		/**
		 * @var array<string, mixed>
		 */
		protected array $additional_data;

		/**
		 * @param array<string, mixed> $additional_data
		 */
		public function __construct( string $error_code, string $message, int $code = 400, array $additional_data = [] ) {
			parent::__construct( $message, $code );
			$this->error_code      = $error_code;
			$this->additional_data = $additional_data;
		}

		public function getErrorCode(): string {
			return $this->error_code;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function getAdditionalData(): array {
			return $this->additional_data;
		}
	}
}

if ( ! class_exists( InvalidCartException::class ) ) {
	class InvalidCartException extends \Exception {
		protected string $error_code;
		protected mixed $error;
		/**
		 * @var array<string, mixed>
		 */
		protected array $additional_data;

		/**
		 * @param array<string, mixed> $additional_data
		 */
		public function __construct( string $error_code, mixed $error, int $code = 409, array $additional_data = [] ) {
			parent::__construct( is_scalar( $error ) ? (string) $error : 'Invalid Cart', $code );
			$this->error_code      = $error_code;
			$this->error           = $error;
			$this->additional_data = $additional_data;
		}

		public function getErrorCode(): string {
			return $this->error_code;
		}

		public function getError(): mixed {
			return $this->error;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function getAdditionalData(): array {
			return $this->additional_data;
		}
	}
}
