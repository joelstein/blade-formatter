<?php

namespace Tests\Feature;

use LaravelZero\Framework\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DefaultCommandTest extends TestCase
{
    use \Illuminate\Foundation\Testing\Concerns\InteractsWithConsole;

    private string $tempDir;

    public function createApplication()
    {
        return require __DIR__.'/../../bootstrap/app.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/blade-fmt-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function it_formats_blade_files(): void
    {
        file_put_contents($this->tempDir.'/test.blade.php', "<div>\n<p>Hello</p>\n</div>");

        $this->artisan('default', ['path' => [$this->tempDir], '--config' => $this->configPath()])
            ->assertSuccessful();

        $this->assertSame("<div>\n    <p>Hello</p>\n</div>", file_get_contents($this->tempDir.'/test.blade.php'));
    }

    #[Test]
    public function it_reports_no_files_found(): void
    {
        $this->artisan('default', ['path' => ['/nonexistent/path'], '--config' => $this->configPath()])
            ->assertSuccessful();
    }

    #[Test]
    public function it_reports_already_formatted_files(): void
    {
        file_put_contents($this->tempDir.'/test.blade.php', "<div>\n    <p>Hello</p>\n</div>");

        $this->artisan('default', ['path' => [$this->tempDir], '--config' => $this->configPath()])
            ->assertSuccessful();
    }

    #[Test]
    public function test_mode_does_not_modify_files(): void
    {
        $original = "<div>\n<p>Hello</p>\n</div>";
        file_put_contents($this->tempDir.'/test.blade.php', $original);

        $this->artisan('default', ['path' => [$this->tempDir], '--test' => true, '--config' => $this->configPath()])
            ->assertFailed();

        $this->assertSame($original, file_get_contents($this->tempDir.'/test.blade.php'));
    }

    #[Test]
    public function test_mode_returns_success_when_no_changes_needed(): void
    {
        file_put_contents($this->tempDir.'/test.blade.php', "<div>\n    <p>Hello</p>\n</div>");

        $this->artisan('default', ['path' => [$this->tempDir], '--test' => true, '--config' => $this->configPath()])
            ->assertSuccessful();
    }

    #[Test]
    public function it_formats_multiple_files(): void
    {
        file_put_contents($this->tempDir.'/one.blade.php', "<div>\n<p>One</p>\n</div>");
        file_put_contents($this->tempDir.'/two.blade.php', "<div>\n<p>Two</p>\n</div>");

        $this->artisan('default', ['path' => [$this->tempDir], '--config' => $this->configPath()])
            ->assertSuccessful();

        $this->assertSame("<div>\n    <p>One</p>\n</div>", file_get_contents($this->tempDir.'/one.blade.php'));
        $this->assertSame("<div>\n    <p>Two</p>\n</div>", file_get_contents($this->tempDir.'/two.blade.php'));
    }

    #[Test]
    public function bail_stops_on_first_change(): void
    {
        // Create multiple files that need formatting
        file_put_contents($this->tempDir.'/a.blade.php', "<div>\n<p>A</p>\n</div>");
        file_put_contents($this->tempDir.'/b.blade.php', "<div>\n<p>B</p>\n</div>");

        $this->artisan('default', ['path' => [$this->tempDir], '--bail' => true, '--config' => $this->configPath()])
            ->assertFailed();

        // Neither file should be modified (bail implies test mode)
        $this->assertSame("<div>\n<p>A</p>\n</div>", file_get_contents($this->tempDir.'/a.blade.php'));
        $this->assertSame("<div>\n<p>B</p>\n</div>", file_get_contents($this->tempDir.'/b.blade.php'));
    }

    #[Test]
    public function it_handles_livewire_sfc(): void
    {
        $content = "<?php\n\nuse App\\Models\\User;\n?>\n\n<div>\n<p>Hello</p>\n</div>";
        file_put_contents($this->tempDir.'/component.blade.php', $content);

        $this->artisan('default', ['path' => [$this->tempDir], '--config' => $this->configPath()])
            ->assertSuccessful();

        $formatted = file_get_contents($this->tempDir.'/component.blade.php');
        $this->assertStringContainsString('    <p>Hello</p>', $formatted);
        $this->assertStringContainsString('<?php', $formatted);
    }

    #[Test]
    public function it_loads_config_file(): void
    {
        $configPath = $this->tempDir.'/blade-formatter.json';
        file_put_contents($configPath, json_encode([
            'indent_size' => 2,
            'enable_pint' => false,
            'enable_tailwind_sort' => false,
        ]));

        file_put_contents($this->tempDir.'/test.blade.php', "<div>\n<p>Hello</p>\n</div>");

        $this->artisan('default', ['path' => [$this->tempDir], '--config' => $configPath])
            ->assertSuccessful();

        $this->assertSame("<div>\n  <p>Hello</p>\n</div>", file_get_contents($this->tempDir.'/test.blade.php'));
    }

    #[Test]
    public function it_accepts_single_file_path(): void
    {
        $file = $this->tempDir.'/test.blade.php';
        file_put_contents($file, "<div>\n<p>Hello</p>\n</div>");

        $this->artisan('default', ['path' => [$file], '--config' => $this->configPath()])
            ->assertSuccessful();

        $this->assertSame("<div>\n    <p>Hello</p>\n</div>", file_get_contents($file));
    }

    #[Test]
    public function it_skips_envoy_files(): void
    {
        // Indentation inside @task is passed to the shell, so it must survive untouched.
        $unformatted = "@servers(['web' => 'user@host'])\n\n@task('deploy')\n@if (\$production)\nphp artisan down\n@endif\ncd /app\n@endtask";

        foreach (['Envoy.blade.php', 'envoy.blade.php'] as $name) {
            file_put_contents($this->tempDir.'/'.$name, $unformatted);

            $this->artisan('default', ['path' => [$this->tempDir.'/'.$name], '--config' => $this->configPath()])
                ->assertSuccessful();

            $this->assertSame($unformatted, file_get_contents($this->tempDir.'/'.$name));
            unlink($this->tempDir.'/'.$name);
        }
    }

    #[Test]
    public function it_skips_boost_guidelines(): void
    {
        $dir = $this->tempDir.'/resources/boost/guidelines';
        mkdir($dir, 0755, true);
        $unformatted = "<div>\n<p>Indentation is meaningful in Markdown.</p>\n</div>";
        file_put_contents($dir.'/core.blade.php', $unformatted);

        $this->artisan('default', ['path' => [$dir], '--config' => $this->configPath()])
            ->assertSuccessful();

        $this->assertSame($unformatted, file_get_contents($dir.'/core.blade.php'));
    }

    #[Test]
    public function it_still_formats_files_outside_ignored_conventions(): void
    {
        // A guidelines directory that is not under resources/boost/ is ordinary.
        $dir = $this->tempDir.'/docs/guidelines';
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/core.blade.php', "<div>\n<p>Hello</p>\n</div>");

        $this->artisan('default', ['path' => [$dir], '--config' => $this->configPath()])
            ->assertSuccessful();

        $this->assertSame("<div>\n    <p>Hello</p>\n</div>", file_get_contents($dir.'/core.blade.php'));
    }

    #[Test]
    public function dirty_only_formats_files_with_uncommitted_changes(): void
    {
        $this->initRepository();

        $unformatted = "<div>\n<p>Hello</p>\n</div>";
        file_put_contents($this->tempDir.'/committed.blade.php', $unformatted);
        $this->git('add -A');
        $this->git('-c user.email=t@t -c user.name=T commit -m init');

        // One tracked file modified, one brand new and untracked.
        file_put_contents($this->tempDir.'/committed.blade.php', $unformatted."\n<span>Changed</span>");
        file_put_contents($this->tempDir.'/untracked.blade.php', $unformatted);

        // A committed, unmodified file that must be left alone.
        file_put_contents($this->tempDir.'/clean.blade.php', $unformatted);
        $this->git('add clean.blade.php');
        $this->git('-c user.email=t@t -c user.name=T commit -m clean');

        $cwd = getcwd();
        chdir($this->tempDir);

        try {
            $this->artisan('default', ['--dirty' => true, '--config' => $this->configPath()])
                ->assertSuccessful();
        } finally {
            chdir($cwd);
        }

        $this->assertStringContainsString('    <p>Hello</p>', (string) file_get_contents($this->tempDir.'/committed.blade.php'));
        $this->assertStringContainsString('    <p>Hello</p>', (string) file_get_contents($this->tempDir.'/untracked.blade.php'));
        $this->assertSame($unformatted, file_get_contents($this->tempDir.'/clean.blade.php'));
    }

    #[Test]
    public function dirty_reports_no_files_when_the_tree_is_clean(): void
    {
        $this->initRepository();
        file_put_contents($this->tempDir.'/test.blade.php', "<div>\n<p>Hello</p>\n</div>");
        $this->git('add -A');
        $this->git('-c user.email=t@t -c user.name=T commit -m init');

        $cwd = getcwd();
        chdir($this->tempDir);

        try {
            $this->artisan('default', ['--dirty' => true, '--config' => $this->configPath()])
                ->assertSuccessful();
        } finally {
            chdir($cwd);
        }

        $this->assertSame("<div>\n<p>Hello</p>\n</div>", file_get_contents($this->tempDir.'/test.blade.php'));
    }

    #[Test]
    public function repair_fixes_files_but_fails(): void
    {
        file_put_contents($this->tempDir.'/test.blade.php', "<div>\n<p>Hello</p>\n</div>");

        $this->artisan('default', ['path' => [$this->tempDir], '--repair' => true, '--config' => $this->configPath()])
            ->assertFailed();

        $this->assertSame("<div>\n    <p>Hello</p>\n</div>", file_get_contents($this->tempDir.'/test.blade.php'));
    }

    #[Test]
    public function repair_succeeds_when_nothing_changed(): void
    {
        file_put_contents($this->tempDir.'/test.blade.php', "<div>\n    <p>Hello</p>\n</div>");

        $this->artisan('default', ['path' => [$this->tempDir], '--repair' => true, '--config' => $this->configPath()])
            ->assertSuccessful();
    }

    #[Test]
    public function repair_combined_with_test_does_not_write(): void
    {
        $unformatted = "<div>\n<p>Hello</p>\n</div>";
        file_put_contents($this->tempDir.'/test.blade.php', $unformatted);

        $this->artisan('default', ['path' => [$this->tempDir], '--repair' => true, '--test' => true, '--config' => $this->configPath()])
            ->assertFailed();

        $this->assertSame($unformatted, file_get_contents($this->tempDir.'/test.blade.php'));
    }

    #[Test]
    public function it_leaves_markdown_mail_views_untouched_in_every_mode(): void
    {
        $this->initRepository();

        // A published mail view, matched by its `vendor/mail` path...
        mkdir($this->tempDir.'/resources/views/vendor/mail/html', 0755, true);
        $published = $this->tempDir.'/resources/views/vendor/mail/html/message.blade.php';

        // ...and one of the project's own mail templates, matched by the
        // `x-mail::` components it builds the message from.
        mkdir($this->tempDir.'/resources/views/emails', 0755, true);
        $own = $this->tempDir.'/resources/views/emails/order.blade.php';

        $unformatted = "<x-mail::layout>\n<x-slot:header>\n<x-mail::header :url=\"config('app.url')\">\n{{ config('app.name') }}\n</x-mail::header>\n</x-slot:header>\n\n{{ \$slot }}\n</x-mail::layout>";

        file_put_contents($published, $unformatted);
        file_put_contents($own, $unformatted);

        $assertUntouched = function () use ($published, $own, $unformatted): void {
            $this->assertSame($unformatted, file_get_contents($published));
            $this->assertSame($unformatted, file_get_contents($own));
        };

        $cwd = getcwd();
        chdir($this->tempDir);

        try {
            // Named explicitly on the command line.
            $this->artisan('default', ['path' => [$published, $own], '--config' => $this->configPath()])
                ->assertSuccessful();
            $assertUntouched();

            // Discovered by scanning a directory.
            $this->artisan('default', ['path' => [$this->tempDir], '--config' => $this->configPath()])
                ->assertSuccessful();
            $assertUntouched();

            // Untracked, so picked up as dirty.
            $this->artisan('default', ['--dirty' => true, '--config' => $this->configPath()])
                ->assertSuccessful();
            $assertUntouched();

            // Modified since a ref, so picked up by --diff.
            $this->git('add -A');
            $this->git('-c user.email=t@t -c user.name=T commit -m init');

            $changed = str_replace(
                '{{ $slot }}',
                "{{ \$slot }}\n\n<x-mail::button :url=\"\$url\">\nView order\n</x-mail::button>",
                $unformatted,
            );
            file_put_contents($published, $changed);
            file_put_contents($own, $changed);

            $this->artisan('default', ['--diff' => 'HEAD', '--config' => $this->configPath()])
                ->assertSuccessful();

            $this->assertSame($changed, file_get_contents($published));
            $this->assertSame($changed, file_get_contents($own));
        } finally {
            chdir($cwd);
        }
    }

    #[Test]
    public function exclude_patterns_apply_to_every_run_mode(): void
    {
        $this->initRepository();

        $unformatted = "<div>\n<p>Hello</p>\n</div>";
        $formatted = "<div>\n    <p>Hello</p>\n</div>";

        mkdir($this->tempDir.'/emails/legacy', 0755, true);
        $excluded = $this->tempDir.'/emails/legacy/receipt.blade.php';
        $included = $this->tempDir.'/page.blade.php';

        $config = $this->configPath(['exclude' => ['emails/legacy']]);

        $cwd = getcwd();
        chdir($this->tempDir);

        try {
            // Named explicitly on the command line.
            file_put_contents($excluded, $unformatted);
            file_put_contents($included, $unformatted);

            $this->artisan('default', ['path' => [$excluded, $included], '--config' => $config])
                ->assertSuccessful();

            $this->assertSame($unformatted, file_get_contents($excluded));
            $this->assertSame($formatted, file_get_contents($included));

            // Untracked, so picked up as dirty.
            file_put_contents($included, $unformatted);

            $this->artisan('default', ['--dirty' => true, '--config' => $config])
                ->assertSuccessful();

            $this->assertSame($unformatted, file_get_contents($excluded));
            $this->assertSame($formatted, file_get_contents($included));

            // Modified since a ref, so picked up by --diff.
            $this->git('add -A');
            $this->git('-c user.email=t@t -c user.name=T commit -m init');
            $changed = $unformatted."\n<span>Changed</span>";
            file_put_contents($excluded, $changed);
            file_put_contents($included, $unformatted);

            $this->artisan('default', ['--diff' => 'HEAD', '--config' => $config])
                ->assertSuccessful();

            $this->assertSame($changed, file_get_contents($excluded));
            $this->assertSame($formatted, file_get_contents($included));
        } finally {
            chdir($cwd);
        }
    }

    private function initRepository(): void
    {
        $this->configPath();
        $this->git('init -q');
    }

    private function git(string $args): void
    {
        exec('git -C '.escapeshellarg($this->tempDir).' '.$args.' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->fail('git '.$args.' failed: '.implode("\n", $output));
        }
    }

    /**
     * Create a minimal config file that disables Pint and Tailwind (no external deps needed).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function configPath(array $overrides = []): string
    {
        $configPath = $this->tempDir.'/blade-formatter.json';

        file_put_contents($configPath, json_encode(array_merge([
            'enable_pint' => false,
            'enable_tailwind_sort' => false,
        ], $overrides)));

        return $configPath;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
