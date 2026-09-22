/**
 * Regenerates ../THIRD-PARTY-NOTICES.txt from package-lock.json + node_modules.
 *
 * The canvas ships a prebuilt bundle (view/adminhtml/web/js/dist/canvas.js) with
 * every dependency inlined, so those dependencies' licenses are redistributed
 * with this package and their notices have to travel with it — elkjs is
 * EPL-2.0, which also requires a source-availability statement. Attribution
 * therefore has to be regenerated whenever the lockfile moves, which is why
 * this is a script and not a hand-maintained file.
 *
 *   npm ci --ignore-scripts && npm run notices
 *
 * canvas.yml runs it and fails on drift. Packages are grouped by identical
 * license text so the file lists each text once. @types/* are skipped: they are
 * declarations only and contribute no code to the bundle. devDependencies are
 * skipped: build tooling is not redistributed.
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const APP = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(APP, '..', 'THIRD-PARTY-NOTICES.txt');
const WIDTH = 78;

const REPOS = {
  '@xyflow/react': 'https://github.com/xyflow/xyflow',
  '@xyflow/system': 'https://github.com/xyflow/xyflow',
  classcat: 'https://github.com/jorgebucaran/classcat',
  elkjs: 'https://github.com/kieler/elkjs',
  'js-tokens': 'https://github.com/lydell/js-tokens',
  'loose-envify': 'https://github.com/zertosh/loose-envify',
  react: 'https://github.com/facebook/react',
  'react-dom': 'https://github.com/facebook/react',
  scheduler: 'https://github.com/facebook/react',
  'use-sync-external-store': 'https://github.com/facebook/react',
  zustand: 'https://github.com/pmndrs/zustand',
};

const repoFor = (name) =>
  REPOS[name] ??
  (name.startsWith('d3-')
    ? `https://github.com/d3/${name}`
    : `https://www.npmjs.com/package/${name}`);

/** Greedy wrap that never splits on hyphens (package-lock.json must stay whole). */
function fill(text, width = WIDTH, indent = '') {
  const lines = [];
  let line = '';
  for (const word of text.split(/\s+/).filter(Boolean)) {
    const candidate = line ? `${line} ${word}` : word;
    if (candidate.length + indent.length > width && line) {
      lines.push(indent + line);
      line = word;
    } else {
      line = candidate;
    }
  }
  if (line) lines.push(indent + line);
  return lines;
}

function licenseTextFor(dir, name) {
  const match = readdirSync(join(APP, dir)).find((f) =>
    /^licen[cs]e/i.test(f)
  );
  if (!match) {
    throw new Error(`no license file shipped by ${name} — add attribution by hand`);
  }
  return readFileSync(join(APP, dir, match), 'utf8').trim();
}

const lock = JSON.parse(readFileSync(join(APP, 'package-lock.json'), 'utf8'));
const packages = [];
for (const [key, meta] of Object.entries(lock.packages)) {
  if (!key || meta.dev || meta.devOptional) continue;
  const name = key.replace('node_modules/', '');
  if (name.startsWith('@types/')) continue;
  const text = licenseTextFor(key, name);
  packages.push({
    name,
    version: meta.version,
    spdx: meta.license ?? 'UNKNOWN',
    repo: repoFor(name),
    text,
    hash: createHash('sha256').update(text).digest('hex'),
  });
}
packages.sort((a, b) => a.name.localeCompare(b.name));
if (!packages.length) {
  throw new Error('no production dependencies found — run npm ci first');
}

const elk = packages.find((p) => p.name === 'elkjs');
const out = [];
out.push('Third-party notices', '===================', '');
out.push(
  ...fill(
    'The mage-os/workflows-canvas package ships a prebuilt JavaScript bundle at ' +
      'view/adminhtml/web/js/dist/canvas.js (with its stylesheet canvas.css). The ' +
      'bundle is generated from app/src by Vite with no externals, so the ' +
      'third-party libraries listed below are compiled into it and are ' +
      'redistributed as part of this package.'
  ),
  ''
);
out.push(
  ...fill(
    'Those libraries are licensed by their respective copyright holders under the ' +
      'terms reproduced in this file, not under the OSL-3.0 that covers Mage-OS’s ' +
      'own code (see LICENSE.txt). Nothing here restricts the rights granted by ' +
      'those licenses, and nothing here grants rights in third-party code beyond ' +
      'what its own license grants.'
  ),
  ''
);
out.push(
  ...fill(
    'This list is the production dependency closure recorded in ' +
      'app/package-lock.json. @types/* packages are omitted: they carry TypeScript ' +
      'declarations only and contribute no code to the bundle. Build-time-only ' +
      'tooling (Vite, esbuild, TypeScript, Vitest, Playwright) is not ' +
      'redistributed and is likewise omitted. Regenerate with ' +
      '`npm run notices`; canvas.yml fails on drift.'
  ),
  '',
  ''
);

out.push('Bundled packages', '----------------', '');
const width = (pick) => Math.max(...packages.map((p) => pick(p).length));
const [nw, vw, sw] = [width((p) => p.name), width((p) => p.version), width((p) => p.spdx)];
for (const p of packages) {
  out.push(
    `  ${p.name.padEnd(nw)}  ${p.version.padEnd(vw)}  ${p.spdx.padEnd(sw)}  ${p.repo}`
  );
}
out.push('', '');

if (elk) {
  out.push(
    'Source availability (Eclipse Public License 2.0, section 3.2)',
    '------------------------------------------------------------',
    ''
  );
  out.push(
    ...fill(
      `elkjs ${elk.version} is EPL-2.0 licensed and is distributed here in ` +
        'minified (object code) form inside canvas.js. Its complete corresponding ' +
        `source code is publicly available from the upstream project at ${elk.repo} ` +
        `and from the npm registry as elkjs@${elk.version} ` +
        `(npm pack elkjs@${elk.version}). Mage-OS distributes elkjs unmodified; no ` +
        'changes were made to it.'
    ),
    '',
    ''
  );
}

out.push('License texts', '-------------', '');
out.push(
  ...fill(
    'Each license text below is reproduced verbatim from the corresponding ' +
      'package as published. Packages sharing an identical text are grouped.'
  )
);

const groups = new Map();
for (const p of packages) {
  if (!groups.has(p.hash)) groups.set(p.hash, { spdx: p.spdx, text: p.text, pkgs: [] });
  groups.get(p.hash).pkgs.push(p);
}
const ordered = [...groups.values()].sort(
  (a, b) => a.spdx.localeCompare(b.spdx) || a.pkgs[0].name.localeCompare(b.pkgs[0].name)
);
for (const group of ordered) {
  const names = group.pkgs.map((p) => `${p.name} ${p.version}`).join(', ');
  out.push('', '', '-'.repeat(WIDTH), '');
  out.push(`${group.spdx} -- applies to:`);
  out.push(...fill(names, WIDTH - 4, '    '));
  out.push('', group.text);
}
out.push('');

writeFileSync(OUT, out.join('\n') + '\n');
console.log(
  `THIRD-PARTY-NOTICES.txt: ${packages.length} packages, ${groups.size} distinct license texts`
);
