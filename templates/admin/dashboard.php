<?php
/**
 * Tierza Admin Dashboard Template.
 *
 * @package Tierza
 */

use Tierza\Infrastructure\Environment;

defined( 'ABSPATH' ) || exit;

$php_version = PHP_VERSION;
$wp_version  = $GLOBALS['wp_version'] ?? '—';
$wc_version  = Environment::get_woocommerce_version() ?? __( 'Inactive or Unknown', 'tierza' );
$is_rtl      = is_rtl();
?>

<div class="wrap tierza-wrap <?php echo $is_rtl ? 'tierza-rtl' : ''; ?>">
	<header class="tierza-header">
		<div class="tierza-header__brand">
			<h1 class="tierza-title">
				<span class="tierza-title__logo">🏷️</span>
				<?php esc_html_e( 'Tierza — B2B & Wholesale Pricing for WooCommerce', 'tierza' ); ?>
				<span class="tierza-badge">v<?php echo esc_html( TIERZA_VERSION ); ?></span>
			</h1>
			<p class="tierza-subtitle">
				<?php esc_html_e( 'Comprehensive, modular, and native wholesale and tier pricing system for WooCommerce stores.', 'tierza' ); ?>
			</p>
		</div>
	</header>

	<main class="tierza-content">
		<div class="tierza-card-grid">
			<div class="tierza-card">
				<div class="tierza-card__header">
					<h3><?php esc_html_e( 'System Status', 'tierza' ); ?></h3>
				</div>
				<div class="tierza-card__body">
					<ul class="tierza-status-list">
						<li>
							<span class="tierza-status-label"><?php esc_html_e( 'Tierza Version:', 'tierza' ); ?></span>
							<span class="tierza-status-value"><strong><?php echo esc_html( TIERZA_VERSION ); ?></strong></span>
						</li>
						<li>
							<span class="tierza-status-label"><?php esc_html_e( 'Server PHP Version:', 'tierza' ); ?></span>
							<span class="tierza-status-value tierza-badge-success">
								<?php echo esc_html( $php_version ); ?> (<?php esc_html_e( 'Compatible', 'tierza' ); ?>)
							</span>
						</li>
						<li>
							<span class="tierza-status-label"><?php esc_html_e( 'WordPress Version:', 'tierza' ); ?></span>
							<span class="tierza-status-value tierza-badge-success">
								<?php echo esc_html( $wp_version ); ?>
							</span>
						</li>
						<li>
							<span class="tierza-status-label"><?php esc_html_e( 'WooCommerce Version:', 'tierza' ); ?></span>
							<span class="tierza-status-value <?php echo Environment::is_woocommerce_active() ? 'tierza-badge-success' : 'tierza-badge-warning'; ?>">
								<?php echo esc_html( $wc_version ); ?>
							</span>
						</li>
					</ul>
				</div>
			</div>

			<div class="tierza-card">
				<div class="tierza-card__header">
					<h3><?php esc_html_e( 'Initial Bootstrap (TRZ-001)', 'tierza' ); ?></h3>
				</div>
				<div class="tierza-card__body">
					<p>
						<?php esc_html_e( 'Core infrastructure loaded successfully. Customer pricing groups schema and pricing rule engine will be initialized in upcoming milestones.', 'tierza' ); ?>
					</p>
					<div class="tierza-callout">
						<strong><?php esc_html_e( 'Design Highlights:', 'tierza' ); ?></strong>
						<ul class="tierza-feature-list">
							<li><?php esc_html_e( '✓ Original regular and sale prices are never overwritten', 'tierza' ); ?></li>
							<li><?php esc_html_e( '✓ Authoritative server-side price calculations', 'tierza' ); ?></li>
							<li><?php esc_html_e( '✓ Persian localization with full RTL layout support', 'tierza' ); ?></li>
							<li><?php esc_html_e( '✓ Clean layered architecture', 'tierza' ); ?></li>
						</ul>
					</div>
				</div>
			</div>
		</div>
	</main>
</div>
