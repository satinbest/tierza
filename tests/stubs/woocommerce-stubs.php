<?php
/**
 * WooCommerce & WordPress stubs for unit tests and static analysis.
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = 'product';
		public string $post_title = '';
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id = 0;
		public string $name = '';
		public string $slug = '';
		public string $taxonomy = 'product_cat';
		public int $parent = 0;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var array<string, array<string>> */
		public array $errors = [];

		public function __construct( string $code = '', string $message = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
			}
		}

		public function add( string $code, string $message ): void {
			$this->errors[ $code ][] = $message;
		}

		public function has_errors(): bool {
			return ! empty( $this->errors );
		}

		public function get_error_message( string $code = '' ): string {
			if ( '' !== $code ) {
				return $this->errors[ $code ][0] ?? '';
			}
			$first = reset( $this->errors );
			return is_array( $first ) ? ( $first[0] ?? '' ) : '';
		}

		/**
		 * @param string $code
		 * @return string[]
		 */
		public function get_error_messages( string $code = '' ): array {
			if ( '' !== $code ) {
				return $this->errors[ $code ] ?? [];
			}
			$all = [];
			foreach ( $this->errors as $messages ) {
				$all = array_merge( $all, $messages );
			}
			return $all;
		}

		/**
		 * @return string[]
		 */
		public function get_error_codes(): array {
			return array_keys( $this->errors );
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		/** @var array<WP_Post|int> */
		public array $posts = [];
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		protected int $id = 0;
		protected string $price = '';
		protected string $regular_price = '';
		protected string $sale_price = '';
		protected ?DateTime $date_on_sale_from = null;
		protected ?DateTime $date_on_sale_to = null;

		/**
		 * @param array<string, mixed> $props
		 */
		public function __construct( int $id = 0, array $props = [] ) {
			$this->id = $id;
			if ( isset( $props['price'] ) ) {
				$this->price = (string) $props['price'];
			}
			if ( isset( $props['regular_price'] ) ) {
				$this->regular_price = (string) $props['regular_price'];
			}
			if ( isset( $props['sale_price'] ) ) {
				$this->sale_price = (string) $props['sale_price'];
			}
		}

		public function get_id(): int {
			return $this->id;
		}

		public function set_id( int $id ): void {
			$this->id = $id;
		}

		public function get_parent_id(): int {
			return 0;
		}

		public function get_type(): string {
			return 'simple';
		}

		/**
		 * @param string|array<string> $type
		 */
		public function is_type( string|array $type ): bool {
			return is_array( $type ) ? in_array( $this->get_type(), $type, true ) : $this->get_type() === $type;
		}

		public function get_price( string $context = 'view' ): string {
			return $this->price;
		}

		public function set_price( string $price ): void {
			$this->price = $price;
		}

		public function get_regular_price( string $context = 'view' ): string {
			return $this->regular_price;
		}

		public function set_regular_price( string $price ): void {
			$this->regular_price = $price;
		}

		public function get_sale_price( string $context = 'view' ): string {
			return $this->sale_price;
		}

		public function set_sale_price( string $price ): void {
			$this->sale_price = $price;
		}

		public function get_price_suffix(): string {
			return '';
		}

		public function get_date_on_sale_from( string $context = 'view' ): ?DateTime {
			return $this->date_on_sale_from;
		}

		public function set_date_on_sale_from( ?DateTime $date ): void {
			$this->date_on_sale_from = $date;
		}

		public function get_date_on_sale_to( string $context = 'view' ): ?DateTime {
			return $this->date_on_sale_to;
		}

		public function set_date_on_sale_to( ?DateTime $date ): void {
			$this->date_on_sale_to = $date;
		}

		public function is_on_sale( string $context = 'view' ): bool {
			return '' !== $this->sale_price && (float) $this->regular_price > (float) $this->sale_price;
		}

		/**
		 * @return array<int>
		 */
		public function get_visible_children(): array {
			return [];
		}

		/**
		 * @return array<string, array<int, string>>
		 */
		public function get_variation_prices( bool $for_display = false ): array {
			return [
				'price'         => [],
				'regular_price' => [],
				'sale_price'    => [],
			];
		}
	}
}

if ( ! class_exists( 'WC_Product_Variation' ) ) {
	class WC_Product_Variation extends WC_Product {
		protected int $parent_id = 0;

		/**
		 * @param array<string, mixed> $props
		 */
		public function __construct( int $id = 0, array $props = [] ) {
			parent::__construct( $id, $props );
			if ( isset( $props['parent_id'] ) ) {
				$this->parent_id = (int) $props['parent_id'];
			}
		}

		public function get_type(): string {
			return 'variation';
		}

		public function get_parent_id(): int {
			return $this->parent_id;
		}

		public function set_parent_id( int $id ): void {
			$this->parent_id = $id;
		}
	}
}

