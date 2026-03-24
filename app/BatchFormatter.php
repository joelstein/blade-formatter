<?php

namespace App;

use App\Formatters\IndentationFormatter;
use App\Formatters\PintFormatter;
use App\Formatters\TailwindFormatter;
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

        // Phase 4: Reassemble each file
        $indentationFormatter = new IndentationFormatter;
        $results = [];

        foreach ($files as $path => $originalContent) {
            $parts = $parsed[$path];
            $php = $formattedPhp[$path] ?? $parts['php'];
            $blade = $parts['isSfc'] ? $parts['blade'] : $originalContent;

            // Apply indentation
            if ($this->enableIndentation) {
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
}
