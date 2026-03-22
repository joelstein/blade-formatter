<?php

namespace JoelStein\BladeFormatter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use JoelStein\BladeFormatter\BatchFormatter;
use Symfony\Component\Finder\Finder;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class FormatCommand extends Command
{
    public const EXCLUDE_DEFAULTS = [
        'vendor/mail',
        'vendor/notifications',
    ];

    protected $signature = 'blade:format
                            {path?* : Paths to format (overrides config)}
                            {--test : Check formatting without making changes}
                            {--dirty : Only format git-dirty files}
                            {--parallel : Run Pint and Prettier concurrently}';

    protected $description = 'Format Blade templates and Livewire SFCs';

    public function handle(): int
    {
        $startTime = microtime(true);

        $formatter = BatchFormatter::fromConfig();

        if ($this->option('parallel')) {
            $formatter->setParallel(true);
        }

        $files = $this->resolveFiles();

        if ($files === []) {
            info('No Blade files found.');

            return self::SUCCESS;
        }

        // Read all file contents
        $fileContents = [];
        foreach ($files as $file) {
            $fileContents[$file] = (string) file_get_contents($file);
        }

        // Batch format with spinner
        $results = spin(
            fn () => $formatter->formatBatch($fileContents),
            'Formatting '.count($files).' file(s)...',
        );

        $changed = 0;
        $unchanged = 0;
        $isTest = (bool) $this->option('test');

        foreach ($results as $file => $formatted) {
            foreach ($formatter->getWarnings($file) as $warn) {
                warning($warn);
            }

            if ($fileContents[$file] === $formatted) {
                $unchanged++;

                continue;
            }

            $relativePath = $this->relativePath($file);

            if ($isTest) {
                $this->components->twoColumnDetail($relativePath, '<fg=yellow;options=bold>WOULD CHANGE</>');
            } else {
                file_put_contents($file, $formatted);
                $this->components->twoColumnDetail($relativePath, '<fg=green;options=bold>FIXED</>');
            }

            $changed++;
        }

        $elapsed = round((microtime(true) - $startTime) * 1000);

        $this->newLine();

        if ($changed === 0) {
            info("All files already formatted. ({$elapsed}ms)");

            return self::SUCCESS;
        }

        $verb = $isTest ? 'would be formatted' : 'formatted';
        info("{$changed} file(s) {$verb}, {$unchanged} already formatted. ({$elapsed}ms)");

        return $isTest ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveFiles(): array
    {
        if ($this->option('dirty')) {
            return $this->getDirtyFiles();
        }

        /** @var list<string> $paths */
        $paths = $this->argument('path');
        if (empty($paths)) {
            /** @var list<string> $paths */
            $paths = config('blade-formatter.paths', ['resources/views']);
        }

        /** @var list<string> $userExclude */
        $userExclude = config('blade-formatter.exclude', []);
        $exclude = array_values(array_unique(array_merge(self::EXCLUDE_DEFAULTS, $userExclude)));

        $files = [];
        $dirs = [];

        foreach ($paths as $path) {
            $absolute = file_exists($path) ? $path : base_path($path);

            if (is_file($absolute) && str_ends_with($absolute, '.blade.php')) {
                $files[] = (string) realpath($absolute);
            } elseif (is_dir($absolute)) {
                $dirs[] = $absolute;
            }
        }

        if (! empty($dirs)) {
            $finder = Finder::create()
                ->files()
                ->name('*.blade.php')
                ->in($dirs);

            foreach ($exclude as $pattern) {
                $finder->notPath($pattern);
            }

            foreach ($finder as $file) {
                $files[] = (string) $file->getRealPath();
            }
        }

        if (empty($files)) {
            return [];
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function getDirtyFiles(): array
    {
        $result = Process::run('git diff --name-only --diff-filter=ACMR HEAD');

        if (! $result->successful()) {
            $this->components->error('Failed to get git dirty files.');

            return [];
        }

        $lines = array_filter(explode("\n", trim($result->output())));

        $files = [];
        foreach ($lines as $line) {
            if (str_ends_with($line, '.blade.php')) {
                $files[] = base_path($line);
            }
        }

        return $files;
    }

    private function relativePath(string $path): string
    {
        $basePath = base_path().'/';

        return str_starts_with($path, $basePath)
            ? substr($path, strlen($basePath))
            : $path;
    }
}
