<?php

namespace JoelStein\BladeFormatter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use JoelStein\BladeFormatter\Formatter;
use Symfony\Component\Finder\Finder;

class FormatCommand extends Command
{
    protected $signature = 'blade:format
                            {path?* : Paths to format (overrides config)}
                            {--test : Check formatting without making changes}
                            {--dirty : Only format git-dirty files}';

    protected $description = 'Format Blade templates and Livewire SFCs';

    public function handle(): int
    {
        $formatter = Formatter::fromConfig();
        $files = $this->resolveFiles();

        if ($files === []) {
            $this->components->info('No Blade files found.');

            return self::SUCCESS;
        }

        $changed = 0;
        $unchanged = 0;
        $isTest = (bool) $this->option('test');

        foreach ($files as $file) {
            $original = (string) file_get_contents($file);
            $formatted = $formatter->format($original);

            foreach ($formatter->getWarnings() as $warning) {
                $this->components->warn($warning);
            }

            if ($original === $formatted) {
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

        if ($changed === 0) {
            $this->components->info('All files are already formatted.');

            return self::SUCCESS;
        }

        $verb = $isTest ? 'would be formatted' : 'formatted';
        $this->newLine();
        $this->components->info("{$changed} file(s) {$verb}, {$unchanged} already formatted.");

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

        /** @var list<string> $exclude */
        $exclude = config('blade-formatter.exclude', []);

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
