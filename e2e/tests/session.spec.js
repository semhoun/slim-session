import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

const apiOrigin = 'http://localhost:8787';
const widgetOrigin = 'http://localhost:8788';

async function open(page, mode, distribution = 'standalone') {
  if (distribution === 'esm') {
    const esm = await readFile(new URL('../../browser/dist/index.js', import.meta.url), 'utf8');
    await page.route('**/assets/e2e-index.js', route => route.fulfill({ contentType: 'text/javascript', body: esm }));
  }
  await page.goto(`${mode === 'cookie' ? apiOrigin : widgetOrigin}/${mode === 'cookie' ? 'cookie' : 'widget'}.html`);
  await expect(page.locator('#state')).toContainText('unauthenticated');
  if (distribution === 'esm') await page.evaluate(async () => { window.SlimSession = await import('/assets/e2e-index.js'); });
}

async function login(page) {
  await page.locator('[data-action="login"]').click();
  await expect(page.locator('#state')).toContainText('authenticated /');
  await expect(page.locator('#state')).not.toContainText('unauthenticated');
}

for (const mode of ['cookie', 'header']) {
  for (const distribution of ['standalone', 'esm']) {
  test(`${mode} ${distribution}: login, parallel writes, refresh, rotation and confirmed logout`, async ({ page, context }) => {
    await open(page, mode, distribution);
    const credentials = [];
    page.on('request', request => {
      if (request.url().includes(`/api/${mode}/data`)) credentials.push(request.headers());
    });
    await login(page);

    const writes = await page.evaluate(async () => Promise.all(Array.from({ length: 6 }, async () => {
      const response = await client.request('data', { method: 'POST' });
      return { status: response.status, body: await response.json() };
    })));
    expect(writes.map(value => value.status)).toEqual(Array(6).fill(200));
    expect(writes.map(value => value.body.visits).sort((a, b) => a - b)).toEqual([1, 2, 3, 4, 5, 6]);
    expect(credentials).toHaveLength(6);
    for (const headers of credentials) {
      if (mode === 'cookie') {
        expect(headers['x-csrf-token']).toBeTruthy();
        expect(headers.authorization).toBeUndefined();
      } else {
        expect(headers.authorization).toMatch(/^Bearer /);
        expect(headers.cookie).toBeUndefined();
      }
    }

    const refreshes = [];
    page.on('request', request => {
      if (request.url().endsWith(`/api/${mode}/session/refresh`)) refreshes.push(request);
    });
    const refreshed = await page.evaluate(async () => Promise.all([client.refresh(), client.refresh(), client.refresh()]));
    expect(refreshes).toHaveLength(1);
    expect(refreshed.every(value => value.status === 'authenticated')).toBe(true);

    if (mode === 'cookie') {
      const cookie = (await context.cookies(apiOrigin)).find(value => value.name === '__Host-example-session');
      expect(cookie).toBeDefined();
      expect(cookie.httpOnly).toBe(true);
      expect(cookie.secure).toBe(true);
      expect(cookie.path).toBe('/');
      expect(cookie.sameSite).toBe('Lax');
      expect(cookie.expires).toBeGreaterThan(Date.now() / 1000);
      expect(await page.evaluate(() => document.cookie)).not.toContain('__Host-example-session');
      const missingCsrf = await page.evaluate(async () => (await fetch('/api/cookie/data', { method: 'POST' })).status);
      expect(missingCsrf).toBe(403);
    } else {
      expect((await context.cookies()).filter(value => value.name === '__Host-example-session')).toEqual([]);
      expect(await page.evaluate(() => location.origin)).toBe(widgetOrigin);
    }

    const rotated = await page.evaluate(async () => {
      const response = await client.request('rotate', { method: 'POST' });
      const data = await client.request('data');
      return { status: response.status, dataStatus: data.status, data: await data.json() };
    });
    expect(rotated.status).toBe(200);
    expect(rotated.dataStatus).toBe(200);
    expect(rotated.data.visits).toBe(6);

    const replayHeaders = mode === 'cookie'
      ? { Cookie: (await context.cookies(apiOrigin)).map(cookie => `${cookie.name}=${cookie.value}`).join('; ') }
      : { Authorization: credentials.at(-1).authorization };
    await page.locator('[data-action="logout"]').click();
    await expect(page.locator('#state')).toHaveText('unauthenticated / revocation: confirmed');
    expect(await page.evaluate(async () => (await client.request('data')).status)).toBe(401);
    const replay = await context.request.get(`${apiOrigin}/api/${mode}/data`, { headers: replayHeaders });
    expect(replay.status()).toBe(401);
    if (mode === 'cookie') {
      expect((await context.cookies(apiOrigin)).find(value => value.name === '__Host-example-session')).toBeUndefined();
    }
  });
  }

  test(`${mode}: early-close stream permits concurrent writes and observes logout`, async ({ page }) => {
    await open(page, mode);
    await login(page);
    const result = await page.evaluate(async () => {
      const response = await client.request('stream');
      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let text = decoder.decode((await reader.read()).value);
      const start = performance.now();
      const mutation = await client.request('data', { method: 'POST' });
      const elapsed = performance.now() - start;
      const logout = await client.logout();
      while (true) {
        const chunk = await reader.read();
        if (chunk.done) break;
        text += decoder.decode(chunk.value);
      }
      return { status: response.status, type: response.headers.get('Content-Type'), mutation: mutation.status, elapsed, logout, text };
    });
    expect(result.status).toBe(200);
    expect(result.type).toContain('text/event-stream');
    expect(result.text).toContain('"valid":true');
    expect(result.mutation).toBe(200);
    // A held lease would block until the four-second stream ends (or time out at two seconds).
    expect(result.elapsed).toBeLessThan(1_800);
    expect(result.logout.revoked).toBe(true);
    expect(result.text).toContain('revoked-or-expired');
  });
}

