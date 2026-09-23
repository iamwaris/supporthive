/**
 * Copies front-end libraries and fonts from node_modules into public/assets so
 * the browser loads everything same-origin.
 *
 * No CDN, and no Google Fonts <link>: the application's own CSP is
 * `style-src 'self'` and `font-src 'self'`, so a stylesheet or font file from
 * another origin is blocked outright. Self-hosting is not a preference here,
 * it is the only thing that works.
 *
 * Run: npm install && npm run vendor
 */
import { mkdir, copyFile, access } from 'node:fs/promises';
import { dirname, resolve, basename } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const assets = resolve(root, 'public/assets');

const libraries = [
  ['node_modules/alpinejs/dist/cdn.min.js', 'vendor/alpine.min.js'],
  ['node_modules/lucide/dist/umd/lucide.min.js', 'vendor/lucide.min.js'],
  ['node_modules/apexcharts/dist/apexcharts.min.js', 'vendor/apexcharts.min.js'],
  ['node_modules/apexcharts/dist/apexcharts.css', 'vendor/apexcharts.css'],
];

// Only the weights the design actually uses — every extra file is bytes the
// browser downloads and a face nobody asked for.
const fonts = [
  ['@fontsource/space-grotesk', ['600', '700']],
  ['@fontsource/ibm-plex-sans', ['400', '500', '600']],
  ['@fontsource/ibm-plex-mono', ['400', '500', '600']],
];

async function copyInto(from, to) {
  const src = resolve(root, from);
  try {
    await access(src);
  } catch {
    console.warn(`skip (not installed): ${from}`);
    return false;
  }
  const dest = resolve(assets, to);
  await mkdir(dirname(dest), { recursive: true });
  await copyFile(src, dest);
  console.log(`vendored ${to}`);
  return true;
}

await mkdir(assets, { recursive: true });

for (const [from, to] of libraries) {
  await copyInto(from, to);
}

for (const [pkg, weights] of fonts) {
  const family = pkg.replace('@fontsource/', '');
  for (const weight of weights) {
    const file = `${family}-latin-${weight}-normal.woff2`;
    await copyInto(`node_modules/${pkg}/files/${file}`, `fonts/${basename(file)}`);
  }
}
