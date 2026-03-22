<?php

namespace JoelStein\BladeFormatter;

use JoelStein\BladeFormatter\Formatters\IndentationFormatter;
use JoelStein\BladeFormatter\Formatters\PintFormatter;
use JoelStein\BladeFormatter\Formatters\TailwindFormatter;

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
        private string $prettierPath = 'npx',
    ) {}

    public static function fromConfig(): self
    {
        /** @var bool $enablePint */
        $enablePint = config('blade-formatter.enable_pint', true);
        /** @var bool $enableTailwindSort */
        $enableTailwindSort = config('blade-formatter.enable_tailwind_sort', true);
        /** @var bool $enableIndentation */
        $enableIndentation = config('blade-formatter.enable_indentation', true);
        /** @var int $indentSize */
        $indentSize = config('blade-formatter.indent_size', 4);
        /** @var string|null $pintConfigPath */
        $pintConfigPath = config('blade-formatter.pint_config_path');
        /** @var string $prettierPath */
        $prettierPath = config('blade-formatter.prettier_path', 'npx');

        return new self(
            enablePint: $enablePint,
            enableTailwindSort: $enableTailwindSort,
            enableIndentation: $enableIndentation,
            indentSize: $indentSize,
            pintConfigPath: $pintConfigPath,
            prettierPath: $prettierPath,
        );
    }

    /**
     * Format multiple files in batch, minimizing subprocess invocations.
     *
     * @param  array<string, string>  $files  Keyed by file path => content
     * @return array<string, string>  Formatted content, same keys
     */
    public function formatBatch(array $files): array
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

        // Phase 2: Batch Pint on all SFC PHP chunks
        $phpChunks = [];
        foreach ($parsed as $path => $parts) {
            if ($parts['isSfc']) {
                $phpChunks[$path] = $parts['php'];
            }
        }

        $formattedPhp = [];
        if ($this->enablePint && $phpChunks !== []) {
            try {
                $formattedPhp = (new PintFormatter)->formatBatch($phpChunks, $this->pintConfigPath);
            } catch (\Throwable $e) {
                foreach (array_keys($phpChunks) as $path) {
                    $this->warnings[$path][] = 'Pint skipped: '.$e->getMessage();
                }
            }
        }

        // Phase 3: Batch Tailwind sorting across all files
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

            if ($allClassStrings !== []) {
                try {
                    $sorted = $tailwindFormatter->sortClassStringsBatch($this->prettierPath, $allClassStrings);
                    if ($sorted !== null) {
                        $sortMap = [];
                        for ($i = 0; $i < count($allClassStrings); $i++) {
                            if ($allClassStrings[$i] !== $sorted[$i]) {
                                $sortMap[$allClassStrings[$i]] = $sorted[$i];
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    foreach (array_keys($files) as $path) {
                        if (($fileClassStrings[$path] ?? []) !== []) {
                            $this->warnings[$path][] = 'Tailwind sorting skipped: '.$e->getMessage();
                        }
                    }
                    $sortMap = [];
                }
            }
        }

        // Phase 4: Reassemble each file
        $indentationFormatter = new IndentationFormatter;
        $results = [];

        foreach ($files as $path => $originalContent) {
            $parts = $parsed[$path];
            $php = $formattedPhp[$path] ?? $parts['php'];
            $blade = $parts['isSfc'] ? $parts['blade'] : $originalContent;

            // Apply Tailwind sorting
            if ($this->enableTailwindSort && ! empty($sortMap ?? [])) {
                $blade = $tailwindFormatter->applySortedClasses($blade, $sortMap);
            }

            // Apply indentation
            if ($this->enableIndentation) {
                $blade = $indentationFormatter->format($blade, $this->indentSize);
            }

            $result = $parts['isSfc']
                ? Parser::assembleSfc($php, $blade)
                : $blade;

            // Collapse multiple consecutive blank lines
            $results[$path] = (string) preg_replace('/\n{3,}/', "\n\n", $result);
        }

        return $results;
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
}
