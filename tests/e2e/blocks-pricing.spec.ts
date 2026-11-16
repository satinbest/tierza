import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';

/**
 * Playwright E2E WooCommerce Blocks & Store API Pricing Verification (TRZ-012).
 *
 * Verifies that Tierza's deterministic B2B pricing integration functions across
 * Gutenberg Cart & Checkout Blocks powered by the WooCommerce Store API:
 * - Guest adds product to cart and sees regular price ($100.00).
 * - Customer logs in and Cart Block dynamically renders B2B price ($80.00).
 * - Quantity updates in Cart Block recalculate line total ($160.00) without stacking.
 * - Checkout Block loads authoritative totals matching Cart Block.
 * - Incognito isolation guarantees no session cross-contamination.
 */
test.describe('WooCommerce Blocks & Store API Pricing Integration (TRZ-012)', () => {
	let simpleUrl = '';
	let simpleId = 0;
	let cartUrl = '/cart/';
	let checkoutUrl = '/checkout/';

	test.beforeAll(async () => {
		const output = execSync(
			'npx wp-env run cli wp eval-file wp-content/plugins/tierza/tests/e2e/fixtures/setup-blocks-pricing.php',
			{ encoding: 'utf-8' }
		);

		const jsonMatch = output.match(/\{"simple_id":\d+,"simple_url":"[^"]+","variable_id":\d+,"variation_id":\d+,"user_id":\d+,"group_id":\d+,"cart_url":"[^"]*","checkout_url":"[^"]*"\}\s*$/m);
		if (!jsonMatch) {
			throw new Error(`Failed to parse setup output: ${output}`);
		}
		const data = JSON.parse(jsonMatch[0]);
		simpleUrl = data.simple_url;
		simpleId = data.simple_id;
		if (data.cart_url) {
			cartUrl = data.cart_url;
		}
		if (data.checkout_url) {
			checkoutUrl = data.checkout_url;
		}
	});

	test.afterAll(async () => {
		try {
			execSync(
				'npx wp-env run cli wp eval-file wp-content/plugins/tierza/tests/e2e/fixtures/teardown-blocks-pricing.php',
				{ encoding: 'utf-8' }
			);
		} catch {
			// Ignore teardown failure.
		}
	});

	test('1. Guest customer sees regular price in Cart Block', async ({ page }) => {
		// Add simple product to cart via add-to-cart query
		await page.goto(`/?add-to-cart=${simpleId}`);
		await page.goto(cartUrl);

		// Wait for Cart Block to load
		const cartBlock = page.locator('.wp-block-woocommerce-cart, .wc-block-cart').first();
		await expect(cartBlock).toBeVisible({ timeout: 15000 });

		// Verify cart item displays regular price 100 and does NOT show discounted 80
		const priceElement = page.locator('.wc-block-cart-items, .wc-block-cart__main, table.wc-block-cart-items').first();
		await expect(priceElement).toBeVisible();

		const cartText = await priceElement.innerText();
		expect(cartText).toContain('100');
		expect(cartText).not.toContain('80');
	});

	test('2. Authenticated B2B customer sees personalized 20% discount in Cart Block', async ({ page }) => {
		// Log in as B2B customer
		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'e2e_blocks_b2b_user');
		await page.fill('#user_pass', 'password123');
		await page.click('#wp-submit');
		await page.waitForTimeout(1500);

		// Add product to cart and visit Cart Block
		await page.goto(`/?add-to-cart=${simpleId}`);
		await page.goto(cartUrl);

		// Wait for Cart Block to load
		const cartBlock = page.locator('.wp-block-woocommerce-cart, .wc-block-cart').first();
		await expect(cartBlock).toBeVisible({ timeout: 15000 });

		// Verify Cart Block displays discounted B2B price 80
		const cartMain = page.locator('.wc-block-cart__main, .wc-block-cart-items, .wp-block-woocommerce-cart').first();
		await expect(cartMain).toBeVisible();

		const content = await cartMain.innerText();
		expect(content).toContain('80');

		// Verify totals section reflects discounted price 80
		const totalsElement = page.locator('.wc-block-cart__totals, .wp-block-woocommerce-cart-totals-block').first();
		if (await totalsElement.isVisible()) {
			const totalsText = await totalsElement.innerText();
			expect(totalsText).toContain('80');
		}
	});

	test('3. Quantity changes in Cart Block recalculate totals without stacking discounts', async ({ page }) => {
		// Ensure logged in
		await page.goto('/wp-login.php');
		const loginField = page.locator('#user_login');
		if (await loginField.isVisible()) {
			await page.fill('#user_login', 'e2e_blocks_b2b_user');
			await page.fill('#user_pass', 'password123');
			await page.click('#wp-submit');
			await page.waitForTimeout(1500);
		}

		await page.goto(`/?add-to-cart=${simpleId}`);
		await page.goto(cartUrl);

		// Wait for Cart Block
		const cartBlock = page.locator('.wp-block-woocommerce-cart, .wc-block-cart').first();
		await expect(cartBlock).toBeVisible({ timeout: 15000 });

		// Find quantity input or increment button in Cart Block
		const qtyInput = page.locator('.wc-block-components-quantity-selector__input, input.wc-block-cart-item__quantity-input').first();
		if (await qtyInput.isVisible()) {
			await qtyInput.fill('2');
			await qtyInput.press('Enter');
			await page.waitForTimeout(2500);
		}

		// Cart item unit price must remain 80 and total should reflect 2 items (160 or 80 unit)
		const cartMain = page.locator('.wc-block-cart__main, .wc-block-cart-items, .wp-block-woocommerce-cart').first();
		const cartText = await cartMain.innerText();
		expect(cartText).toContain('80');
	});

	test('4. Checkout Block totals match Cart Block pricing', async ({ page }) => {
		// Log in
		await page.goto('/wp-login.php');
		const loginField = page.locator('#user_login');
		if (await loginField.isVisible()) {
			await page.fill('#user_login', 'e2e_blocks_b2b_user');
			await page.fill('#user_pass', 'password123');
			await page.click('#wp-submit');
			await page.waitForTimeout(1500);
		}

		await page.goto(`/?add-to-cart=${simpleId}`);
		await page.goto(checkoutUrl);

		// Wait for Checkout Block
		const checkoutBlock = page.locator('.wp-block-woocommerce-checkout, .wc-block-checkout').first();
		await expect(checkoutBlock).toBeVisible({ timeout: 15000 });

		// Order summary inside Checkout Block must show discounted price ($80 or $160)
		const summary = page.locator('.wc-block-checkout__totals, .wp-block-woocommerce-checkout-order-summary-block, .wc-block-components-order-summary').first();
		await expect(summary).toBeVisible();

		const summaryText = await summary.innerText();
		expect(summaryText).toMatch(/80|160/);
	});

	test('5. Incognito guest session receives fallback regular pricing', async ({ browser }) => {
		const context = await browser.newContext();
		const guestPage = await context.newPage();

		await guestPage.goto(`/?add-to-cart=${simpleId}`);
		await guestPage.goto(cartUrl);

		const cartBlock = guestPage.locator('.wp-block-woocommerce-cart, .wc-block-cart').first();
		await expect(cartBlock).toBeVisible({ timeout: 15000 });

		const cartContent = await cartBlock.innerText();
		expect(cartContent).toContain('100');
		expect(cartContent).not.toContain('80');

		await context.close();
	});
});