if ( ! class_exists( 'WC_Product_Variable' ) ) {
	class WC_Product_Variable extends WC_Product {
		public function get_type(): string {
			return 'variable';
		}
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		global $current_user_id_mock;
		return is_int( $current_user_id_mock ) ? $current_user_id_mock : 0;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, ...$args ): bool {
		global $current_user_caps_mock;
		return ! empty( $current_user_caps_mock[ $capability ] );
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax(): bool {
		global $wp_doing_ajax_mock;
		return ! empty( $wp_doing_ajax_mock );
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( int|WP_Post|WC_Product $the_product = 0 ): ?WC_Product {
		global $wc_products_mock;
		$id = $the_product instanceof WC_Product ? $the_product->get_id() : ( $the_product instanceof WP_Post ? $the_product->ID : (int) $the_product );
		return $wc_products_mock[ $id ] ?? null;
	}
}

if ( ! function_exists( 'wc_price' ) ) {
	/**
	 * @param float|string $price
	 * @param array<string, mixed> $args
	 */
	function wc_price( float|string $price, array $args = [] ): string {
		$decimals = $args['decimals'] ?? 2;
		return sprintf( '$%.*f', (int) $decimals, (float) $price );
	}
}

if ( ! function_exists( 'wc_format_decimal' ) ) {
	function wc_format_decimal( float|string $number, int|bool $dp = false, bool $trim_zeros = false ): string {
		$decimals = is_int( $dp ) ? $dp : 2;
		return number_format( (float) $number, $decimals, '.', '' );
	}
}

if ( ! function_exists( 'wc_format_sale_price' ) ) {
	function wc_format_sale_price( string $regular_price, string $sale_price ): string {
		return '<del aria-hidden="true">' . $regular_price . '</del> <ins>' . $sale_price . '</ins>';
	}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency(): string {
		global $wc_currency_mock;
		return is_string( $wc_currency_mock ) && '' !== $wc_currency_mock ? $wc_currency_mock : 'USD';
	}
}

if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	function wc_get_price_decimals(): int {
		global $wc_decimals_mock;
		return is_int( $wc_decimals_mock ) ? $wc_decimals_mock : 2;
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	/**
	 * @param int|WP_Post $post
	 * @param string $taxonomy
	 * @return array<WP_Term>|false
	 */
	function get_the_terms( int|WP_Post $post, string $taxonomy ): array|false {
		global $wp_terms_mock;
		$id = $post instanceof WP_Post ? $post->ID : (int) $post;
		return $wp_terms_mock[ $id ] ?? [];
	}
}

if ( ! function_exists( 'get_ancestors' ) ) {
	/**
	 * @param int $object_id
	 * @param string $object_type
	 * @param string $resource_type
	 * @return array<int>
	 */
	function get_ancestors( int $object_id, string $object_type = '', string $resource_type = '' ): array {
		global $wp_ancestors_mock;
		return $wp_ancestors_mock[ $object_id ] ?? [];
	}
}

if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger(): object {
		return new class() {
			/**
			 * @param string $message
			 * @param array<string, mixed> $context
			 */
			public function error( string $message, array $context = [] ): void {}
		};
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return strip_tags( trim( $str ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param string|array<mixed> $value
	 * @return string|array<mixed>
	 */
	function wp_unslash( string|array $value ): string|array {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $wp_filters;
		$wp_filters[ $hook ][] = [
			'callback' => $callback,
			'priority' => $priority,
			'args'     => $accepted_args,
		];
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, ...$args ): mixed {
		global $wp_filters;
		if ( ! empty( $wp_filters[ $hook ] ) ) {
			foreach ( $wp_filters[ $hook ] as $item ) {
				$call_args = array_merge( [ $value ], $args );
				$value = call_user_func_array( $item['callback'], array_slice( $call_args, 0, $item['args'] ) );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $tag, callable $function_to_remove, int $priority = 10 ): bool {
		global $wp_filters;
		if ( ! empty( $wp_filters[ $tag ] ) ) {
			foreach ( $wp_filters[ $tag ] as $key => $item ) {
				if ( $item['callback'] === $function_to_remove && $item['priority'] === $priority ) {
					unset( $wp_filters[ $tag ][ $key ] );
					return true;
				}
			}
		}
		return false;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( string $tag, callable $function_to_remove, int $priority = 10 ): bool {
		return remove_filter( $tag, $function_to_remove, $priority );
	}
}

if ( ! class_exists( 'WC_Cache_Helper' ) ) {
	class WC_Cache_Helper {
		public static function get_transient_version( string $group, bool $refresh = false ): string {
			return '1.0';
		}
	}
}

if ( ! class_exists( 'WC_Cart' ) ) {
	class WC_Cart {
		/** @var array<string, array<string, mixed>> */
		public array $cart_contents = [];
		public float $total = 0.0;
		public float $subtotal = 0.0;

		/**
		 * @return array<string, array<string, mixed>>
		 */
		public function get_cart(): array {
			return $this->cart_contents;
		}

		/**
		 * @param array<string, array<string, mixed>> $contents
		 */
		public function set_cart_contents( array $contents ): void {
			$this->cart_contents = $contents;
		}

		public function calculate_totals(): void {
			if ( function_exists( 'do_action' ) ) {
				do_action( 'woocommerce_before_calculate_totals', $this );
			}
			$this->subtotal = 0.0;
			foreach ( $this->cart_contents as $item ) {
				if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
					$qty = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
					$this->subtotal += (float) $item['data']->get_price() * $qty;
				}
			}
			$this->total = $this->subtotal;
		}

		public function is_empty(): bool {
			return empty( $this->cart_contents );
		}

		public function empty_cart(): void {
			$this->cart_contents = [];
			$this->total         = 0.0;
			$this->subtotal      = 0.0;
		}
	}
}

if ( ! class_exists( 'WC_Customer' ) ) {
	class WC_Customer {
		public int $id = 0;

		public function __construct( int $id = 0 ) {
			$this->id = $id;
		}

		public function get_id(): int {
			return $this->id;
		}
	}
}

if ( ! class_exists( 'WC_Checkout' ) ) {
	class WC_Checkout {
		private static ?WC_Checkout $instance = null;

		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	class WC_Order_Item_Product {
		public int $id = 0;
		public int $product_id = 0;
		public int $variation_id = 0;
		public int $quantity = 1;
		public string $subtotal = '0.00';
		public string $subtotal_tax = '0.00';
		public string $total = '0.00';
		public string $total_tax = '0.00';
		public ?WC_Product $product = null;

		public function get_id(): int {
			return $this->id;
		}

		public function get_product_id(): int {
			return $this->product_id;
		}

		public function get_variation_id(): int {
			return $this->variation_id;
		}

		public function get_quantity(): int {
			return $this->quantity;
		}

		public function get_subtotal(): string {
			return $this->subtotal;
		}

		public function get_subtotal_tax(): string {
			return $this->subtotal_tax;
		}

		public function get_total(): string {
			return $this->total;
		}

		public function get_total_tax(): string {
			return $this->total_tax;
		}

		public function get_product(): ?WC_Product {
			return $this->product;
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public int $id = 0;
		public int $customer_id = 0;
		public string $total = '0.00';
		/** @var array<int|string, WC_Order_Item_Product> */
		public array $items = [];

		public function get_id(): int {
			return $this->id;
		}

		public function get_customer_id(): int {
			return $this->customer_id;
		}

		public function get_total(): string {
			return $this->total;
		}

		/**
		 * @param string $type
		 * @return array<int|string, object>
		 */
		public function get_items( string $type = 'line_item' ): array {
			return $this->items;
		}

		public function delete( bool $force_delete = false ): bool {
			return true;
		}
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	/**
	 * @param string $message
	 * @param string $notice_type
	 * @param array<string, mixed> $data
	 */
	function wc_add_notice( string $message, string $notice_type = 'success', array $data = [] ): void {
		global $wc_notices;
		if ( ! is_array( $wc_notices ) ) {
			$wc_notices = [];
		}
		$wc_notices[ $notice_type ][] = $message;
	}
}

if ( ! function_exists( 'wc_get_notices' ) ) {
	/**
	 * @param string $notice_type
	 * @return array<string, array<string>>
	 */
	function wc_get_notices( string $notice_type = '' ): array {
		global $wc_notices;
		if ( ! is_array( $wc_notices ) ) {
			return [];
		}
		if ( '' !== $notice_type ) {
			return $wc_notices[ $notice_type ] ?? [];
		}
		return $wc_notices;
	}
}

if ( ! function_exists( 'wc_clear_notices' ) ) {
	function wc_clear_notices(): void {
		global $wc_notices;
		$wc_notices = [];
	}
}

if ( ! function_exists( 'wc_prices_include_tax' ) ) {
	function wc_prices_include_tax(): bool {
		global $wc_prices_include_tax_mock;
		return (bool) ( $wc_prices_include_tax_mock ?? false );
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC(): stdClass {
		static $wc;
		if ( ! $wc ) {
			$wc       = new stdClass();
			$wc->cart = new WC_Cart();
		}
		return $wc;
	}
}
