import { build } from 'esbuild';
import { execFileSync } from 'node:child_process';

execFileSync('tsc', ['-p', 'tsconfig.json'], { stdio: 'inherit' });
execFileSync('tsc', ['--noEmit', '--strict', '--module', 'NodeNext', '--target', 'ES2022', 'test/consumer.mts'], { stdio: 'inherit' });
for (const minify of [false, true]) {
  await build({
    entryPoints: ['src/index.ts'], bundle: true, format: 'iife',
    globalName: 'SlimSession', platform: 'browser', target: 'es2022',
    outfile: `dist/slim-session${minify ? '.min' : ''}.js`, minify,
    legalComments: 'none',
  });
}
