import type { Finding } from './types.js';

export interface Summary {
  readonly total: number;
  readonly direct: number;
  readonly partial: number;
  readonly manual: number;
  readonly unmapped: number;
}

export function summarise(findings: readonly Finding[]): Summary {
  let direct = 0;
  let partial = 0;
  let manual = 0;
  let unmapped = 0;

  for (const finding of findings) {
    if (finding.rule === undefined) unmapped += 1;
    else if (finding.rule.status === 'direct') direct += 1;
    else if (finding.rule.status === 'partial') partial += 1;
    else manual += 1;
  }

  return { total: findings.length, direct, partial, manual, unmapped };
}

/** Machine readable output, for CI and for piping into something else. */
export function toJson(findings: readonly Finding[]): string {
  return JSON.stringify(
    {
      summary: summarise(findings),
      findings: findings.map((finding) => ({
        file: finding.call.file,
        line: finding.call.line,
        column: finding.call.column,
        method: finding.call.method,
        path: finding.call.path,
        apiVersion: finding.call.apiVersion ?? null,
        detector: finding.call.detector,
        confidence: finding.call.confidence,
        rule: finding.rule?.id ?? null,
        status: finding.rule?.status ?? 'unmapped',
        rootField: finding.rule?.graphql?.rootField ?? null,
        notes: finding.rule?.notes ?? [],
        docs: finding.rule?.docs ?? null,
      })),
    },
    null,
    2,
  );
}

/** A report a person reads, grouped by file, in the order the calls appear. */
export function toMarkdown(findings: readonly Finding[]): string {
  const summary = summarise(findings);
  const lines: string[] = [];

  lines.push('# Shopify REST To GraphQL Report', '');
  const noun = summary.total === 1 ? 'REST call' : 'REST calls';
  const covered = summary.unmapped === 1 ? 'is not covered' : 'are not covered';
  lines.push(
    `${summary.total} ${noun} found. ` +
      `${summary.direct} map directly, ${summary.partial} need the call site changed, ` +
      `${summary.manual} need a decision, ${summary.unmapped} ${covered} by a rule.`,
    '',
  );

  const byFile = new Map<string, Finding[]>();
  for (const finding of findings) {
    const bucket = byFile.get(finding.call.file);
    if (bucket === undefined) byFile.set(finding.call.file, [finding]);
    else bucket.push(finding);
  }

  for (const [file, group] of [...byFile].sort(([a], [b]) => a.localeCompare(b))) {
    lines.push(`## ${file}`, '');
    for (const finding of group) {
      const { call, rule } = finding;
      lines.push(`### ${call.method} ${call.path} · line ${call.line}`, '');
      lines.push(`Found by \`${call.detector}\`, confidence ${call.confidence}.`, '');
      lines.push('```', call.evidence, '```', '');

      if (rule === undefined) {
        lines.push(
          'No rule covers this call. Check the GraphQL Admin API reference for the equivalent operation.',
          '',
        );
        continue;
      }

      if (rule.status === 'manual') {
        lines.push('No single operation replaces this call. It needs a decision.', '');
      } else if (rule.graphql !== undefined) {
        lines.push(`Replace with \`${rule.graphql.rootField}\`.`, '');
        lines.push('```graphql', rule.graphql.document, '```', '');
        if (rule.graphql.variables !== undefined) {
          lines.push('```json', JSON.stringify(rule.graphql.variables, null, 2), '```', '');
        }
      }

      for (const note of rule.notes ?? []) lines.push(`- ${note}`);
      if ((rule.notes ?? []).length > 0) lines.push('');
      lines.push(`Reference: ${rule.docs}`, '');
    }
  }

  return lines.join('\n');
}

const STATUS_LABEL: Record<string, string> = {
  direct: 'direct ',
  partial: 'partial',
  manual: 'manual ',
  unmapped: 'unknown',
};

/** Terminal output, one line per call, sized for a scroll back rather than a report. */
export function toText(findings: readonly Finding[]): string {
  const summary = summarise(findings);
  const lines = findings.map((finding) => {
    const status = finding.rule?.status ?? 'unmapped';
    const target = finding.rule?.graphql?.rootField ?? '-';
    const where = `${finding.call.file}:${finding.call.line}`;
    return `${STATUS_LABEL[status] ?? status}  ${finding.call.method.padEnd(6)} ${finding.call.path.padEnd(44)} ${target.padEnd(28)} ${where}`;
  });

  lines.push(
    '',
    `${summary.total} calls: ${summary.direct} direct, ${summary.partial} partial, ${summary.manual} manual, ${summary.unmapped} unmapped.`,
  );
  return lines.join('\n');
}
