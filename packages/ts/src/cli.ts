#!/usr/bin/env node
import { parseArgs } from 'node:util';
import { existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { allRules, loadMappings } from './mappings.js';
import { scanDirectory } from './scan.js';
import { summarise, toJson, toMarkdown, toText } from './report.js';

const USAGE = `shopify-rest-to-graphql <path> [options]

Finds Shopify REST Admin API calls and reports the GraphQL operation that
replaces each one. Nothing is written to your source; the output is a report.

Options
  --format <text|json|markdown>  Output format. Default text.
  --mappings <dir>               Mapping directory. Defaults to the bundled one.
  --exclude <name>               Directory name to skip. Repeatable.
  --fail-on <none|any|unmapped>  Exit non zero when calls are found. Default none.
  --help                         This text.
`;

function resolveDefaultMappings(): string {
  // Works both from source (src/) and from the published build (dist/).
  const here = dirname(fileURLToPath(import.meta.url));
  const candidates = [
    join(here, '..', 'mappings'),
    join(here, '..', '..', 'mappings'),
    join(here, '..', '..', '..', 'mappings'),
  ];
  const found = candidates.find((candidate) => existsSync(join(candidate, 'schema.json')));
  if (found === undefined) {
    throw new Error('Could not find the bundled mappings directory. Pass --mappings.');
  }
  return found;
}

function main(argv: readonly string[]): number {
  const { values, positionals } = parseArgs({
    args: [...argv],
    allowPositionals: true,
    options: {
      format: { type: 'string', default: 'text' },
      mappings: { type: 'string' },
      exclude: { type: 'string', multiple: true, default: [] },
      'fail-on': { type: 'string', default: 'none' },
      help: { type: 'boolean', default: false },
    },
  });

  if (values.help === true || positionals.length === 0) {
    process.stdout.write(USAGE);
    return values.help === true ? 0 : 2;
  }

  const target = resolve(positionals[0] ?? '.');
  if (!existsSync(target)) {
    process.stderr.write(`No such path: ${target}\n`);
    return 2;
  }

  const mappingDirectory = values.mappings ?? resolveDefaultMappings();
  const rules = allRules(loadMappings(mappingDirectory));
  const findings = scanDirectory(target, rules, { exclude: values.exclude });

  const format = values.format;
  if (format === 'json') process.stdout.write(`${toJson(findings)}\n`);
  else if (format === 'markdown') process.stdout.write(`${toMarkdown(findings)}\n`);
  else if (format === 'text') process.stdout.write(`${toText(findings)}\n`);
  else {
    process.stderr.write(`Unknown format: ${format}\n`);
    return 2;
  }

  const summary = summarise(findings);
  const failOn = values['fail-on'];
  if (failOn === 'any' && summary.total > 0) return 1;
  if (failOn === 'unmapped' && summary.unmapped > 0) return 1;
  return 0;
}

try {
  process.exitCode = main(process.argv.slice(2));
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
  process.exitCode = 2;
}
