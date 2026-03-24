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

    /**
     * Create a minimal config file that disables Pint and Tailwind (no external deps needed).
     */
    private function configPath(): string
    {
        $configPath = $this->tempDir.'/blade-formatter.json';

        if (! file_exists($configPath)) {
            file_put_contents($configPath, json_encode([
                'enable_pint' => false,
                'enable_tailwind_sort' => false,
            ]));
        }

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
