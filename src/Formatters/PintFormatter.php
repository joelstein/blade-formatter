<?php

namespace JoelStein\BladeFormatter\Formatters;

use RuntimeException;
use Symfony\Component\Process\Process;

class PintFormatter
{
    public function format(string $phpCode, ?string $configPath = null): string
    {
        $results = $this->formatBatch(['single' => $phpCode], $configPath);

        return $results['single'];
    }

    /**
     * Format multiple PHP chunks in a single Pint invocation.
     *
     * @param  array<string, string>  $phpChunks  Keyed by identifier
     * @return array<string, string> Formatted chunks, same keys
     */
    public function formatBatch(array $phpChunks, ?string $configPath = null): array
    {
        if ($phpChunks === []) {
            return [];
        }

        $pintPath = $this->resolvePintPath();
        $tmpDir = sys_get_temp_dir().'/blade-fmt-'.uniqid();
        mkdir($tmpDir);

        try {
            $keyMap = [];
            $index = 0;
            foreach ($phpChunks as $key => $phpCode) {
                $filename = $index.'.php';
                $keyMap[$filename] = $key;
                file_put_contents($tmpDir.'/'.$filename, $phpCode);
                $index++;
            }

            $command = [$pintPath, $tmpDir, '--quiet'];
            if ($configPath !== null) {
                $command[] = '--config';
                $command[] = $configPath;
            }

            $process = new Process($command);
            $process->setTimeout(30);
            $process->run();

            // Pint exits with 1 when it makes changes, which is fine
            if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
                throw new RuntimeException('Pint failed: '.($process->getErrorOutput() ?: $process->getOutput()));
            }

            $results = [];
            foreach ($keyMap as $filename => $key) {
                $formatted = rtrim((string) file_get_contents($tmpDir.'/'.$filename));

                // Pint strips the closing PHP tag (PSR-12), but SFCs need it
                if (! str_ends_with($formatted, '?'.'>')) {
                    $formatted .= "\n".'?'.'>';
                }

                $results[$key] = $formatted;
            }

            return $results;
        } finally {
            // Clean up temp files
            foreach (glob($tmpDir.'/*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        }
    }

    public static function resolvePintPath(): string
    {
        // Check for Pint in the project vendor directory
        try {
            $basePath = base_path();
        } catch (\Throwable) {
            $basePath = getcwd();
        }
        $projectPint = $basePath.'/vendor/bin/pint';
        if (file_exists($projectPint)) {
            return $projectPint;
        }

        // Fall back to the package's own dependency
        $packagePint = dirname(__DIR__, 2).'/vendor/bin/pint';
        if (file_exists($packagePint)) {
            return $packagePint;
        }

        throw new RuntimeException('Laravel Pint not found. Install it with: composer require laravel/pint');
    }
}
