// Drives every client-area tab of the domain service through the real Paymenter UI.
const fs = require('fs');
const { ok, session } = require('./common.cjs');

(async () => {
  const { page, settle, finish, BASE } = await session('Client area (browser)');
  const sid = fs.readFileSync('/shared/e2e_service_id', 'utf8').trim();
  const tab = async (name) => { await page.goto(`${BASE}/services/${sid}?tab=${name}`); await page.waitForLoadState('networkidle'); };
  const shot = (name) => page.screenshot({ path: `/artifacts/${name}.png`, fullPage: true });

  await tab('overview');
  ok('overview shows registry data', (await page.innerText('body')).includes('Domain details'));
  await shot('overview');

  await tab('dns');
  await page.selectOption('select[name=type]', 'TXT');
  await page.fill('input[name=name]', 'e2e');
  await page.fill('input[name=content]', 'hello-from-paymenter');
  await page.click('button:has-text("Add record") >> nth=-1');
  await settle();
  ok('DNS record created', (await page.innerText('table')).includes('hello-from-paymenter'));
  await page.click('tr:has-text("hello-from-paymenter") button[title=Edit]');
  await settle();
  await page.fill('input[name=content]', 'edited-value');
  await page.click('button:has-text("Save record")');
  await settle();
  ok('DNS record edited', (await page.innerText('table')).includes('edited-value'));
  await shot('dns');
  await page.click('tr:has-text("edited-value") button[title=Delete]');
  await settle();
  ok('DNS record deleted', !(await page.innerText('table')).includes('edited-value'));
  await page.selectOption('select[name=type]', 'MX');
  await settle();
  await page.fill('input[name=content]', 'mx.example.com');
  await page.click('button:has-text("Add record") >> nth=-1');
  await settle();
  ok('MX record without priority is rejected', (await page.innerText('body')).toLowerCase().includes('priority field is required'));

  // Zone file import / export
  await page.fill('textarea[name=zoneFile]', 'zone-import 3600 IN TXT "imported"');
  await page.click('button:has-text("Import")');
  await settle();
  ok('zone file imported', (await page.innerText('body')).includes('zone file has been imported'));
  const [download] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }).catch(() => null), page.click('button:has-text("Download zone file")')]);
  ok('zone file downloaded (' + (download ? download.suggestedFilename() : 'none') + ')', download !== null && download.suggestedFilename().endsWith('.zone'));

  await tab('nameservers');
  await page.fill('input[name="nameservers.0"]', 'not a hostname');
  await page.click('button:has-text("Save nameservers")');
  await settle();
  ok('invalid nameserver is rejected', (await page.innerText('body')).includes('not a valid nameserver hostname'));
  await page.fill('input[name="nameservers.0"]', 'ns1.example-dns.com');
  await page.fill('input[name="nameservers.1"]', 'ns2.example-dns.com');
  await page.click('button:has-text("Save nameservers")');
  await settle();
  ok('nameservers saved', (await page.innerText('body')).includes('uses custom nameservers'));
  await page.click('button:has-text("Switch to managed DNS")');
  await settle();
  // A local backend without BUNNY_DNS_API_KEY cannot create managed zones, even in sandbox mode.
  // Then the API error has to reach the customer as a notification.
  const managedText = await page.innerText('body');
  ok('switch to managed DNS: ' + (managedText.includes('uses our managed DNS') ? 'switched' : 'API error shown to the customer'),
    managedText.includes('uses our managed DNS') || managedText.includes('Bunny DNS not configured'));
  await shot('nameservers');

  await tab('contacts');
  await page.fill('input[name="contact.city"]', 'Hamburg');
  await page.click('button:has-text("Save contact data")');
  await settle();
  await tab('contacts');
  ok('owner contact updated at the registry', (await page.inputValue('input[name="contact.city"]')) === 'Hamburg');
  await shot('contacts');

  // Owner change (free in the sandbox → confirmation step)
  await page.click('button:has-text("Change owner")');
  await settle();
  const owner = { firstName: 'Max', lastName: 'Käufer', street: 'Neue Straße', houseNumber: '7', postalCode: '20095', city: 'Hamburg', phone: '+49 40 1234567', email: 'max@example.de' };
  for (const [field, value] of Object.entries(owner)) await page.fill(`input[name="newOwner.${field}"]`, value);
  await page.selectOption('select[name="newOwner.country"]', 'DE');
  await page.click('button:has-text("Continue")');
  await settle();
  ok('owner change asks for confirmation', (await page.innerText('body')).includes('free of charge'));
  await page.click('button:has-text("Confirm owner change")');
  await settle();
  ok('owner changed', (await page.innerText('body')).includes('The owner has been changed'));
  await shot('owner-change');

  await tab('dnssec');
  await page.click('button:has-text("Enable DNSSEC")');
  await settle();
  ok('DNSKEY required for external nameservers', (await page.innerText('body')).includes('at least one DNSKEY record'));
  await page.fill('textarea[name=dnskeys]', '257 3 13 mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ==');
  await page.click('button:has-text("Enable DNSSEC")');
  await settle();
  ok('DNSSEC enabled', (await page.innerText('body')).includes('Disable DNSSEC'));
  await shot('dnssec');

  await tab('transfer');
  await page.click('button:has-text("Show auth code")');
  await settle();
  ok('auth code revealed', (await page.locator('code').count()) === 1);
  await shot('auth-code');

  await finish();
})();
