<?php

namespace BladeFormatter;

use BladeFormatter\Formatters\IndentationFormatter;
use BladeFormatter\Formatters\PintFormatter;
use BladeFormatter\Formatters\TailwindFormatter;

class BatchFormatter
{
    private const DISABLE_MARKER = '/\{\{--\s*blade-formatter-disable\s*--\}\}/';

    private const ENABLE_MARKER = '/\{\{--\s*blade-formatter-enable\s*--\}\}/';

    private const PLACEHOLDER_PATTERN = '\{\{-- blade-formatter-region:(\d+) --\}\}';

    /** @var array<string, list<string>> */
    private array $warnings = [];

    public function __construct(
        private bool $enablePint = true,
        private bool $enableTailwindSort = true,
        private bool $enableIndentation = true,
        private int $indentSize = 4,
        private ?string $pintConfigPath = null,
        private string $prettierPath = 'node_modules/.bin/prettier',
    ) {}

    /**
     * Format multiple files in batch, minimizing subprocess invocations.
     *
     * Every Pint and Prettier subprocess runs concurrently, since they operate
     * on independent content (PHP sections, @php blocks, Blade class strings).
     *
     * @param  array<string, string>  $files  Keyed by file path => content
     * @param  (\Closure(string $path, string $formatted): void)|null  $onFileComplete
     * @return array<string, string> Formatted content, same keys
     */
    public function formatBatch(array $files, ?\Closure $onFileComplete = null): array
    {
        $this->warnings = [];

        if ($files === []) {
            return [];
        }

        // Phase 0: Replace opted-out regions with a single-line placeholder so no
        // formatter ever sees them. Offsets shift as each phase rewrites content,
        // so masking up front is the only way ranges survive the whole pipeline.
        $masked = [];
        $maskedRegions = [];
        foreach ($files as $path => $content) {
            [$masked[$path], $maskedRegions[$path]] = self::maskDisabledRegions($content);
        }

        // Phase 1: Parse all files
        $parsed = [];
        foreach ($masked as $path => $content) {
            $parsed[$path] = Parser::parseSfc($content);
        }

        // Phase 2: Collect work for Pint and Tailwind
        $phpChunks = [];
        foreach ($parsed as $path => $parts) {
            if ($parts['isSfc']) {
                $phpChunks[$path] = $parts['php'];
            }
        }

        // Collect @php blocks for Pint formatting
        $phpBlocks = [];
        if ($this->enablePint) {
            foreach ($masked as $path => $content) {
                $bladeContent = $parsed[$path]['isSfc'] ? $parsed[$path]['blade'] : $content;
                $blocks = $this->extractPhpBlocks($bladeContent);
                if ($blocks !== []) {
                    $phpBlocks[$path] = $blocks;
                }
            }
        }

        $tailwindFormatter = new TailwindFormatter;
        $allClassStrings = [];
        $fileClassStrings = [];

        if ($this->enableTailwindSort) {
            foreach ($parsed as $path => $parts) {
                $bladeContent = $parts['isSfc'] ? $parts['blade'] : $masked[$path];
                $classStrings = $tailwindFormatter->extractClassStrings($bladeContent);
                $fileClassStrings[$path] = $classStrings;
                foreach ($classStrings as $cs) {
                    $allClassStrings[] = $cs;
                }
            }
            $allClassStrings = array_values(array_unique($allClassStrings));
        }

        // Phase 3: Run Pint (PHP sections and @php blocks) and Prettier concurrently.
        // SFC @php blocks expand imports for hoisting.
        $sfcPaths = array_keys(array_filter($parsed, fn (array $p): bool => $p['isSfc']));
        [$formattedPhp, $sortMap, $formattedPhpBlocks] = $this->runFormatters(
            $phpChunks, $phpBlocks, $sfcPaths, $tailwindFormatter, $allClassStrings, $fileClassStrings,
        );

        // Phase 4: Reassemble each file
        $indentationFormatter = new IndentationFormatter;
        $results = [];

        foreach ($files as $path => $originalContent) {
            $parts = $parsed[$path];
            $php = $formattedPhp[$path] ?? $parts['php'];
            $blade = $parts['isSfc'] ? $parts['blade'] : $masked[$path];

            // Reinsert Pint-formatted @php block contents before indentation
            if (isset($formattedPhpBlocks[$path])) {
                $blade = $this->reinsertPhpBlocks($blade, $phpBlocks[$path], $formattedPhpBlocks[$path]);
            }

            if ($parts['isSfc']) {
                // Hoist FQCNs from Blade to the SFC PHP section as use statements
                [$php, $blade] = $this->hoistFqcnsToPhpSection($php, $blade);

                // Re-add any imports that Pint stripped as "unused" but are
                // referenced by short name in the Blade template
                $php = $this->restoreBladeImports($parts['php'], $php, $blade);
            }

            // Apply indentation (skip Markdown mail templates where whitespace affects rendering)
            if ($this->enableIndentation && ! self::isMarkdownMail($path, $blade)) {
                $blade = $indentationFormatter->format($blade, $this->indentSize);
            }

            // Apply Tailwind sorting
            if ($this->enableTailwindSort && $sortMap !== []) {
                $blade = $tailwindFormatter->applySortedClasses($blade, $sortMap);
            }

            $result = $parts['isSfc']
                ? Parser::assembleSfc($php, $blade)
                : $blade;

            // Collapse multiple consecutive blank lines
            $result = (string) preg_replace('/\n{3,}/', "\n\n", $result);

            // Put the opted-out regions back exactly as they were written. If any
            // placeholder failed to survive the pipeline, restoring would silently
            // drop the user's markup, so the file is left untouched instead.
            $restored = self::restoreDisabledRegions($result, $maskedRegions[$path]);

            if ($restored === null) {
                $this->warnings[$path][] = 'Formatting skipped: could not restore blade-formatter-disable regions.';
                $results[$path] = $originalContent;
            } else {
                $results[$path] = $restored;
            }

            if ($onFileComplete !== null) {
                $onFileComplete($path, $results[$path]);
            }
        }

        return $results;
    }

