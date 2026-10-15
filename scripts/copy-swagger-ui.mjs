#!/usr/bin/env node
// Copies the locally installed swagger-ui-dist assets to public/vendor/swagger-ui so /docs works offline
// (no CDN). Run after `npm install`:  node scripts/copy-swagger-ui.mjs
import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const src = join(root, 'node_modules', 'swagger-ui-dist');
const dest = join(root, 'public', 'vendor', 'swagger-ui');
const files = ['swagger-ui.css', 'swagger-ui-bundle.js', 'swagger-ui-standalone-preset.js', 'favicon-32x32.png', 'favicon-16x16.png', 'oauth2-redirect.html', 'LICENSE', 'NOTICE'];

mkdirSync(dest, { recursive: true });
for (const f of files) {
    copyFileSync(join(src, f), join(dest, f));
}
const version = JSON.parse(readFileSync(join(src, 'package.json'), 'utf8')).version;
writeFileSync(join(dest, 'VERSION'), version + '\n');
console.log(`swagger-ui-dist ${version} → public/vendor/swagger-ui (${files.length} files)`);
