<?php

declare(strict_types=1);

namespace Tierza\Domain\Pricing\Enums;

/**
 * Source of the authoritative price resolution.
 */
enum PricingSource: string {
	case CUSTOMER             = 'customer';
	case GROUP                = 'group';
	case WOOCOMMERCE_FALLBACK = 'woocommerce';

	public function is_customer(): bool {
		return self::CUSTOMER === $this;
	}

	public function is_group(): bool {
		return self::GROUP === $this;
	}

	public function is_fallback(): bool {
		return self::WOOCOMMERCE_FALLBACK === $this;
	}
}
