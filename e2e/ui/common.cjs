const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:18080';
let failures = 0;

function ok(label, condition) {
  console.log((condition ? '  \x1b[32mPASS\x1b[0m ' : '  \x1b[31mFAIL\x1b[0m ') + label);
  if (!condition) failures++;
}

async function session(title, email = 'kunde@e2e.test') {
  console.log('\n\x1b[1m' + title + '\x1b[0m');
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
  const errors = [];
  // wire:confirm dialogs are always confirmed.
  page.on('dialog', (d) => d.accept().catch(() => {}));
  page.on('pageerror', (e) => errors.push(page.url() + ' :: ' + e.message));
  page.on('response', (r) => { if (r.status() >= 500) errors.push(r.status() + ' ' + r.url()); });

  await page.goto(BASE + '/login');
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', 'e2e-password');
  await page.click('button:has-text("Sign in")');
  await page.waitForURL((u) => !u.toString().includes('/login'), { timeout: 15000 }).catch(() => {});
  ok(email + ' logged in', !page.url().includes('/login'));

  const settle = () => page.waitForLoadState('networkidle').then(() => page.waitForTimeout(800));
  const finish = async (ignoreError = () => false) => {
    const relevant = errors.filter((e) => !ignoreError(e));
    ok('no JavaScript errors or HTTP 5xx' + (relevant.length ? ': ' + JSON.stringify(relevant) : ''), relevant.length === 0);
    await browser.close();
    process.exit(failures);
  };

  return { page, settle, finish, BASE };
}

module.exports = { ok, session };
