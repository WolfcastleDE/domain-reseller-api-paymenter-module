// Public domain search page → checkout with the domain pre-filled.
const { ok, session } = require('./common.cjs');

(async () => {
  const { page, finish, BASE } = await session('Domain search (browser)');
  const name = `search${Date.now()}`;

  await page.goto(BASE + '/');
  await page.waitForLoadState('networkidle');
  ok('navigation links to the domain search', (await page.locator('a[href*="/domain-search"]').count()) > 0);

  await page.goto(BASE + '/domain-search');
  await page.fill('input[name=query]', name + '.de');
  await page.click('button:has-text("Search")');
  await page.waitForSelector('table', { timeout: 15000 }).catch(() => {});
  const rows = await page.locator('tbody tr').allInnerTexts();
  ok('results for all offered TLDs: ' + rows.map((r) => r.split('\t')[0]).join(', '), rows.length >= 3);
  ok('typed TLD listed first and available', rows[0].startsWith(name + '.de') && rows[0].includes('Available'));
  await page.screenshot({ path: '/artifacts/search.png', fullPage: true });

  await page.click('tbody tr >> nth=0 >> a:has-text("Order")');
  await page.waitForURL(/checkout/, { timeout: 15000 });
  await page.waitForLoadState('networkidle');
  ok('checkout opened with the domain pre-filled', (await page.inputValue('input[name="checkoutConfig.domain"]')) === name + '.de');

  await finish();
})();
