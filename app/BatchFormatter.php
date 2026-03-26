<?php

namespace BladeFormatter;

use BladeFormatter\Formatters\IndentationFormatter;
use BladeFormatter\Formatters\PintFormatter;
use BladeFormatter\Formatters\TailwindFormatter;
use Symfony\Component\Process\Process;

class BatchFormatter
{
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
     * Pint and Prettier run concurrently since they operate on independent
     * content (PHP sections vs Blade class strings).
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

        // Phase 1: Parse all files
        $parsed = [];
        foreach ($files as $path => $content) {
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
            foreach ($files as $path => $content) {
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
                $bladeContent = $parts['isSfc'] ? $parts['blade'] : $files[$path];
                $classStrings = $tailwindFormatter->extractClassStrings($bladeContent);
                $fileClassStrings[$path] = $classStrings;
                foreach ($classStrings as $cs) {
                    $allClassStrings[] = $cs;
                }
            }
            $allClassStrings = array_values(array_unique($allClassStrings));
        }

        // Phase 3: Run Pint and Prettier concurrently
        [$formattedPhp, $sortMap] = $this->runFormatters($phpChunks, $tailwindFormatter, $allClassStrings, $fileClassStrings);

        // Phase 3b: Run Pint on @php blocks (SFC blocks expand imports for hoisting)
        $sfcPaths = array_keys(array_filter($parsed, fn (array $p): bool => $p['isSfc']));
        $formattedPhpBlocks = $this->formatPhpBlocks($phpBlocks, $sfcPaths);

        // Phase 4: Reassemble each file
        $indentationFormatter = new IndentationFormatter;
        $results = [];

        foreach ($files as $path => $originalContent) {
            $parts = $parsed[$path];
            $php = $formattedPhp[$path] ?? $parts['php'];
            $blade = $parts['isSfc'] ? $parts['blade'] : $originalContent;

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
            if ($this->enableIndentation && ! preg_match('/<x-mail::message[\s>]/', $blade)) {
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
            $results[$path] = (string) preg_replace('/\n{3,}/', "\n\n", $result);

            if ($onFileComplete !== null) {
                $onFileComplete($path, $results[$path]);
            }
        }

        return $results;
    }

