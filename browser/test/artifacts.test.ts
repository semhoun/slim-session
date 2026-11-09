import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createContext, runInContext } from 'node:vm';
import { expect, it } from 'vitest';
import * as source from '../src/index.js';

it('built ESM imports without any browser globals or side effects', () => {
  const output = execFileSync(process.execPath, ['--input-type=module', '-e', `
    delete globalThis.window;
    delete globalThis.document;
    delete globalThis.fetch;
    const api = await import('./dist/index.js');
    const client = api.createSessionClient({application:'ssr',baseUrl:'https://example.test/',transport:'header',endpoints:{status:'status',refresh:'refresh',logout:'logout'}});
    if(client.state.status !== 'uninitialized') throw Error('Eager initialization');
    client.destroy();
    process.stdout.write(Object.keys(api).sort().join(','));
  `], { encoding: 'utf8' });
  expect(output).toBe(Object.keys(source).sort().join(','));
});

it.each(['slim-session.js', 'slim-session.min.js'])('standalone %s exposes only the documented namespace without fetching', file => {
  const fetch = (): never => { throw Error('Unsolicited fetch'); };
  const sandbox = { fetch };
  const context = createContext(sandbox);
  runInContext(readFileSync(new URL(`../dist/${file}`, import.meta.url), 'utf8'), context);
  expect(Object.keys(sandbox).sort()).toEqual(['SlimSession', 'fetch']);
  const api = (sandbox as typeof sandbox & { SlimSession: typeof source }).SlimSession;
  expect(Object.keys(api).sort()).toEqual(Object.keys(source).sort());
  expect(sandbox.fetch).toBe(fetch);
});
