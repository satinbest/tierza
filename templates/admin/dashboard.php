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
$wc_version  = Environment::get_woocommerce_version() ?? __( 'غیرفعال یا نامشخص', 'tierza' );
$is_rtl      = is_rtl();
?>

<div class="wrap tierza-wrap <?php echo $is_rtl ? 'tierza-rtl' : ''; ?>">
	<header class="tierza-header">
		<div class="tierza-header__brand">
			<h1 class="tierza-title">
				<span class="tierza-title__logo">🏷️</span>
				<?php esc_html_e( 'تیرزا — قیمت‌گذاری B2B و همکار ووکامرس', 'tierza' ); ?>
				<span class="tierza-badge">v<?php echo esc_html( TIERZA_VERSION ); ?></span>
			</h1>
			<p class="tierza-subtitle">
				<?php esc_html_e( 'سیستم جامع، ماژولار و بومی قیمت‌گذاری همکار، سطحی و عمده برای فروشگاه‌های ووکامرسی ایران.', 'tierza' ); ?>
			</p>
		</div>
	</header>

	<main class="tierza-content">
		<div class="tierza-card-grid">
			<div class="tierza-card">
				<div class="tierza-card__header">
					<h3><?php esc_html_e( 'وضعیت سامانه', 'tierza' ); ?></h3>
				</div>
				<div class="tierza-card__body">
					<ul class="tierza-status-list">
						<li>
							<span class="tierza-status-label"><?php esc_html_e( 'نسخه تیرزا:', 'tierza' ); ?></span>
							<span class="tierza-status-value"><strong><?php echo esc_html( TIERZA_VERSION ); ?></strong></span>
						</li>
						<li>
							<span class="tierza-status-label"><?php esc_html_e( 'نسخه PHP سرور:', 'tierza' ); ?></span>
							<span class="tierza-status-value tierza-badge-success">
								<?php echo esc_html( $php_version ); ?> (<?php esc_html_e( 'سازگار', 'tierza' ); ?>)
							</span>
						</li>
						<li>
							<span class="tierza-status-label"><?php esc_html_e( 'نسخه وردپرس:', 'tierza' ); ?></span>
							<span class="tierza-status-value tierza-badge-success">
								<?php echo esc_html( $wp_version ); ?>
							</span>
						</li>
						<li>
							<span class="tierza-status-label"><?php esc_html_e( 'نسخه ووکامرس:', 'tierza' ); ?></span>
							<span class="tierza-status-value <?php echo Environment::is_woocommerce_active() ? 'tierza-badge-success' : 'tierza-badge-warning'; ?>">
								<?php echo esc_html( $wc_version ); ?>
							</span>
						</li>
					</ul>
				</div>
			</div>

			<div class="tierza-card">
				<div class="tierza-card__header">
					<h3><?php esc_html_e( 'راه‌اندازی اولیه (TRZ-001)', 'tierza' ); ?></h3>
				</div>
				<div class="tierza-card__body">
					<p>
						<?php esc_html_e( 'هسته اولیه و زیرساخت ماژولار تیرزا با موفقیت بارگذاری شد. مراحل بعدی شامل تعریف گروه‌های مشتریان و موتور قوانین قیمت‌گذاری در حال آماده‌سازی می‌باشد.', 'tierza' ); ?>
					</p>
					<div class="tierza-callout">
						<strong><?php esc_html_e( 'ویژگی‌های طراحی:', 'tierza' ); ?></strong>
						<ul class="tierza-feature-list">
							<li><?php esc_html_e( '✓ عدم تغییر در قیمت‌های اصلی (Regular/Sale) محصولات', 'tierza' ); ?></li>
							<li><?php esc_html_e( '✓ محاسبات سمت سرور و مستقل از لایه‌های نمایشی', 'tierza' ); ?></li>
							<li><?php esc_html_e( '✓ پشتیبانی کامل از زبان فارسی و چینش راست‌به‌چپ (RTL)', 'tierza' ); ?></li>
							<li><?php esc_html_e( '✓ معماری لایه‌بندی‌شده و ماژولار (Clean Architecture)', 'tierza' ); ?></li>
						</ul>
					</div>
				</div>
			</div>
		</div>
	</main>
</div>
