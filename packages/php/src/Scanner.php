<?php

declare(strict_types=1);

namespace EdmUk\ShopifyRestToGraphql;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Walks a tree, parses each PHP file and pairs every call with its rule. */
final class Scanner
{
    private const SKIPPED = ['vendor', 'node_modules', '.git', 'storage', 'bootstrap', 'public'];

    private const MAX_FILE_BYTES = 1_000_000;

    /** @var list<string> */
    private array $unparseable = [];

    public function __construct(private readonly MappingRepository $mappings)
    {
    }

    /**
     * Files the parser could not read during the last scan.
     *
     * @return list<string>
     */
    public function unparseableFiles(): array
    {
        return $this->unparseable;
    }

    /**
     * @param  list<string>  $exclude
     * @return list<Finding>
     */
    public function scanDirectory(string $root, array $exclude = []): array
    {
        $skip = array_merge(self::SKIPPED, $exclude);
        $findings = [];
        $this->unparseable = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if ($file->getSize() > self::MAX_FILE_BYTES) {
                continue;
            }
            if ($this->isSkipped($file->getPathname(), $root, $skip)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }

            // Cheap rejection before paying for a parse. A file needs one of
            // these to produce a finding at all. `.json` matters as much as the
            // Admin prefix, because wrappers set a base URL once and then pass
            // bare resource paths such as `webhooks.json`.
            if (! str_contains($source, '/admin/') && ! str_contains($source, '.json')) {
                continue;
            }

            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');

            try {
                $fileFindings = $this->scanSource($relative, $source);
            } catch (UnparseableFile $skipped) {
                $this->unparseable[] = $skipped->path;

                continue;
            }

            foreach ($fileFindings as $finding) {
                $findings[] = $finding;
            }
        }

        usort($findings, static function (Finding $a, Finding $b): int {
            return [$a->call->file, $a->call->line] <=> [$b->call->file, $b->call->line];
        });

        return $findings;
    }

    /** @return list<Finding> */
    public function scanSource(string $file, string $source): array
    {
        $findings = [];
        foreach (Detector::detect($file, $source) as $call) {
            $findings[] = new Finding($call, $this->mappings->find($call->method, $call->path));
        }

        return $findings;
    }

    /** @param  list<string>  $skip */
    private function isSkipped(string $path, string $root, array $skip): bool
    {
        $relative = trim(str_replace($root, '', $path), '/');
        foreach (explode('/', $relative) as $segment) {
            if (in_array($segment, $skip, true)) {
                return true;
            }
        }

        return false;
    }
}