    /**
     * Run Pint and Prettier concurrently.
     *
     * @param  array<string, string>  $phpChunks
     * @param  list<string>  $allClassStrings
     * @param  array<string, list<string>>  $fileClassStrings
     * @return array{array<string, string>, array<string, string>}
     */
    private function runFormatters(array $phpChunks, TailwindFormatter $tailwindFormatter, array $allClassStrings, array $fileClassStrings): array
    {
        $pintTmpDir = null;
        $pintProcess = null;
        $pintKeyMap = [];

        $prettierTmpDir = null;
        $prettierTmpFile = null;
        $prettierProcess = null;

        // Start Pint process
        if ($this->enablePint && $phpChunks !== []) {
            try {
                $pintPath = PintFormatter::resolvePintPath();
                $pintTmpDir = sys_get_temp_dir().'/blade-fmt-'.uniqid();
                mkdir($pintTmpDir);

                $index = 0;
                foreach ($phpChunks as $key => $phpCode) {
                    $filename = $index.'.php';
                    $pintKeyMap[$filename] = $key;
                    file_put_contents($pintTmpDir.'/'.$filename, $phpCode);
                    $index++;
                }

                $command = [$pintPath, $pintTmpDir, '--quiet'];
                if ($this->pintConfigPath !== null) {
                    $command[] = '--config';
                    $command[] = $this->pintConfigPath;
                }

                $pintProcess = new Process($command);
                $pintProcess->setTimeout(30);
                $pintProcess->start();
            } catch (\Throwable $e) {
                foreach (array_keys($phpChunks) as $path) {
                    $this->warnings[$path][] = 'Pint skipped: '.$e->getMessage();
                }
                $pintProcess = null;
            }
        }

        // Start Prettier process
        if ($this->enableTailwindSort && $allClassStrings !== []) {
            try {
                $lines = array_map(
                    fn (string $cs, int $i): string => '<div id="cs'.$i.'" class="'.$cs.'"></div>',
                    $allClassStrings,
                    array_keys($allClassStrings)
                );

                $prettierTmpDir = sys_get_temp_dir().'/blade-fmt-tw-'.uniqid();
                mkdir($prettierTmpDir);
                $prettierTmpFile = $prettierTmpDir.'/template.html';
                file_put_contents($prettierTmpFile, implode("\n", $lines));

                $command = TailwindFormatter::buildPrettierCommand($this->prettierPath, $prettierTmpFile);
                $prettierProcess = new Process($command);
                $prettierProcess->setTimeout(30);
                $prettierProcess->start();
            } catch (\Throwable $e) {
                foreach ($fileClassStrings as $path => $classStrings) {
                    if ($classStrings !== []) {
                        $this->warnings[$path][] = 'Tailwind sorting skipped: '.$e->getMessage();
                    }
                }
                $prettierProcess = null;
            }
        }

        // Wait for both and collect results
        $formattedPhp = [];
        if ($pintProcess !== null) {
            try {
                $pintProcess->wait();
                if ($pintProcess->isSuccessful() || $pintProcess->getExitCode() === 1) {
                    foreach ($pintKeyMap as $filename => $key) {
                        $formatted = rtrim((string) file_get_contents($pintTmpDir.'/'.$filename));
                        if (! str_ends_with($formatted, '?'.'>')) {
                            $formatted .= "\n".'?'.'>';
                        }
                        $formattedPhp[$key] = $formatted;
                    }
                } else {
                    throw new \RuntimeException('Pint failed: '.($pintProcess->getErrorOutput() ?: $pintProcess->getOutput()));
                }
            } catch (\Throwable $e) {
                foreach (array_keys($phpChunks) as $path) {
                    $this->warnings[$path][] = 'Pint skipped: '.$e->getMessage();
                }
            } finally {
                if ($pintTmpDir !== null) {
                    foreach (glob($pintTmpDir.'/*.php') ?: [] as $file) {
                        @unlink($file);
                    }
                    @rmdir($pintTmpDir);
                }
            }
        }

        $sortMap = [];
        if ($prettierProcess !== null && $prettierTmpFile !== null) {
            try {
                $prettierProcess->wait();
                if ($prettierProcess->isSuccessful()) {
                    $sorted = (string) file_get_contents($prettierTmpFile);
                    preg_match_all('/class="([^"]*)"/', $sorted, $extractMatches);

                    if (count($extractMatches[1]) === count($allClassStrings)) {
                        for ($i = 0; $i < count($allClassStrings); $i++) {
                            if ($allClassStrings[$i] !== $extractMatches[1][$i]) {
                                $sortMap[$allClassStrings[$i]] = $extractMatches[1][$i];
                            }
                        }
                    }
                } else {
                    throw new \RuntimeException('Prettier failed: '.($prettierProcess->getErrorOutput() ?: $prettierProcess->getOutput()));
                }
            } catch (\Throwable $e) {
                foreach ($fileClassStrings as $path => $classStrings) {
                    if ($classStrings !== []) {
                        $this->warnings[$path][] = 'Tailwind sorting skipped: '.$e->getMessage();
                    }
                }
            } finally {
                @unlink($prettierTmpFile);
                if ($prettierTmpDir !== null) {
                    @rmdir($prettierTmpDir);
                }
            }
        }

        return [$formattedPhp, $sortMap];
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
     * Format @php block contents with Pint.
     *
     * @param  array<string, list<array{start: int, end: int, code: string}>>  $phpBlocks
     * @param  list<string>  $sfcPaths  Paths of files that are SFCs
     * @return array<string, array<int, string>>  Formatted code per file, indexed same as blocks
     */
    private function formatPhpBlocks(array $phpBlocks, array $sfcPaths): array
    {
        if ($phpBlocks === []) {
            return [];
        }

        // Split blocks into SFC and non-SFC batches
        $sfcChunks = [];
        $regularChunks = [];
        $keyMap = [];
        foreach ($phpBlocks as $path => $blocks) {
            $isSfc = in_array($path, $sfcPaths);
            foreach ($blocks as $index => $block) {
                $key = $path.':'.$index;
                $keyMap[$key] = ['path' => $path, 'index' => $index];
                if ($isSfc) {
                    $sfcChunks[$key] = $this->dedent($block['code']);
                } else {
                    $regularChunks[$key] = $this->dedent($block['code']);
                }
            }
        }

        try {
            $pintFormatter = new PintFormatter;
            // SFC blocks: expand imports so FQCNs can be hoisted to PHP section
            $formatted = $pintFormatter->formatPhpBlockBatch($sfcChunks, $this->pintConfigPath, expandImports: true)
                + $pintFormatter->formatPhpBlockBatch($regularChunks, $this->pintConfigPath, expandImports: false);
        } catch (\Throwable $e) {
            foreach (array_keys($phpBlocks) as $path) {
                $this->warnings[$path][] = 'Pint skipped for @php blocks: '.$e->getMessage();
            }

            return [];
        }

        // Group results back by file
        $results = [];
        foreach ($formatted as $key => $code) {
            $info = $keyMap[$key];
            $results[$info['path']][$info['index']] = rtrim($code)."\n";
        }

        return $results;
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
