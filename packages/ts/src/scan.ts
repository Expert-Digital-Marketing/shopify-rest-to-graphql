import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, extname } from 'node:path';
import { detectInSource } from './detect.js';
import { findRule } from './path-pattern.js';
import type { Finding, MappingRule, RestCallSite } from './types.js';

const SOURCE_EXTENSIONS = new Set(['.ts', '.tsx', '.mts', '.cts', '.js', '.jsx', '.mjs', '.cjs']);

const SKIPPED_DIRECTORIES = new Set([
  'node_modules',
  '.git',
  'dist',
  'build',
  'coverage',
  '.next',
  '.nuxt',
  '.svelte-kit',
  'vendor',
]);

export interface ScanOptions {
  /** Extra directory names to skip, on top of the defaults. */
  readonly exclude?: readonly string[];
  /** Cap on file size in bytes. Bundles and minified output are noise. */
  readonly maxFileBytes?: number;
}

const DEFAULT_MAX_FILE_BYTES = 1_000_000;

function* walk(root: string, exclude: ReadonlySet<string>): Generator<string> {
  const entries = readdirSync(root, { withFileTypes: true });
  for (const entry of entries) {
    const full = join(root, entry.name);
    if (entry.isDirectory()) {
      if (exclude.has(entry.name)) continue;
      yield* walk(full, exclude);
    } else if (entry.isFile() && SOURCE_EXTENSIONS.has(extname(entry.name))) {
      yield full;
    }
  }
}

/** Every REST call in a directory tree, already matched against the rules. */
export function scanDirectory(
  root: string,
  rules: readonly MappingRule[],
  options: ScanOptions = {},
): Finding[] {
  const exclude = new Set([...SKIPPED_DIRECTORIES, ...(options.exclude ?? [])]);
  const maxBytes = options.maxFileBytes ?? DEFAULT_MAX_FILE_BYTES;
  const findings: Finding[] = [];

  for (const file of walk(root, exclude)) {
    if (statSync(file).size > maxBytes) continue;
    const source = readFileSync(file, 'utf8');
    // Cheap rejection before paying for a parse. Every detector needs one of
    // these strings to fire, so a file without them cannot produce a finding.
    if (!source.includes('/admin/') && !source.includes('rest') && !source.includes('path:')) continue;

    for (const call of detectInSource(relative(root, file), source)) {
      findings.push(toFinding(call, rules));
    }
  }

  return findings;
}

/** Scan a single file's text, for tests and for editor integrations. */
export function scanSource(
  fileName: string,
  source: string,
  rules: readonly MappingRule[],
): Finding[] {
  return detectInSource(fileName, source).map((call) => toFinding(call, rules));
}

function toFinding(call: RestCallSite, rules: readonly MappingRule[]): Finding {
  const rule = findRule(rules, call);
  return rule === undefined ? { call } : { call, rule };
}
