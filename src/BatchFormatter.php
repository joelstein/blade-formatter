<?php

namespace JoelStein\BladeFormatter;

use JoelStein\BladeFormatter\Formatters\IndentationFormatter;
use JoelStein\BladeFormatter\Formatters\PintFormatter;
use JoelStein\BladeFormatter\Formatters\TailwindFormatter;
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
        private string $prettierPath = 'npx',
        private bool $parallel = false,
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

    public function setParallel(bool $parallel): self
    {
        $this->parallel = $parallel;

        return $this;
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

        // Phase 2: Collect work for Pint and Tailwind
        $phpChunks = [];
        foreach ($parsed as $path => $parts) {
            if ($parts['isSfc']) {
                $phpChunks[$path] = $parts['php'];
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

        // Phase 3: Run Pint and Prettier (concurrently if parallel)
        if ($this->parallel) {
            [$formattedPhp, $sortMap] = $this->runParallel($phpChunks, $tailwindFormatter, $allClassStrings, $fileClassStrings);
        } else {
            [$formattedPhp, $sortMap] = $this->runSequential($phpChunks, $tailwindFormatter, $allClassStrings, $fileClassStrings);
        }

        // Phase 4: Reassemble each file
        $indentationFormatter = new IndentationFormatter;
        $results = [];

        foreach ($files as $path => $originalContent) {
            $parts = $parsed[$path];
            $php = $formattedPhp[$path] ?? $parts['php'];
            $blade = $parts['isSfc'] ? $parts['blade'] : $originalContent;

            // Apply Tailwind sorting
            if ($this->enableTailwindSort && $sortMap !== []) {
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
     * Run Pint and Prettier sequentially.
     *
     * @param  array<string, string>  $phpChunks
     * @param  list<string>  $allClassStrings
     * @param  array<string, list<string>>  $fileClassStrings
     * @return array{array<string, string>, array<string, string>}
     */
    private function runSequential(array $phpChunks, TailwindFormatter $tailwindFormatter, array $allClassStrings, array $fileClassStrings): array
    {
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

        $sortMap = [];
        if ($this->enableTailwindSort && $allClassStrings !== []) {
            try {
                $sorted = $tailwindFormatter->sortClassStringsBatch($this->prettierPath, $allClassStrings);
                if ($sorted !== null) {
                    for ($i = 0; $i < count($allClassStrings); $i++) {
                        if ($allClassStrings[$i] !== $sorted[$i]) {
                            $sortMap[$allClassStrings[$i]] = $sorted[$i];
                        }
                    }
                }
            } catch (\Throwable $e) {
                foreach ($fileClassStrings as $path => $classStrings) {
                    if ($classStrings !== []) {
                        $this->warnings[$path][] = 'Tailwind sorting skipped: '.$e->getMessage();
                    }
                }
            }
        }

        return [$formattedPhp, $sortMap];
    }

    /**
     * Run Pint and Prettier concurrently using async processes.
     *
     * @param  array<string, string>  $phpChunks
     * @param  list<string>  $allClassStrings
     * @param  array<string, list<string>>  $fileClassStrings
     * @return array{array<string, string>, array<string, string>}
     */
    private function runParallel(array $phpChunks, TailwindFormatter $tailwindFormatter, array $allClassStrings, array $fileClassStrings): array
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
}
