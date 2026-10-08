#!/usr/bin/env node
// Creates the two Typesense keys a client e-shop needs, using the master key.
//
//   node tools/provision-site.mjs example_com           create keys
//   node tools/provision-site.mjs example_com --rotate  delete existing keys, create new ones
//
// Keys are scoped with an anchored regex to exactly one collection:
//   site   – collections:get/create/update + documents:*   (WordPress plugin, server side only)
//   search – documents:search                              (browser)
// No alias rights on purpose: an alias-capable key can create aliases with any name.
// Values are shown by Typesense only once, so they are written to infra/sites/<collection>.env (0600).

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const [collection, flag] = process.argv.slice(2);

if (!/^[a-z0-9_]+$/.test(collection ?? '')) {
  console.error('Usage: node tools/provision-site.mjs <collection> [--rotate]   (collection: a-z, 0-9, _)');
  process.exit(1);
}

const env = Object.fromEntries(
  readFileSync(join(root, 'infra/typesense/.env'), 'utf8')
    .split('\n')
    .filter((line) => line.includes('='))
    .map((line) => [line.slice(0, line.indexOf('=')), line.slice(line.indexOf('=') + 1).trim()]),
);
const host = process.env.TYPESENSE_HOST ?? env.TYPESENSE_HOST;
if (!host) {
  console.error('Set TYPESENSE_HOST (e.g. https://search.example.com) in the environment or in infra/typesense/.env');
  process.exit(1);
}

async function api(method, path, body) {
  const res = await fetch(host + path, {
    method,
    headers: { 'X-TYPESENSE-API-KEY': env.TYPESENSE_API_KEY, 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json();
  if (!res.ok) throw new Error(`${method} ${path} → ${res.status} ${data.message ?? ''}`);
  return data;
}

const scope = [`^${collection}$`];
const wanted = {
  site: {
    description: `nwr-search ${collection} site`,
    actions: ['collections:get', 'collections:create', 'collections:update', 'documents:*'],
    collections: scope,
  },
  search: {
    description: `nwr-search ${collection} search`,
    actions: ['documents:search'],
    collections: scope,
  },
};

const { keys } = await api('GET', '/keys');
const existing = keys.filter((key) => Object.values(wanted).some((w) => w.description === key.description));

if (existing.length && flag !== '--rotate') {
  console.error(`Keys for ${collection} already exist (ids ${existing.map((k) => k.id).join(', ')}). Use --rotate to replace them.`);
  process.exit(1);
}
for (const key of existing) {
  await api('DELETE', `/keys/${key.id}`);
}

const site = await api('POST', '/keys', wanted.site);
const search = await api('POST', '/keys', wanted.search);

const file = join(root, 'infra/sites', `${collection}.env`);
mkdirSync(dirname(file), { recursive: true });
writeFileSync(
  file,
  [
    `# Nowera Search keys for ${collection} — created ${new Date().toISOString()}`,
    `NWR_SEARCH_HOST=${host}`,
    `NWR_SEARCH_COLLECTION=${collection}`,
    `NWR_SEARCH_SITE_KEY=${site.value}`,
    `NWR_SEARCH_SEARCH_KEY=${search.value}`,
    '',
  ].join('\n'),
  { mode: 0o600 },
);

console.log(`Created site key #${site.id} and search key #${search.id} for ${collection}${existing.length ? ' (rotated)' : ''}.`);
console.log(`Saved to ${file}`);
