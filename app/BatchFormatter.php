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

        // Phase 3b: Run Pint on @php blocks
        $formattedPhpBlocks = $this->formatPhpBlocks($phpBlocks);

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
     * @return array<string, array<int, string>>  Formatted code per file, indexed same as blocks
     */
    private function formatPhpBlocks(array $phpBlocks): array
    {
        if ($phpBlocks === []) {
            return [];
        }

        // Collect all blocks into a single Pint batch
        $chunks = [];
        $keyMap = [];
        foreach ($phpBlocks as $path => $blocks) {
            foreach ($blocks as $index => $block) {
                $key = $path.':'.$index;
                // Dedent the code to column 0 so Pint formats cleanly
                $chunks[$key] = "<?php\n".$this->dedent($block['code']);
                $keyMap[$key] = ['path' => $path, 'index' => $index];
            }
        }

        try {
            $pintFormatter = new PintFormatter;
            $formatted = $pintFormatter->formatBatch($chunks, $this->pintConfigPath, ensureClosingTag: false);
        } catch (\Throwable $e) {
            foreach (array_keys($phpBlocks) as $path) {
                $this->warnings[$path][] = 'Pint skipped for @php blocks: '.$e->getMessage();
            }

            return [];
        }

        // Group results back by file
        $results = [];
        foreach ($formatted as $key => $formattedCode) {
            $info = $keyMap[$key];
            // Strip the <?php prefix we added
            $code = (string) preg_replace('/^<\?php\s*\n?/', '', $formattedCode);
            $code = rtrim($code)."\n";
            $results[$info['path']][$info['index']] = $code;
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