    /**
     * Whether a template is one of Blade's Markdown mail views.
     *
     * The assembled output of these is run through a Markdown parser, so any
     * line the formatter indents is delivered as a code block in the email.
     *
     * A message written by hand opens with `<x-mail::message>`, but the views
     * `vendor:publish` writes to `resources/views/vendor/mail` are the pieces
     * that component is built from — `<x-mail::layout>`, `<x-mail::header>`,
     * `<x-mail::footer>` and so on — and a theme partial may reference no
     * component at all. So the whole `x-mail::` namespace counts, as does
     * anything sitting under a `vendor/mail` path.
     */
    public static function isMarkdownMail(string $path, string $content): bool
    {
        if (str_contains($content, '<x-mail::')) {
            return true;
        }

        return str_contains(str_replace('\\', '/', $path), 'vendor/mail/');
    }

    /**
     * Run Pint and Prettier concurrently.
     *
     * Every subprocess is started before any is waited on, so the batch takes
     * as long as the slowest one rather than the sum of them all.
     *
     * @param  array<string, string>  $phpChunks
     * @param  array<string, list<array{start: int, end: int, code: string}>>  $phpBlocks
     * @param  list<string>  $sfcPaths  Paths of files that are SFCs
     * @param  list<string>  $allClassStrings
     * @param  array<string, list<string>>  $fileClassStrings
     * @return array{array<string, string>, array<string, string>, array<string, array<int, string>>}
     */
    private function runFormatters(array $phpChunks, array $phpBlocks, array $sfcPaths, TailwindFormatter $tailwindFormatter, array $allClassStrings, array $fileClassStrings): array
    {
        $pintFormatter = new PintFormatter;

        $warnPint = function (\Throwable $e) use ($phpChunks): void {
            foreach (array_keys($phpChunks) as $path) {
                $this->warnings[$path][] = 'Pint skipped: '.$e->getMessage();
            }
        };

        $warnPhpBlocks = function (\Throwable $e) use ($phpBlocks): void {
            foreach (array_keys($phpBlocks) as $path) {
                $this->warnings[$path][] = 'Pint skipped for @php blocks: '.$e->getMessage();
            }
        };

        $warnTailwind = function (\Throwable $e) use ($fileClassStrings): void {
            foreach ($fileClassStrings as $path => $classStrings) {
                if ($classStrings !== []) {
                    $this->warnings[$path][] = 'Tailwind sorting skipped: '.$e->getMessage();
                }
            }
        };

        // Start Pint on the SFC PHP sections
        $pendingPint = null;
        if ($this->enablePint) {
            try {
                $pendingPint = $pintFormatter->startBatch($phpChunks, $this->pintConfigPath);
            } catch (\Throwable $e) {
                $warnPint($e);
            }
        }

        // Start Pint on the @php blocks. SFC and regular blocks share one run,
        // since they differ only in how the result is post-processed.
        $blockChunks = [];
        $blockKeyMap = [];
        $expandImportsFor = [];
        $isSfc = array_fill_keys($sfcPaths, true);
        foreach ($phpBlocks as $path => $blocks) {
            foreach ($blocks as $index => $block) {
                $key = $path.':'.$index;
                $blockKeyMap[$key] = ['path' => $path, 'index' => $index];
                $blockChunks[$key] = $this->dedent($block['code']);
                if (isset($isSfc[$path])) {
                    $expandImportsFor[] = $key;
                }
            }
        }

        $pendingPhpBlocks = null;
        try {
            $pendingPhpBlocks = $pintFormatter->startPhpBlockBatch($blockChunks, $this->pintConfigPath);
        } catch (\Throwable $e) {
            $warnPhpBlocks($e);
        }

        // Start Prettier
        $pendingSort = null;
        if ($this->enableTailwindSort) {
            try {
                $pendingSort = $tailwindFormatter->startSort($this->prettierPath, $allClassStrings);
            } catch (\Throwable $e) {
                $warnTailwind($e);
            }
        }

        // Wait for each and collect results
        $formattedPhp = [];
        try {
            $formattedPhp = $pintFormatter->finishBatch($pendingPint);
        } catch (\Throwable $e) {
            $warnPint($e);
        }

        $formattedPhpBlocks = [];
        try {
            foreach ($pintFormatter->finishPhpBlockBatch($pendingPhpBlocks, $expandImportsFor) as $key => $code) {
                $info = $blockKeyMap[$key];
                $formattedPhpBlocks[$info['path']][$info['index']] = rtrim($code)."\n";
            }
        } catch (\Throwable $e) {
            $formattedPhpBlocks = [];
            $warnPhpBlocks($e);
        }

        $sortMap = [];
        try {
            $sorted = $tailwindFormatter->finishSort($pendingSort);

            if ($sorted !== null) {
                foreach ($sorted as $i => $sortedClassString) {
                    if ($allClassStrings[$i] !== $sortedClassString) {
                        $sortMap[$allClassStrings[$i]] = $sortedClassString;
                    }
                }
            }
        } catch (\Throwable $e) {
            $warnTailwind($e);
        }

        return [$formattedPhp, $sortMap, $formattedPhpBlocks];
    }

