import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
const manifest = JSON.parse(readFileSync('dist/mix-manifest.json', 'utf8'));
for (const [file, versioned] of Object.entries(manifest)) {
  const bytes = readFileSync(`dist${file}`);
  assert.ok(bytes.length > 0);
  assert.equal(versioned.split('?id=')[1], createHash('md5').update(bytes).digest('hex'));
}
assert.ok(manifest['/js/tool.js']);
assert.ok(manifest['/css/tool.css']);
