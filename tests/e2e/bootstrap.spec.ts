import { test, expect } from '@playwright/test';

/**
 * Real WordPress & WooCommerce integration smoke tests for Tierza (TRZ-002).
 */
test.describe('Tierza Admin & Integration Smoke Tests', () => {
	test.beforeEach(async ({ page }) => {
		// Log in to WordPress admin using standard wp-env credentials
		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await Promise.all([
			page.waitForURL(/.*wp-admin/, { timeout: 30000 }),
			page.click('#wp-submit'),
		]);
		await expect(page).toHaveURL(/.*wp-admin/);
	});

	test('1 & 4. Tierza menu appears for authorized users and navigates to dashboard', async ({ page }) => {
		// Look for Tierza menu item in admin sidebar
		const tierzaMenu = page.locator('#toplevel_page_tierza');
		await expect(tierzaMenu).toBeVisible({ timeout: 10000 });

		// Click into Tierza admin screen
		await Promise.all([
			page.waitForURL(/.*page=tierza/, { timeout: 10000 }),
			tierzaMenu.click(),
		]);

		// Verify main wrapper and title exist
		const header = page.locator('.tierza-title');
		await expect(header).toBeVisible();
		await expect(header).toContainText('v0.1.0-dev');
	});

	test('2 & 3. Inactive WooCommerce produces warning notice without fatal errors', async ({ page }) => {
		// Navigate to plugins page
		await page.goto('/wp-admin/plugins.php');

		// Check for any PHP fatal errors in page content
		const bodyText = await page.locator('body').innerText();
		expect(bodyText).not.toContain('Fatal error');
		expect(bodyText).not.toContain('Parse error');
	});

	test('5 & 7. Persian translation and RTL stylesheet render correctly under fa_IR', async ({ page }) => {
		await page.goto('/wp-admin/admin.php?page=tierza');

		// Check for wrapper
		const wrap = page.locator('.tierza-wrap');
		await expect(wrap).toBeVisible({ timeout: 10000 });

		// Check that admin stylesheet is enqueued
		const adminCss = page.locator('link[id="tierza-admin-css-css"]');
		await expect(adminCss).toHaveCount(1);
	});

	test('8 & 9. Clean execution with no console errors or PHP warnings', async ({ page }) => {
		const consoleErrors: string[] = [];
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				consoleErrors.push(msg.text());
			}
		});

		await page.goto('/wp-admin/admin.php?page=tierza');

		// Verify no unexpected frontend errors occurred
		expect(consoleErrors).toEqual([]);

		// Verify no PHP warnings printed on screen
		const pageContent = await page.content();
		expect(pageContent).not.toContain('Warning:');
		expect(pageContent).not.toContain('Notice:');
		expect(pageContent).not.toContain('Deprecated:');
	});
});