    /**
     * Find the byte ranges opted out of formatting.
     *
     * A `{{-- blade-formatter-disable --}}` comment starts a region and
     * `{{-- blade-formatter-enable --}}` ends it; an unclosed region runs to the
     * end of the file, so a single comment at the top opts out the whole thing.
     * Both markers are part of the region, so they survive in the output.
     *
     * Intended for markup that is itself the content — documentation pages
     * showing code samples, fixtures asserted against as strings — where even
     * the always-safe rewrites (Tailwind class order, indentation) are unwanted.
     *
     * @return list<array{start: int, end: int}>
     */
    public static function disabledRegions(string $content): array
    {
        if (! preg_match_all(self::DISABLE_MARKER, $content, $starts, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        preg_match_all(self::ENABLE_MARKER, $content, $ends, PREG_OFFSET_CAPTURE);

        $regions = [];
        $cursor = 0;

        foreach ($starts[0] as [$startText, $startOffset]) {
            // A second disable inside an open region is just text.
            if ($startOffset < $cursor) {
                continue;
            }

            $end = strlen($content);

            foreach ($ends[0] as [$endText, $endOffset]) {
                if ($endOffset > $startOffset) {
                    $end = $endOffset + strlen($endText);
                    break;
                }
            }

            $regions[] = ['start' => $startOffset, 'end' => $end];
            $cursor = $end;
        }

        return $regions;
    }

    /**
     * Replace each opted-out region with a single-line placeholder.
     *
     * @return array{string, array<int, string>} Masked content, and the original
     *                                           text of each region by index
     */
    public static function maskDisabledRegions(string $content): array
    {
        $regions = self::disabledRegions($content);

        if ($regions === []) {
            return [$content, []];
        }

        $originals = [];

        // Replace back to front so earlier offsets stay valid.
        for ($i = count($regions) - 1; $i >= 0; $i--) {
            ['start' => $start, 'end' => $end] = $regions[$i];

            $originals[$i] = substr($content, $start, $end - $start);
            $content = substr($content, 0, $start).self::placeholder($i).substr($content, $end);
        }

        ksort($originals);

        return [$content, $originals];
    }

    /**
     * Substitute opted-out regions back into formatted content.
     *
     * @param  array<int, string>  $originals
     * @return string|null Null if any region could not be restored
     */
    public static function restoreDisabledRegions(string $content, array $originals): ?string
    {
        if ($originals === []) {
            return $content;
        }

        $restored = [];

        // A placeholder on its own line drops whatever indentation the formatter
        // gave it, so the region returns at the column the author wrote it in.
        $content = (string) preg_replace_callback(
            '/^[ \t]*'.self::PLACEHOLDER_PATTERN.'[ \t]*$/m',
            function (array $m) use ($originals, &$restored): string {
                $index = (int) $m[1];
                $restored[$index] = true;

                return $originals[$index] ?? $m[0];
            },
            $content,
        );

        // An inline placeholder keeps its surroundings.
        $content = (string) preg_replace_callback(
            '/'.self::PLACEHOLDER_PATTERN.'/',
            function (array $m) use ($originals, &$restored): string {
                $index = (int) $m[1];
                $restored[$index] = true;

                return $originals[$index] ?? $m[0];
            },
            $content,
        );

        // Every region must come back, or content would be silently lost.
        foreach (array_keys($originals) as $index) {
            if (! isset($restored[$index])) {
                return null;
            }
        }

        return $content;
    }

    private static function placeholder(int $index): string
    {
        return '{{-- blade-formatter-region:'.$index.' --}}';
    }

    /**
     * Get warnings for a specific file.
     *
     * @return list<string>
     */
    public function getWarnings(string $path): array
    {
        return $this->warnings[$path] ?? [];
    }

    /**
     * Get all warnings.
     *
     * @return array<string, list<string>>
     */
    public function getAllWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Extract multiline @php/@endphp blocks from Blade content.
     *
     * @return list<array{start: int, end: int, code: string}>
     */
    private function extractPhpBlocks(string $blade): array
    {
        $blocks = [];

        // Match @php (not @php(...)) followed by content and @endphp
        if (preg_match_all('/@php\s*\n([\s\S]*?)@endphp/', $blade, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                $code = $match[0];
                $start = $match[1];

                // Skip empty blocks
                if (trim($code) === '') {
                    continue;
                }

                $blocks[] = [
                    'start' => $start,
                    'end' => $start + strlen($code),
                    'code' => $code,
                ];
            }
        }

        return $blocks;
    }

    /**
     * Reinsert Pint-formatted @php block contents into the Blade template.
     * Processes blocks in reverse order to preserve string offsets.
     *
     * @param  list<array{start: int, end: int, code: string}>  $blocks
     * @param  array<int, string>  $formattedBlocks
     */
    private function reinsertPhpBlocks(string $blade, array $blocks, array $formattedBlocks): string
    {
        // Process in reverse to preserve offsets
        for ($i = count($blocks) - 1; $i >= 0; $i--) {
            if (! isset($formattedBlocks[$i])) {
                continue;
            }

            $block = $blocks[$i];
            $blade = substr($blade, 0, $block['start'])
                .$formattedBlocks[$i]
                .substr($blade, $block['end']);
        }

        return $blade;
    }

    /**
     * Re-add use statements that Pint stripped as "unused" but are
     * referenced by short name in the Blade template.
     */
    private function restoreBladeImports(string $originalPhp, string $formattedPhp, string $blade): string
    {
        // Find imports that existed before Pint
        preg_match_all('/^use\s+([\w\\\\]+)\s*;$/m', $originalPhp, $originalUses);
        // Find imports that survived Pint
        preg_match_all('/^use\s+([\w\\\\]+)\s*;$/m', $formattedPhp, $currentUses);

        $stripped = array_diff($originalUses[1], $currentUses[1]);

        if ($stripped === []) {
            return $formattedPhp;
        }

        $toRestore = [];
        foreach ($stripped as $fqcn) {
            $shortName = substr($fqcn, strrpos($fqcn, '\\') + 1);
            if (preg_match('/\b'.preg_quote($shortName, '/').'\b/', $blade)) {
                $toRestore[] = 'use '.$fqcn.';';
            }
        }

        if ($toRestore === []) {
            return $formattedPhp;
        }

        // Insert after the last existing use statement
        if (preg_match_all('/^use\s+[\w\\\\]+\s*;$/m', $formattedPhp, $allUses, PREG_OFFSET_CAPTURE)) {
            $lastUse = end($allUses[0]);
            /** @var array{string, int} $lastUse */
            $insertPos = $lastUse[1] + strlen($lastUse[0]);
            $formattedPhp = substr($formattedPhp, 0, $insertPos)."\n".implode("\n", $toRestore).substr($formattedPhp, $insertPos);
        }

        return $this->sortUseStatements($formattedPhp);
    }

    /**
     * Extract FQCNs from Blade content, replace them with short names,
     * and add use statements to the SFC PHP section.
     *
     * This runs after Pint has already formatted the PHP section, so the
     * use statements won't be stripped as "unused".
     *
     * @return array{string, string} [php, blade]
     */
    private function hoistFqcnsToPhpSection(string $php, string $blade): array
    {
        // Match FQCNs: at least one backslash, starting with uppercase segment
        // e.g. App\Models\Post, App\Enums\Status
        if (! preg_match_all('/\b((?:[A-Z][a-zA-Z0-9]*\\\\)+[A-Z][a-zA-Z0-9]*)\b/', $blade, $matches)) {
            return [$php, $blade];
        }

        $fqcns = array_unique($matches[1]);

        // Collect existing use statements from the PHP section to avoid duplicates
        preg_match_all('/^use\s+([\w\\\\]+)\s*;$/m', $php, $existingUses);
        $existingFqcns = $existingUses[1];

        $newUses = [];
        foreach ($fqcns as $fqcn) {
            $shortName = substr($fqcn, strrpos($fqcn, '\\') + 1);

            // Skip if already imported
            if (in_array($fqcn, $existingFqcns)) {
                // Still replace FQCN with short name in Blade
                $blade = $this->replaceFqcnWithShortName($blade, $fqcn, $shortName);

                continue;
            }

            // Check for short name conflicts (different FQCN, same class name)
            $conflictsWithExisting = false;
            foreach ($existingFqcns as $existing) {
                if (substr($existing, strrpos($existing, '\\') + 1) === $shortName && $existing !== $fqcn) {
                    $conflictsWithExisting = true;
                    break;
                }
            }

            if ($conflictsWithExisting) {
                continue; // Leave as FQCN in Blade to avoid ambiguity
            }

            $newUses[] = 'use '.$fqcn.';';
            $blade = $this->replaceFqcnWithShortName($blade, $fqcn, $shortName);
        }

        if ($newUses === []) {
            return [$php, $blade];
        }

        // Insert new use statements into the PHP section after existing ones
        sort($newUses);

        if (preg_match_all('/^use\s+[\w\\\\]+\s*;$/m', $php, $allUseMatches, PREG_OFFSET_CAPTURE)) {
            // Insert after the last existing use statement
            $lastUse = end($allUseMatches[0]);
            /** @var array{string, int} $lastUse */
            $insertPos = $lastUse[1] + strlen($lastUse[0]);
            $php = substr($php, 0, $insertPos)."\n".implode("\n", $newUses).substr($php, $insertPos);
        } else {
            // No existing use statements — insert after <?php line
            $phpTagEnd = strpos($php, "\n");
            if ($phpTagEnd !== false) {
                $php = substr($php, 0, $phpTagEnd)."\n\n".implode("\n", $newUses).substr($php, $phpTagEnd);
            }
        }

        // Re-sort all use statements in the PHP section
        $php = $this->sortUseStatements($php);

        return [$php, $blade];
    }

    /**
     * Replace a FQCN with its short name in all class reference contexts.
     */
    private function replaceFqcnWithShortName(string $code, string $fqcn, string $shortName): string
    {
        $escaped = preg_quote($fqcn, '/');

        return (string) preg_replace(
            '/(?<=instanceof\s)\\\\?'.$escaped.'\b'
            .'|\\\\?'.$escaped.'(?=\s*::|\s*\$)'
            .'|(?<=new\s)\\\\?'.$escaped.'\b/',
            $shortName,
            $code,
        );
    }

    /**
     * Sort use statements alphabetically within the PHP section.
     */
    private function sortUseStatements(string $php): string
    {
        if (! preg_match_all('/^use\s+[\w\\\\]+\s*;$/m', $php, $matches, PREG_OFFSET_CAPTURE)) {
            return $php;
        }

        $uses = $matches[0];
        if (count($uses) < 2) {
            return $php;
        }

        $sorted = array_column($uses, 0);
        sort($sorted);

        // Replace each use statement in order
        // Process in reverse to preserve offsets
        $result = $php;
        for ($i = count($uses) - 1; $i >= 0; $i--) {
            $result = substr($result, 0, $uses[$i][1])
                .$sorted[$i]
                .substr($result, $uses[$i][1] + strlen($uses[$i][0]));
        }

        return $result;
    }

    /**
     * Remove the common leading whitespace from all non-empty lines.
     */
    private function dedent(string $code): string
    {
        $lines = explode("\n", $code);
        $minIndent = PHP_INT_MAX;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));
            $minIndent = min($minIndent, $indent);
        }

        if ($minIndent === 0 || $minIndent === PHP_INT_MAX) {
            return $code;
        }

        return implode("\n", array_map(
            fn (string $line): string => trim($line) === '' ? '' : substr($line, $minIndent),
            $lines,
        ));
    }
}
