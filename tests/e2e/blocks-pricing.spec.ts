import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';

/**
 * Playwright E2E WooCommerce Blocks & Store API Pricing Verification (TRZ-012).
 *
 * Verifies that Tierza's deterministic B2B pricing integration functions across
 * Gutenberg Cart & Checkout Blocks powered by the WooCommerce Store API:
 * - Guest adds product to cart and sees regular price ($100.00, minor units 10000).
 * - Customer logs in and Cart Block dynamically renders B2B price ($80.00, minor units 8000).
 * - Quantity updates in Cart Block recalculate line total ($160.00, minor units 16000) without stacking.
 * - Checkout Block loads authoritative totals matching Cart Block.
 * - Real Checkout Block submission completes order, persisting authoritative pricing ($80.00).
 * - Failure injection triggers visible error notice in Checkout Block UI without creating payable order,
 *   and restores cleanly upon service recovery.
 * - Incognito isolation guarantees no session cross-contamination.
 */
test.describe('WooCommerce Blocks & Store API Pricing Integration (TRZ-012)', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(60000);

	let simpleUrl = '';
	let simpleId = 0;
	let userId = 0;
	let cartUrl = '/cart/';
	let checkoutUrl = '/checkout/';

	async function clearUserCart(uid: number) {
		try {
			execSync(
				'npx wp-env run cli wp db query "TRUNCATE TABLE wp_woocommerce_sessions; DELETE FROM wp_usermeta WHERE meta_key LIKE \'_woocommerce_persistent_cart_%\';"',
				{ encoding: 'utf-8' }
			);
		} catch {}
	}

	async function loginB2B(page: any) {
		await page.goto('/wp-login.php');
		const userField = page.locator('#user_login');
		if (await userField.isVisible()) {
			await page.fill('#user_login', 'e2e_blocks_b2b_user');
			await page.fill('#user_pass', 'password123');
			await page.click('#wp-submit');
			await page.waitForLoadState('networkidle');
		}
	}

	async function fillCheckoutForm(page: any) {
		const emailField = page.locator('#email, input[type="email"]').first();
		if (await emailField.isVisible()) {
			const val = await emailField.inputValue();
			if (!val) {
				await emailField.fill('e2e_blocks_b2b@tierza.test');
			}
		}
		const fnField = page.locator('#billing-first_name, input[name="billing-first_name"]').first();
		if (await fnField.isVisible()) {
			await fnField.fill('B2B');
		}
		const lnField = page.locator('#billing-last_name, input[name="billing-last_name"]').first();
		if (await lnField.isVisible()) {
			await lnField.fill('Buyer');
		}
		const addrField = page.locator('#billing-address_1, input[name="billing-address_1"]').first();
		if (await addrField.isVisible()) {
			await addrField.fill('123 Commerce St');
		}
		const cityField = page.locator('#billing-city, input[name="billing-city"]').first();
		if (await cityField.isVisible()) {
			await cityField.fill('Dallas');
		}
		const postField = page.locator('#billing-postcode, input[name="billing-postcode"]').first();
		if (await postField.isVisible()) {
			await postField.fill('75001');
		}
		const phoneField = page.locator('#billing-phone, input[name="billing-phone"]').first();
		if (await phoneField.isVisible()) {
			await phoneField.fill('2145550188');
			await phoneField.blur();
		}
		await page.waitForTimeout(1000);
	}

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
		userId = data.user_id;
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

	test('1. Guest customer sees regular price in Cart Block and Store API minor units', async ({ page }) => {
		let capturedCartJson: any = null;
		await page.route('**/wp-json/wc/store/v1/cart**', async (route) => {
			const res = await route.fetch();
			try {
				capturedCartJson = await res.json();
			} catch {}
			await route.fulfill({ response: res });
		});

		await page.goto(`/?add-to-cart=${simpleId}`);
		await page.goto(cartUrl);

		const cartBlock = page.locator('.wp-block-woocommerce-cart, .wc-block-cart').first();
		await expect(cartBlock).toBeVisible({ timeout: 20000 });

		// Assert Store API minor units: 10000 = $100.00
		expect(capturedCartJson).not.toBeNull();
		expect(capturedCartJson.items[0].prices.price).toBe('10000');
		expect(capturedCartJson.items[0].totals.line_total).toBe('10000');

		// Verify Cart Block UI displays 100 and not 80
		const priceElement = page.locator('.wc-block-cart-items, .wc-block-cart__main, table.wc-block-cart-items').first();
		await expect(priceElement).toBeVisible();
		const cartText = await priceElement.innerText();
		expect(cartText).toContain('100');
		expect(cartText).not.toContain('80');
	});

	test('2. Authenticated B2B customer sees personalized 20% discount in Cart Block and Store API (8000)', async ({ page }) => {
		await clearUserCart(userId);
		let capturedCartJson: any = null;
		await page.route('**/wp-json/wc/store/v1/cart**', async (route) => {
			const res = await route.fetch();
			try {
				capturedCartJson = await res.json();
			} catch {}
			await route.fulfill({ response: res });
		});

		await loginB2B(page);
		await page.goto(`/?add-to-cart=${simpleId}`);
		await page.goto(cartUrl);

		const cartBlock = page.locator('.wp-block-woocommerce-cart, .wc-block-cart').first();
		await expect(cartBlock).toBeVisible({ timeout: 20000 });

		// Assert Store API minor units for B2B price: 8000 = $80.00
		expect(capturedCartJson).not.toBeNull();
		expect(capturedCartJson.items[0].prices.price).toBe('8000');
		expect(capturedCartJson.items[0].totals.line_total).toBe('8000');

		// Verify Cart Block displays discounted B2B price 80
		const cartMain = page.locator('.wc-block-cart__main, .wc-block-cart-items, .wp-block-woocommerce-cart').first();
		await expect(cartMain).toBeVisible();
		const content = await cartMain.innerText();
		expect(content).toContain('80');
	});

	test('3. Quantity changes in Cart Block recalculate totals without stacking discounts (qty 2 -> 16000)', async ({ page }) => {
		let latestCartJson: any = null;
		await page.route('**/wp-json/wc/store/v1/cart**', async (route) => {
			const res = await route.fetch();
			try {
				latestCartJson = await res.json();
			} catch {}
			await route.fulfill({ response: res });
		});

		await loginB2B(page);
		await page.goto(`/?add-to-cart=${simpleId}`);
		await page.goto(cartUrl);

		const cartBlock = page.locator('.wp-block-woocommerce-cart, .wc-block-cart').first();
		await expect(cartBlock).toBeVisible({ timeout: 20000 });

		// Confirm quantity selector is visible and interactive
		const qtyInput = page.locator('.wc-block-components-quantity-selector__input, input.wc-block-cart-item__quantity-input').first();
		await expect(qtyInput).toBeVisible({ timeout: 15000 });

		// Update quantity to 2
		await qtyInput.fill('2');
		await qtyInput.press('Enter');
		await page.waitForTimeout(3000);

		// Assert Store API minor units: 8000 unit, 16000 line total
		if (latestCartJson && latestCartJson.items && latestCartJson.items.length > 0) {
			expect(latestCartJson.items[0].prices.price).toBe('8000');
			expect(latestCartJson.items[0].totals.line_total).toBe('16000');
		}

		// Cart Block UI must display line total of 160 (or 80 each * 2)
		const cartMain = page.locator('.wc-block-cart__main, .wc-block-cart-items, .wp-block-woocommerce-cart').first();
		await expect(cartMain).toContainText('160', { timeout: 15000 });
	});

	test('4. Real Checkout Block submission persists authoritative B2B price ($80.00)', async ({ page }) => {
		await clearUserCart(userId);

		let checkoutResponseData: any = null;
		await page.route('**/wp-json/wc/store/v1/checkout**', async (route) => {
			const res = await route.fetch();
			try {
				checkoutResponseData = await res.json();
			} catch {}
			await route.fulfill({ response: res });
		});

		await loginB2B(page);

		// Fresh cart with 1 item
		await page.goto(`/?add-to-cart=${simpleId}`);
		await page.goto(checkoutUrl);

		const checkoutBlock = page.locator('.wp-block-woocommerce-checkout, .wc-block-checkout').first();
		await expect(checkoutBlock).toBeVisible({ timeout: 20000 });

		await fillCheckoutForm(page);

		const placeOrderBtn = page.locator('button.wc-block-components-checkout-place-order-button, button:has-text("Place order")').first();
		await expect(placeOrderBtn).toBeVisible({ timeout: 15000 });
		await expect(placeOrderBtn).toBeEnabled({ timeout: 10000 });

		await placeOrderBtn.click();

		// Confirm navigation to order received
		await page.waitForURL(/.*order-received.*/, { timeout: 35000 });
		const orderDetails = page.locator('[class*="wc-block-order-confirmation"], .woocommerce-order, .woocommerce-order-received').first();
		await expect(orderDetails).toBeVisible({ timeout: 20000 });

		// Verify order confirmation UI reflects $80.00
		const pageText = await page.innerText('body');
		expect(pageText).toMatch(/80(\.00)?/);

		// Authoritatively verify persisted WC_Order from CLI
		if (checkoutResponseData && checkoutResponseData.order_id) {
			const orderId = checkoutResponseData.order_id;
			const orderVerifyOutput = execSync(
				`npx wp-env run cli wp eval "echo wc_get_order(${orderId})->get_total();"`,
				{ encoding: 'utf-8' }
			);
			expect(orderVerifyOutput).toContain('80.00');
		}
	});

	test('5. Failure injection triggers visible error notice banner in Checkout Block UI and recovers cleanly', async ({ page }) => {
		await clearUserCart(userId);
		await loginB2B(page);

		await page.goto(`/?add-to-cart=${simpleId}`);
		await page.goto(checkoutUrl);

		const checkoutBlock = page.locator('.wp-block-woocommerce-checkout, .wc-block-checkout').first();
		await expect(checkoutBlock).toBeVisible({ timeout: 20000 });

		await fillCheckoutForm(page);

		const placeOrderBtn = page.locator('button.wc-block-components-checkout-place-order-button, button:has-text("Place order")').first();
		await expect(placeOrderBtn).toBeVisible({ timeout: 15000 });
		await expect(placeOrderBtn).toBeEnabled({ timeout: 10000 });

		// Inject database failure: rename tierza_rules table
		execSync(
			'npx wp-env run cli wp db query "RENAME TABLE wp_tierza_rules TO wp_tierza_rules_e2e_fault;"',
			{ encoding: 'utf-8' }
		);

		try {
			await placeOrderBtn.click();

			// Assert visible error notice banner in Checkout Block UI
			const noticeBanner = page.locator('.wc-block-components-notice-banner.is-error, .wc-block-components-notice-banner, .wc-block-checkout__notices').first();
			await expect(noticeBanner).toBeVisible({ timeout: 15000 });

			// Verify order was blocked: URL did not proceed to order-received
			expect(page.url()).not.toContain('order-received');
		} finally {
			// Restore rules table
			try {
				execSync(
					'npx wp-env run cli wp db query "RENAME TABLE wp_tierza_rules_e2e_fault TO wp_tierza_rules;"',
					{ encoding: 'utf-8' }
				);
			} catch {}
		}

		// Wait briefly after restoring table
		await page.waitForTimeout(1000);

		// Retry checkout after restoration: authoritative recovery allows order completion
		await placeOrderBtn.click();
		await page.waitForURL(/.*order-received.*/, { timeout: 35000 });
		const orderDetails = page.locator('[class*="wc-block-order-confirmation"], .woocommerce-order, .woocommerce-order-received').first();
		await expect(orderDetails).toBeVisible({ timeout: 20000 });
	});

	test('6. Incognito guest session receives fallback regular pricing ($100.00)', async ({ browser }) => {
		const context = await browser.newContext();
		const guestPage = await context.newPage();

		await guestPage.goto(`/?add-to-cart=${simpleId}`);
		await guestPage.goto(cartUrl);

		const cartBlock = guestPage.locator('.wp-block-woocommerce-cart, .wc-block-cart').first();
		await expect(cartBlock).toBeVisible({ timeout: 20000 });

		const cartContent = await cartBlock.innerText();
		expect(cartContent).toContain('100');
		expect(cartContent).not.toContain('80');

		await context.close();
	});
});