test('two-origin iframe uses headers without borrowing the parent cookie', async ({ page }) => {
  await open(page, 'cookie');
  await login(page);
  const frame = page.frameLocator('#widget');
  await expect(frame.locator('#state')).toContainText('unauthenticated');
  await frame.locator('[data-action="login"]').click();
  await expect(frame.locator('#state')).toHaveText('authenticated / revocation: none');
  await frame.locator('[data-action="logout"]').click();
  await expect(frame.locator('#state')).toHaveText('unauthenticated / revocation: confirmed');
  expect(await page.evaluate(async () => (await client.request('data')).status)).toBe(200);
});

test('built ESM and standalone exports agree without patching fetch or starting requests', async ({ page }) => {
  const esm = await readFile(new URL('../../browser/dist/index.js', import.meta.url), 'utf8');
  await page.route('**/assets/e2e-index.js', route => route.fulfill({ contentType: 'text/javascript', body: esm }));
  await page.route('**/e2e-library-only.html', route => route.fulfill({
    contentType: 'text/html',
    body: '<!doctype html><html><head><script src="/assets/slim-session.js"></script></head><body></body></html>',
  }));
  await page.addInitScript(() => { window.originalFetch = window.fetch; });
  const apiRequests = [];
  page.on('request', request => { if (request.url().includes('/api/')) apiRequests.push(request.url()); });
  await page.goto(`${apiOrigin}/e2e-library-only.html`);
  await expect.poll(() => page.evaluate(() => typeof window.SlimSession?.createSessionClient)).toBe('function');
  const result = await page.evaluate(async () => {
    const module = await import('/assets/e2e-index.js');
    const options = {
      baseUrl: `${location.origin}/api/cookie/`, application: 'esm-test', transport: 'cookie',
      endpoints: { status: 'session/status', refresh: 'session/refresh', logout: 'session/logout' },
    };
    for (const library of [module, window.SlimSession]) library.createSessionClient(options).destroy();
    return { esm: Object.keys(module).sort(), standalone: Object.keys(window.SlimSession).sort(), sameFetch: window.originalFetch === window.fetch };
  });
  expect(result.esm).toEqual(result.standalone);
  expect(result.sameFetch).toBe(true);
  expect(apiRequests).toEqual([]);
});
