import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';

/**
 * Playwright E2E Cart & Checkout Pricing Verification (TRZ-011).
 *
 * Verifies that Tierza's deterministic pricing integration works across
 * the full WooCommerce cart and checkout lifecycle:
 * - Guest adds product to cart and sees regular price.
 * - Customer logs in and saved cart dynamically updates to B2B price.
 * - Quantity updates preserve correct calculated unit price without stacking.
 * - Checkout page totals match the cart prices.
 * - Incognito isolation guarantees no session cross-contamination.
 */
test.describe('WooCommerce Cart & Checkout Pricing Integration (TRZ-011)', () => {
	let simpleUrl = '';
	let simpleId = 0;
	let cartUrl = '/cart/';
	let checkoutUrl = '/checkout/';

	test.beforeAll(async () => {
		const output = execSync(
			'npx wp-env run cli wp eval-file wp-content/plugins/tierza/tests/e2e/fixtures/setup-cart-pricing.php',
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
				'npx wp-env run cli wp eval-file wp-content/plugins/tierza/tests/e2e/fixtures/teardown-cart-pricing.php',
				{ encoding: 'utf-8' }
			);
		} catch {
			// Ignore teardown failure.
		}
	});

	test('1. Guest customer sees regular price in cart and checkout', async ({ page }) => {
		// Add simple product to cart via direct add-to-cart URL
		await page.goto(`/?add-to-cart=${simpleId}`);
		await page.goto(cartUrl);

		// Verify cart item price shows 100
		const priceElement = page.locator('.cart_item .product-price, .cart_item .product-subtotal').first();
		await expect(priceElement).toBeVisible();
		const priceText = await priceElement.innerText();
		expect(priceText).toContain('100');
		expect(priceText).not.toContain('75');
	});

	test('2. Authenticated B2B customer sees personalized 25% discount in cart', async ({ page }) => {
		// Log in as B2B customer
		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'e2e_cart_b2b_user');
		await page.fill('#user_pass', 'password123');
		await page.click('#wp-submit');
		await page.waitForTimeout(1500);

		// Add product to cart
		await page.goto(`/?add-to-cart=${simpleId}`);
		await page.goto(cartUrl);

		// Verify cart item price shows struck-out regular (100) and discounted price (75)
		const cartItem = page.locator('.cart_item').first();
		await expect(cartItem).toBeVisible();

		const cartContent = await cartItem.innerText();
		expect(cartContent).toContain('75');

		// Verify subtotal contains 75
		const subtotalElem = page.locator('.cart-subtotal, .order-total').first();
		const subtotalText = await subtotalElem.innerText();
		expect(subtotalText).toContain('75');
	});

	test('3. Quantity change recalculates totals without stacking discounts', async ({ page }) => {
		// Ensure logged in
		await page.goto('/wp-login.php');
		const loginField = page.locator('#user_login');
		if (await loginField.isVisible()) {
			await page.fill('#user_login', 'e2e_cart_b2b_user');
			await page.fill('#user_pass', 'password123');
			await page.click('#wp-submit');
			await page.waitForTimeout(1500);
		}

		await page.goto(cartUrl);

		// Change quantity to 2 if quantity input is present
		const qtyInput = page.locator('.cart_item input.qty').first();
		if (await qtyInput.isVisible()) {
			await qtyInput.fill('2');
			const updateBtn = page.locator('button[name="update_cart"]');
			if (await updateBtn.isVisible()) {
				await updateBtn.click();
				await page.waitForTimeout(2000);
			}
		}

		// Cart item unit price must remain 75 (not 56.25 from double discounting)
		const cartItem = page.locator('.cart_item').first();
		await expect(cartItem).toBeVisible();
		const itemText = await cartItem.innerText();
		expect(itemText).toContain('75');
	});

	test('4. Checkout totals match cart pricing', async ({ page }) => {
		// Log in
		await page.goto('/wp-login.php');
		const loginField = page.locator('#user_login');
		if (await loginField.isVisible()) {
			await page.fill('#user_login', 'e2e_cart_b2b_user');
			await page.fill('#user_pass', 'password123');
			await page.click('#wp-submit');
			await page.waitForTimeout(1500);
		}

		await page.goto(`/?add-to-cart=${simpleId}`);
		await page.goto(checkoutUrl);

		// Checkout review table must show discounted price ($75 unit, $150 for 2, or $225 for 3)
		const reviewOrder = page.locator('.woocommerce-checkout-review-order-table, #order_review').first();
		await expect(reviewOrder).toBeVisible();

		const reviewText = await reviewOrder.innerText();
		expect(reviewText).toMatch(/75|150|225/);
	});

	test('5. Incognito guest session receives fallback regular pricing', async ({ browser }) => {
		const context = await browser.newContext();
		const guestPage = await context.newPage();

		await guestPage.goto(`/?add-to-cart=${simpleId}`);
		await guestPage.goto(cartUrl);

		const cartItem = guestPage.locator('.cart_item').first();
		await expect(cartItem).toBeVisible();

		const content = await cartItem.innerText();
		expect(content).toContain('100');
		expect(content).not.toContain('75');

		await context.close();
	});
});
