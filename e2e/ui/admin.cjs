// Checks that the admin forms generated from the extension config render and save.
const { ok, session } = require('./common.cjs');

(async () => {
  const { page, settle, finish, BASE } = await session('Admin area (browser)', 'admin@e2e.test');

  await page.goto(BASE + '/admin/servers');
  await page.waitForLoadState('networkidle');
  await page.click('tr:has-text("Domain Reseller API") a:has-text("Edit")');
  await page.waitForURL(/\/edit/);
  await page.waitForLoadState('networkidle');
  let body = await page.innerText('body');
  ok('server form shows extension settings', body.includes('API key') && body.includes('When a service is terminated') && body.includes('Webhook secret'));
  await page.screenshot({ path: '/artifacts/admin-server.png', fullPage: true });
  await page.click('text=Test Connection');
  await settle();
  ok('"Test Connection" in the admin UI succeeds', (await page.innerText('body')).includes('Configuration is correct'));

  await page.goto(BASE + '/admin/products');
  await page.waitForLoadState('networkidle');
  await page.click('tr:has-text(".de / .com Domain") a:has-text("Edit")');
  await page.waitForURL(/\/edit/);
  await page.waitForLoadState('networkidle');
  const serverTab = page.locator('[role=tab]:has-text("Server"), button:has-text("Server")').first();
  if (await serverTab.count()) await serverTab.click();
  await settle();
  body = await page.innerText('body');
  ok('product form shows extension settings', body.includes('Allowed TLDs') && body.includes('Nameservers for new registrations') && body.includes('Customers can manage DNS records'));
  await page.screenshot({ path: '/artifacts/admin-product.png', fullPage: true });
  await page.click('button:has-text("Save changes")');
  await settle();
  ok('product settings save', /saved/i.test(await page.innerText('body')));

  // Domains page
  await page.goto(BASE + '/admin/domain-reseller-api/domains');
  await page.waitForLoadState('networkidle');
  body = await page.innerText('body');
  ok('domains page lists domains', body.includes('Registry') && (await page.locator('tbody tr').count()) > 0);
  ok('wallet/sandbox info shown', body.includes('sandbox') || body.includes('wallet'));
  await page.screenshot({ path: '/artifacts/admin-domains.png', fullPage: true });

  const fs = require('fs');
  const domain = fs.readFileSync('/shared/e2e_checkout_domain', 'utf8').trim();
  const row = page.locator('tbody tr', { hasText: domain });
  const rowAction = async (label) => {
    await row.locator('.fi-dropdown-trigger').first().click();
    await page.waitForTimeout(500);
    await page.locator(`.fi-dropdown-list-item:visible`, { hasText: label }).first().click();
    await page.waitForTimeout(800);
    const confirm = page.locator('.fi-modal button:has-text("Confirm")');
    if (await confirm.count()) await confirm.first().click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1500);
  };
  await rowAction('Sync now');
  ok('row action "Sync now"', (await page.innerText('body')).includes('Status: active'));
  await rowAction('Show auth code');
  ok('row action "Show auth code"', (await page.innerText('body')).includes('Auth code:'));
  await rowAction('Approve outgoing transfer');
  ok('row action "Approve outgoing transfer"', (await page.innerText('body')).includes('Outgoing transfer approved'));

  await finish();
})();
