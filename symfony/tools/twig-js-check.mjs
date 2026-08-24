#!/usr/bin/env node
/**
 * Syntax-check the inline JavaScript inside fork-owned Twig templates.
 *
 * These templates carry hundreds of lines of inline <script>. Nothing else in
 * the pipeline looks at it: `lint:twig` validates Twig, PHPUnit never executes
 * a browser, so a JavaScript syntax error ships green and the page renders its
 * static markup with every scripted feature silently dead. That is exactly how
 * a broken splice in the merged Calendar tab reached production: the chrome
 * drew, the grid never did.
 *
 * Twig expressions are replaced with syntactically valid placeholders before
 * parsing, so this checks the JavaScript around them, not the interpolated
 * values:
 *   {{ ... }}  ->  null      (they are json_encode'd values in practice)
 *   {% ... %}  ->  removed   (control flow, cannot be represented in JS)
 *
 * A template that interpolates Twig in a place where `null` is not valid JS
 * will report a false positive. Add it to SKIP with a reason rather than
 * weakening the substitution for everything.
 *
 * Usage: node tools/twig-js-check.mjs [root]
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const root = process.argv[2] ?? new URL('..', import.meta.url).pathname;

/** Fork-owned template directories. Upstream templates are not ours to fix. */
const DIRS = ['templates/discover', 'templates/decouverte'];

/**
 * Templates whose inline JS cannot be checked this way, and why.
 *
 * Only for genuine false positives from the substitution, never to silence a
 * real error. Each entry states what the template does that defeats it.
 *
 * @type {Record<string, string>}
 */
const SKIP = {
  // Builds a JS object literal whose KEYS come from a Twig for-loop:
  //   { {% for t in tabs %}{{ t }}: {{ path(...) }}{% endfor %} }
  // Substituting the expressions yields `null: nullnull`, which is not valid
  // JavaScript even though the rendered output is. Checking it would need the
  // loop expanded, which means rendering Twig, which is what this tool exists
  // to avoid.
  'templates/discover/index.html.twig':
    'Twig loop generates object-literal keys; substitution cannot represent it',
};

function walk(dir) {
  let out = [];
  let entries;
  try {
    entries = readdirSync(dir);
  } catch {
    return out;
  }
  for (const entry of entries) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      out = out.concat(walk(full));
    } else if (entry.endsWith('.twig')) {
      out.push(full);
    }
  }
  return out;
}

/** Every inline <script> body that is executable JavaScript. */
function scripts(source) {
  const out = [];
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/g;
  let m;
  while ((m = re.exec(source)) !== null) {
    const attrs = m[1] ?? '';
    // Data islands such as type="application/json" are not JavaScript.
    const type = /type\s*=\s*["']([^"']+)["']/.exec(attrs);
    if (type && !/javascript|module/i.test(type[1])) continue;
    if (/\bsrc\s*=/.test(attrs)) continue;
    out.push({ body: m[2], offset: source.slice(0, m.index).split('\n').length });
  }
  return out;
}

function stripTwig(js) {
  return js
    .replace(/\{\{[\s\S]*?\}\}/g, 'null')
    .replace(/\{%[\s\S]*?%\}/g, '');
}

let failures = 0;
let checked = 0;

for (const dir of DIRS) {
  for (const file of walk(join(root, dir))) {
    const rel = relative(root, file);
    if (SKIP[rel]) continue;

    const source = readFileSync(file, 'utf8');
    for (const { body, offset } of scripts(source)) {
      checked++;
      const candidate = stripTwig(body);
      try {
        // Function() parses without executing, which is what we want.
        new Function(candidate);
      } catch (err) {
        failures++;
        console.error(`\n  ${rel}  (script starting near line ${offset})`);
        console.error(`    ${err.message}`);
      }
    }
  }
}

if (failures > 0) {
  console.error(`\nInline JS check FAILED: ${failures} of ${checked} script blocks do not parse.\n`);
  process.exit(1);
}

console.log(`Inline JS check passed: ${checked} script blocks parse.`);
