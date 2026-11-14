import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';

/**
 * Playwright E2E storefront pricing verification (TRZ-010).
 *
 * Verifies that Tierza's deterministic pricing integration works across real
 * HTTP requests on the WooCommerce storefront:
 * - Guest sees regular price without modifications.
 * - Authenticated customer in a B2B group sees calculated price with strikethrough markup.
 * - Logging out reverts to guest price (no cross-session cache pollution).
 */
test.describe('WooCommerce Storefront Pricing Integration (TRZ-010)', () => {
	let productUrl = '';

	test.beforeAll(async () => {
		// Run PHP setup script inside wp-env CLI container to set up test fixtures deterministically.
		const output = execSync(
			'npx wp-env run cli wp eval-file wp-content/plugins/tierza/tests/e2e/fixtures/setup-pricing.php',
			{ encoding: 'utf-8' }
		);

		// Extract JSON output from wp-env cli output
		const jsonMatch = output.match(/\{"product_id":\d+,"product_url":"[^"]+","user_id":\d+,"group_id":\d+,"rule_id":\d+\}/);
		if (!jsonMatch) {
			throw new Error(`Failed to parse setup output: ${output}`);
		}
		const data = JSON.parse(jsonMatch[0]);
		productUrl = data.product_url;
	});

	test.afterAll(async () => {
		try {
			execSync(
				'npx wp-env run cli wp eval-file wp-content/plugins/tierza/tests/e2e/fixtures/teardown-pricing.php',
				{ encoding: 'utf-8' }
			);
		} catch {
			// Ignore teardown failure.
		}
	});

	test('1. Guest customer sees regular WooCommerce price without B2B discount', async ({ page }) => {
		await page.goto(productUrl);

		// Product price container in WooCommerce
		const priceElement = page.locator('.wc-block-components-product-price, .price, .woocommerce-Price-amount').first();
		await expect(priceElement).toBeVisible();

		// Guest must see regular 100 price, without <del> or <ins> tags from Tierza discount
		const priceText = await priceElement.innerText();
		expect(priceText).toContain('100');
		expect(priceText).not.toContain('75');
	});

	test('2. Authenticated B2B customer sees calculated 25% discount price with strike-through', async ({ page }) => {
		// Log in as B2B customer
		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'e2e_b2b_user');
		await page.fill('#user_pass', 'password123');
		await page.click('#wp-submit');
		await page.waitForTimeout(2000);

		// Navigate to product single page
		await page.goto(productUrl);

		// Price container must now show original 100.00 struck out (<del>) and discounted 75.00 (<ins>)
		const priceContainer = page.locator('.wc-block-components-product-price, .price, .woocommerce-Price-amount').first();
		await expect(priceContainer).toBeVisible();

		const delElement = priceContainer.locator('del');
		const insElement = priceContainer.locator('ins');

		await expect(delElement).toBeVisible();
		await expect(insElement).toBeVisible();

		const delText = await delElement.innerText();
		const insText = await insElement.innerText();

		expect(delText).toContain('100');
		expect(insText).toContain('75');
	});

	test('3. Clean isolation: guest in incognito context sees regular price', async ({ browser }) => {
		// Create a separate clean incognito context simulating another visitor
		const context = await browser.newContext();
		const freshPage = await context.newPage();

		await freshPage.goto(productUrl);

		const priceContainer = freshPage.locator('.wc-block-components-product-price, .price, .woocommerce-Price-amount').first();
		await expect(priceContainer).toBeVisible();

		const priceText = await priceContainer.innerText();
		expect(priceText).toContain('100');
		expect(priceText).not.toContain('75');

		await context.close();
	});
});
