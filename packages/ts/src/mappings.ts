import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { MappingFile, MappingRule } from './types.js';

const HTTP_METHODS = new Set(['GET', 'POST', 'PUT', 'DELETE']);
const STATUSES = new Set(['direct', 'partial', 'manual']);

class MappingError extends Error {
  constructor(file: string, detail: string) {
    super(`${file}: ${detail}`);
    this.name = 'MappingError';
  }
}

function assertRule(file: string, value: unknown, index: number): MappingRule {
  const where = `rules[${index}]`;
  if (typeof value !== 'object' || value === null) {
    throw new MappingError(file, `${where} is not an object`);
  }
  const rule = value as Record<string, unknown>;

  if (typeof rule['id'] !== 'string') throw new MappingError(file, `${where}.id is missing`);
  if (typeof rule['docs'] !== 'string') throw new MappingError(file, `${where}.docs is missing`);

  const status = rule['status'];
  if (typeof status !== 'string' || !STATUSES.has(status)) {
    throw new MappingError(file, `${where}.status must be direct, partial or manual`);
  }

  const rest = rule['rest'];
  if (typeof rest !== 'object' || rest === null) throw new MappingError(file, `${where}.rest is missing`);
  const restRecord = rest as Record<string, unknown>;
  if (typeof restRecord['method'] !== 'string' || !HTTP_METHODS.has(restRecord['method'])) {
    throw new MappingError(file, `${where}.rest.method is not an HTTP method`);
  }
  if (typeof restRecord['path'] !== 'string') throw new MappingError(file, `${where}.rest.path is missing`);

  const graphql = rule['graphql'];
  if (status === 'manual') {
    if (graphql !== undefined) {
      throw new MappingError(file, `${where} is manual, so it must not carry a graphql operation`);
    }
  } else {
    if (typeof graphql !== 'object' || graphql === null) {
      throw new MappingError(file, `${where}.graphql is required unless the status is manual`);
    }
    const op = graphql as Record<string, unknown>;
    const rootField = op['rootField'];
    const document = op['document'];
    if (typeof rootField !== 'string') throw new MappingError(file, `${where}.graphql.rootField is missing`);
    if (typeof document !== 'string') throw new MappingError(file, `${where}.graphql.document is missing`);

    // The document has to actually call the field the rule advertises, or the
    // report tells a reader one thing and hands them another.
    if (!new RegExp(`\\b${rootField}\\s*\\(`).test(document)) {
      throw new MappingError(file, `${where}.graphql.document never calls ${rootField}`);
    }
  }

  return rule as unknown as MappingRule;
}

function assertMappingFile(file: string, value: unknown): MappingFile {
  if (typeof value !== 'object' || value === null) throw new MappingError(file, 'is not an object');
  const record = value as Record<string, unknown>;
  if (typeof record['resource'] !== 'string') throw new MappingError(file, 'resource is missing');
  const rules = record['rules'];
  if (!Array.isArray(rules) || rules.length === 0) throw new MappingError(file, 'rules is empty');

  return {
    resource: record['resource'],
    rules: rules.map((rule, index) => assertRule(file, rule, index)),
  };
}

/**
 * Read every mapping file in a directory.
 *
 * Validation is deliberate rather than delegated to a schema library: the
 * failure messages name the rule and the field, which is what you want when a
 * mapping is being edited by hand.
 */
export function loadMappings(directory: string): MappingFile[] {
  const files = readdirSync(directory)
    .filter((name) => name.endsWith('.json') && name !== 'schema.json')
    .sort();

  return files.map((name) => {
    const path = join(directory, name);
    const parsed: unknown = JSON.parse(readFileSync(path, 'utf8'));
    return assertMappingFile(name, parsed);
  });
}

/** Flatten loaded files into one ordered rule list. */
export function allRules(files: readonly MappingFile[]): MappingRule[] {
  return files.flatMap((file) => [...file.rules]);
}
