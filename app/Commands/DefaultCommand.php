<?php

namespace App\Commands;

use App\BatchFormatter;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Finder\Finder;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class DefaultCommand extends Command
{
    private const EXCLUDE_DEFAULTS = [
        'vendor/mail',
        'vendor/notifications',
    ];

    protected $signature = 'default
                            {path?* : Files or directories to format}
                            {--test : Check formatting without making changes}
                            {--bail : Stop on first file that would change (implies --test)}
                            {--diff= : Only format files changed since a branch (defaults to main)}
                            {--config= : Path to blade-formatter.json config file}';

    protected $description = 'Format Blade templates and Livewire SFCs';

    public function handle(): int
    {
        $startTime = microtime(true);

        $config = $this->loadConfig();

        $formatter = new BatchFormatter(
            enablePint: $config['enable_pint'] ?? true,
            enableTailwindSort: $config['enable_tailwind_sort'] ?? true,
            enableIndentation: $config['enable_indentation'] ?? true,
            indentSize: $config['indent_size'] ?? 4,
            pintConfigPath: $config['pint_config_path'] ?? null,
            prettierPath: $config['prettier_path'] ?? 'node_modules/.bin/prettier',
        );

        $files = $this->resolveFiles($config);

        if ($files === []) {
            info('No Blade files found.');

            return self::SUCCESS;
        }

        // Read all file contents
        $fileContents = [];
        foreach ($files as $file) {
            $fileContents[$file] = (string) file_get_contents($file);
        }

        $changed = [];
        $unchanged = 0;
        $isTest = (bool) $this->option('test') || (bool) $this->option('bail');
        $isBail = (bool) $this->option('bail');
        $warnings = [];
        $bailed = false;

        // Progress dots (Pint-style)
        $symbolsPerLine = (new Terminal)->getWidth() - 4;
        $processed = 0;

        $this->newLine();
        $this->output->write('  ');

        $formatter->formatBatch($fileContents, function (string $path, string $formatted) use ($fileContents, $isTest, $isBail, $formatter, &$changed, &$unchanged, &$warnings, &$processed, &$bailed, $symbolsPerLine) {
            if ($bailed) {
                return;
            }

            if ($processed > 0 && $processed % $symbolsPerLine === 0) {
                $this->newLine();
                $this->output->write('  ');
            }

            foreach ($formatter->getWarnings($path) as $warn) {
                $warnings[] = $warn;
            }

            if ($fileContents[$path] === $formatted) {
                $this->output->write('<fg=gray>.</>');
                $unchanged++;
            } else {
                if (! $isTest) {
                    file_put_contents($path, $formatted);
                }

                if ($isTest) {
                    $this->output->write('<fg=yellow;options=bold>⨯</>');
                } else {
                    $this->output->write('<fg=green;options=bold>✓</>');
                }

                $changed[] = $path;

                if ($isBail) {
                    $bailed = true;
                }
            }

            $processed++;
        });

        $this->newLine(2);

        // Changed file list
        foreach ($changed as $file) {
            $relativePath = $this->relativePath($file);

            if ($isTest) {
                $this->components->twoColumnDetail($relativePath, '<fg=yellow;options=bold>WOULD CHANGE</>');
            } else {
                $this->components->twoColumnDetail($relativePath, '<fg=green;options=bold>FIXED</>');
            }
        }

        // Warnings
        foreach ($warnings as $warn) {
            warning($warn);
        }

        $elapsed = round((microtime(true) - $startTime) * 1000);
        $changedCount = count($changed);

        $this->newLine();

        if ($changedCount === 0) {
            info("All files already formatted. ({$elapsed}ms)");

            return self::SUCCESS;
        }

        $verb = $isTest ? 'would be formatted' : 'formatted';
        info("{$changedCount} file(s) {$verb}, {$unchanged} already formatted. ({$elapsed}ms)");

        return $isTest ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        /** @var string|null $configPath */
        $configPath = $this->option('config');

        if ($configPath === null) {
            $configPath = getcwd().'/blade-formatter.json';
            if (! file_exists($configPath)) {
                return [];
            }
        }

        if (! file_exists($configPath)) {
            $this->components->error("Config file not found: {$configPath}");

            return [];
        }

        $json = file_get_contents($configPath);
        if ($json === false) {
            $this->components->error("Could not read config file: {$configPath}");

            return [];
        }

        $config = json_decode($json, true);
        if (! is_array($config)) {
            $this->components->error("Invalid JSON in config file: {$configPath}");

            return [];
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function resolveFiles(array $config): array
    {
        /** @var string|null $diff */
        $diff = $this->option('diff');
        if ($diff !== null) {
            return $this->getChangedFiles($diff ?: 'main');
        }

        /** @var list<string> $paths */
        $paths = $this->argument('path');

        if (! empty($paths)) {
            return $this->resolvePathsToFiles($paths);
        }

        // Default: scan configured paths
        /** @var list<string> $defaultPaths */
        $defaultPaths = $config['paths'] ?? ['resources/views'];
        $cwd = (string) getcwd();
        $absolutePaths = array_map(
            fn (string $p): string => is_dir($p) ? $p : $cwd.'/'.$p,
            $defaultPaths,
        );
        $absolutePaths = array_filter($absolutePaths, 'is_dir');

        if (empty($absolutePaths)) {
            return [];
        }

        /** @var list<string> $userExclude */
        $userExclude = $config['exclude'] ?? [];
        $exclude = array_values(array_unique(array_merge(self::EXCLUDE_DEFAULTS, $userExclude)));

        return $this->findBladeFiles($absolutePaths, $exclude);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function resolvePathsToFiles(array $paths): array
    {
        $files = [];
        $dirs = [];

        foreach ($paths as $path) {
            $absolute = file_exists($path) ? realpath($path) : getcwd().'/'.$path;
            if ($absolute === false) {
                continue;
            }

            if (is_file($absolute) && str_ends_with($absolute, '.blade.php')) {
                $files[] = $absolute;
            } elseif (is_dir($absolute)) {
                $dirs[] = $absolute;
            }
        }

        if (! empty($dirs)) {
            $files = array_merge($files, $this->findBladeFiles($dirs, self::EXCLUDE_DEFAULTS));
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * @param  list<string>  $directories
     * @param  list<string>  $exclude
     * @return list<string>
     */
    private function findBladeFiles(array $directories, array $exclude): array
    {
        $finder = Finder::create()
            ->files()
            ->name('*.blade.php')
            ->in($directories);

        foreach ($exclude as $pattern) {
            $finder->notPath($pattern);
        }

        $files = [];
        foreach ($finder as $file) {
            $files[] = (string) $file->getRealPath();
        }

        sort($files);

        return $files;
    }

    /**
     * Get Blade files changed relative to a git ref.
     *
     * @return list<string>
     */
    private function getChangedFiles(string $ref): array
    {
        exec("git diff --name-only --diff-filter=ACMR {$ref} 2>/dev/null", $output, $exitCode);

        if ($exitCode !== 0) {
            $this->components->error("Failed to get changed files from git ref: {$ref}");

            return [];
        }

        $cwd = (string) getcwd();
        $files = [];
        foreach ($output as $line) {
            if (str_ends_with($line, '.blade.php') && $line !== '') {
                $files[] = $cwd.'/'.$line;
            }
        }

        return $files;
    }

    private function relativePath(string $path): string
    {
        $cwd = (string) getcwd();

        return str_starts_with($path, $cwd.'/')
            ? substr($path, strlen($cwd) + 1)
            : $path;
    }
}
