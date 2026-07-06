// Minimal static file server for the Playwright smoke. Rooted at the canvas
// MODULE directory so both /app/e2e/fixtures/* and the built /web/js/dist/*
// bundle are reachable by absolute path from the mock admin page. POSTs to the
// mock save/validate endpoints are answered 200 so the smoke can assert on the
// intercepted request (via page.route) without a running Magento.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const moduleRoot = normalize(join(fileURLToPath(import.meta.url), '../../..')); // app/e2e -> module
const port = Number(process.env.PORT || 4173);

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
};

const server = createServer(async (req, res) => {
  if (req.method === 'POST') {
    // Mock save/validate/options endpoints — the smoke asserts on the request.
    res.writeHead(200, { 'content-type': 'text/html' });
    res.end('<!doctype html><title>ok</title>ok');
    return;
  }
  const url = new URL(req.url || '/', `http://127.0.0.1:${port}`);
  const rel = normalize(decodeURIComponent(url.pathname)).replace(/^(\.\.[/\\])+/, '');
  const filePath = join(moduleRoot, rel);
  if (!filePath.startsWith(moduleRoot)) {
    res.writeHead(403).end('forbidden');
    return;
  }
  try {
    const body = await readFile(filePath);
    res.writeHead(200, { 'content-type': TYPES[extname(filePath)] || 'application/octet-stream' });
    res.end(body);
  } catch {
    res.writeHead(404).end('not found');
  }
});

server.listen(port, '127.0.0.1', () => {
  // eslint-disable-next-line no-console
  console.log(`smoke server on http://127.0.0.1:${port} (root ${moduleRoot})`);
});
