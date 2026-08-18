<?php

declare(strict_types=1);

namespace EdmUk\ShopifyRestToGraphql;

/** Turns findings into the three output formats the CLI offers. */
final class Report
{
    private const STATUS_LABEL = [
        'direct' => 'direct ',
        'partial' => 'partial',
        'manual' => 'manual ',
        'unmapped' => 'unknown',
    ];

    /**
     * @param  list<Finding>  $findings
     * @return array{total: int, direct: int, partial: int, manual: int, unmapped: int}
     */
    public static function summarise(array $findings): array
    {
        $summary = ['total' => count($findings), 'direct' => 0, 'partial' => 0, 'manual' => 0, 'unmapped' => 0];
        foreach ($findings as $finding) {
            $summary[$finding->status()]++;
        }

        return $summary;
    }

    /** @param  list<Finding>  $findings */
    public static function toText(array $findings): string
    {
        $lines = [];
        foreach ($findings as $finding) {
            $label = self::STATUS_LABEL[$finding->status()] ?? $finding->status();
            $lines[] = sprintf(
                '%s  %-6s %-44s %-28s %s:%d',
                $label,
                $finding->call->method,
                $finding->call->path,
                $finding->rule?->rootField ?? '-',
                $finding->call->file,
                $finding->call->line,
            );
        }

        $summary = self::summarise($findings);
        $lines[] = '';
        $lines[] = sprintf(
            '%d calls: %d direct, %d partial, %d manual, %d unmapped.',
            $summary['total'],
            $summary['direct'],
            $summary['partial'],
            $summary['manual'],
            $summary['unmapped'],
        );

        return implode("\n", $lines);
    }

    /** @param  list<Finding>  $findings */
    public static function toJson(array $findings): string
    {
        $payload = [
            'summary' => self::summarise($findings),
            'findings' => array_map(static fn (Finding $finding): array => [
                'file' => $finding->call->file,
                'line' => $finding->call->line,
                'method' => $finding->call->method,
                'path' => $finding->call->path,
                'apiVersion' => $finding->call->apiVersion,
                'detector' => $finding->call->detector,
                'confidence' => $finding->call->confidence,
                'rule' => $finding->rule?->id,
                'status' => $finding->status(),
                'rootField' => $finding->rule?->rootField,
                'notes' => $finding->rule?->notes ?? [],
                'docs' => $finding->rule?->docs,
            ], $findings),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param  list<Finding>  $findings */
    public static function toMarkdown(array $findings): string
    {
        $summary = self::summarise($findings);
        $lines = ['# Shopify REST To GraphQL Report', ''];
        $lines[] = sprintf(
            '%d REST calls found. %d map directly, %d need the call site changed, %d need a decision, %d are not covered by a rule.',
            $summary['total'],
            $summary['direct'],
            $summary['partial'],
            $summary['manual'],
            $summary['unmapped'],
        );
        $lines[] = '';

        $byFile = [];
        foreach ($findings as $finding) {
            $byFile[$finding->call->file][] = $finding;
        }
        ksort($byFile);

        foreach ($byFile as $file => $group) {
            $lines[] = "## {$file}";
            $lines[] = '';
            foreach ($group as $finding) {
                $call = $finding->call;
                $lines[] = "### {$call->method} {$call->path} · line {$call->line}";
                $lines[] = '';
                $lines[] = "Found by `{$call->detector}`, confidence {$call->confidence}.";
                $lines[] = '';
                $lines[] = '```';
                $lines[] = $call->evidence;
                $lines[] = '```';
                $lines[] = '';

                $rule = $finding->rule;
                if ($rule === null) {
                    $lines[] = 'No rule covers this call. Check the GraphQL Admin API reference for the equivalent operation.';
                    $lines[] = '';

                    continue;
                }

                if ($rule->hasReplacement() && $rule->document !== null) {
                    $lines[] = "Replace with `{$rule->rootField}`.";
                    $lines[] = '';
                    $lines[] = '```graphql';
                    $lines[] = $rule->document;
                    $lines[] = '```';
                    $lines[] = '';
                } else {
                    $lines[] = 'No single operation replaces this call. It needs a decision.';
                    $lines[] = '';
                }

                foreach ($rule->notes as $note) {
                    $lines[] = "- {$note}";
                }
                if ($rule->notes !== []) {
                    $lines[] = '';
                }
                $lines[] = "Reference: {$rule->docs}";
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }
}
