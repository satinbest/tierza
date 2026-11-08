<?php

declare(strict_types=1);

namespace Tierza\Domain\Pricing\Enums;

/**
 * Deterministic precedence hierarchy levels (1 to 9).
 */
enum PrecedenceLevel: int {
	case CUSTOMER_VARIATION   = 1;
	case CUSTOMER_PRODUCT     = 2;
	case CUSTOMER_CATEGORY    = 3;
	case CUSTOMER_GLOBAL      = 4;
	case GROUP_VARIATION      = 5;
	case GROUP_PRODUCT        = 6;
	case GROUP_CATEGORY       = 7;
	case GROUP_GLOBAL         = 8;
	case WOOCOMMERCE_FALLBACK = 9;

	public function is_customer(): bool {
		return $this->value <= 4;
	}

	public function is_group(): bool {
		return $this->value >= 5 && $this->value <= 8;
	}

	public function is_fallback(): bool {
		return self::WOOCOMMERCE_FALLBACK === $this;
	}

	public function to_source(): PricingSource {
		if ( $this->is_customer() ) {
			return PricingSource::CUSTOMER;
		}
		if ( $this->is_group() ) {
			return PricingSource::GROUP;
		}
		return PricingSource::WOOCOMMERCE_FALLBACK;
	}
}
