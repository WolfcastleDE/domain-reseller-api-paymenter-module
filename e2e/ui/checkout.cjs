// Orders a domain through the real Paymenter checkout.
const fs = require('fs');
const { ok, session } = require('./common.cjs');

(async () => {
  const { page, finish, BASE } = await session('Checkout (browser)');
  const domain = `ui-${Date.now()}.de`;

  await page.goto(BASE + '/products/domains/domain-de/checkout');
  await page.waitForLoadState('networkidle');
  ok('no auth code field for registrations', (await page.locator('input[name="checkoutConfig.auth_code"]').count()) === 0);
  await page.selectOption('select[name="checkoutConfig.action"]', 'transfer');
  await page.waitForTimeout(1500);
  ok('auth code field appears for transfers', (await page.locator('input[name="checkoutConfig.auth_code"]').count()) === 1);
  await page.selectOption('select[name="checkoutConfig.action"]', 'register');
  await page.waitForTimeout(1500);

  await page.fill('input[name="checkoutConfig.domain"]', 'example.net');
  await page.waitForTimeout(800);
  await page.click('button:has-text("Checkout")');
  await page.waitForTimeout(2000);
  ok('wrong TLD is rejected', (await page.innerText('body')).includes('only supports the following extensions'));
  await page.screenshot({ path: '/artifacts/checkout.png', fullPage: true });

  await page.fill('input[name="checkoutConfig.domain"]', domain);
  await page.waitForTimeout(800);
  await page.click('button:has-text("Checkout")');
  await page.waitForURL((u) => u.toString().includes('/cart'), { timeout: 15000 }).catch(() => {});
  ok('domain added to the cart', page.url().includes('/cart'));
  await page.waitForLoadState('networkidle');
  const boxes = page.locator('input[type=checkbox]');
  for (let i = 0; i < (await boxes.count()); i++) await boxes.nth(i).check().catch(() => {});
  await page.click('button:has-text("Checkout")');
  await page.waitForURL((u) => u.toString().includes('/invoices/'), { timeout: 15000 }).catch(() => {});
  ok('order placed, invoice ' + page.url().replace(BASE, ''), page.url().includes('/invoices/'));
  fs.writeFileSync('/shared/e2e_checkout_domain', domain);

  // Paymenter core throws a Livewire component id while redirecting from the product page; not caused by the extension.
  await finish((e) => /\/checkout\?.* :: [A-Za-z0-9]{20}$/.test(e));
})();
