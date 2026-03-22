<?php

namespace JoelStein\BladeFormatter\Tests\Feature;

use Illuminate\Support\Facades\File;
use JoelStein\BladeFormatter\BladeFormatterServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FormatCommandTest extends TestCase
{
    private string $tempDir;

    protected function getPackageProviders($app): array
    {
        return [BladeFormatterServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/blade-fmt-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        config([
            'blade-formatter.paths' => [$this->tempDir],
            'blade-formatter.enable_pint' => false,
            'blade-formatter.enable_tailwind_sort' => false,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function it_formats_blade_files(): void
    {
        file_put_contents($this->tempDir.'/test.blade.php', "<div>\n<p>Hello</p>\n</div>");

        $this->artisan('blade:format')
            ->assertSuccessful();

        $this->assertSame("<div>\n    <p>Hello</p>\n</div>", file_get_contents($this->tempDir.'/test.blade.php'));
    }

    #[Test]
    public function it_reports_no_files_found(): void
    {
        config(['blade-formatter.paths' => ['/nonexistent/path']]);

        $this->artisan('blade:format')
            ->assertSuccessful()
            ->expectsOutputToContain('No Blade files found.');
    }

    #[Test]
    public function it_reports_already_formatted_files(): void
    {
        file_put_contents($this->tempDir.'/test.blade.php', "<div>\n    <p>Hello</p>\n</div>");

        $this->artisan('blade:format')
            ->assertSuccessful()
            ->expectsOutputToContain('All files are already formatted.');
    }

    #[Test]
    public function test_mode_returns_failure_when_changes_needed(): void
    {
        file_put_contents($this->tempDir.'/test.blade.php', "<div>\n<p>Hello</p>\n</div>");

        $this->artisan('blade:format', ['--test' => true])
            ->assertFailed();

        // File should not be modified in test mode
        $this->assertSame("<div>\n<p>Hello</p>\n</div>", file_get_contents($this->tempDir.'/test.blade.php'));
    }

    #[Test]
    public function test_mode_returns_success_when_no_changes_needed(): void
    {
        file_put_contents($this->tempDir.'/test.blade.php', "<div>\n    <p>Hello</p>\n</div>");

        $this->artisan('blade:format', ['--test' => true])
            ->assertSuccessful();
    }

    #[Test]
    public function it_formats_multiple_files(): void
    {
        file_put_contents($this->tempDir.'/one.blade.php', "<div>\n<p>One</p>\n</div>");
        file_put_contents($this->tempDir.'/two.blade.php', "<div>\n<p>Two</p>\n</div>");

        $this->artisan('blade:format')
            ->assertSuccessful();

        $this->assertSame("<div>\n    <p>One</p>\n</div>", file_get_contents($this->tempDir.'/one.blade.php'));
        $this->assertSame("<div>\n    <p>Two</p>\n</div>", file_get_contents($this->tempDir.'/two.blade.php'));
    }

    #[Test]
    public function it_accepts_path_argument(): void
    {
        $subDir = $this->tempDir.'/sub';
        mkdir($subDir);
        file_put_contents($subDir.'/test.blade.php', "<div>\n<p>Hello</p>\n</div>");

        $this->artisan('blade:format', ['path' => [$subDir]])
            ->assertSuccessful();

        $this->assertSame("<div>\n    <p>Hello</p>\n</div>", file_get_contents($subDir.'/test.blade.php'));
    }

    #[Test]
    public function it_handles_livewire_sfc_indentation(): void
    {
        $content = "<?php\n\nuse App\\Models\\User;\n?>\n\n<div>\n<p>Hello</p>\n</div>";

        file_put_contents($this->tempDir.'/component.blade.php', $content);

        $this->artisan('blade:format')
            ->assertSuccessful();

        $formatted = file_get_contents($this->tempDir.'/component.blade.php');
        $this->assertStringContainsString('    <p>Hello</p>', $formatted);
        $this->assertStringContainsString('<?php', $formatted);
    }
}
