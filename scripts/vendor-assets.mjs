/**
 * Copies front-end libraries from node_modules into public/assets/vendor so the
 * browser loads them same-origin. No CDN is used: a CDN is a third party that
 * can serve different bytes tomorrow, and it forces the CSP open.
 *
 * Run: npm install && npm run vendor
 */
import { mkdir, copyFile, access } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const out = resolve(root, 'public/assets/vendor');

const files = [
  ['node_modules/alpinejs/dist/cdn.min.js', 'alpine.min.js'],
  ['node_modules/lucide/dist/umd/lucide.min.js', 'lucide.min.js'],
  ['node_modules/apexcharts/dist/apexcharts.min.js', 'apexcharts.min.js'],
  ['node_modules/apexcharts/dist/apexcharts.css', 'apexcharts.css'],
];

await mkdir(out, { recursive: true });

for (const [from, to] of files) {
  const src = resolve(root, from);
  try {
    await access(src);
  } catch {
    console.warn(`skip (not installed): ${from}`);
    continue;
  }
  const dest = resolve(out, to);
  await mkdir(dirname(dest), { recursive: true });
  await copyFile(src, dest);
  console.log(`vendored ${to}`);
}
