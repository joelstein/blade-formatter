<?php

namespace Tests\Unit;

use BladeFormatter\Formatters\PintFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PintFormatterTest extends TestCase
{
    /** @var list<string> */
    private array $vendorDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->vendorDirs as $vendor) {
            @unlink($vendor.'/composer/installed.php');
            @rmdir($vendor.'/composer');
            @rmdir($vendor.'/bin');
            @rmdir($vendor);
        }

        parent::tearDown();
    }

    /**
     * Create a vendor directory whose Composer metadata reports the given Pint
     * version, and return the path Pint would have inside it.
     */
    private function pintPathForVersion(?string $version): string
    {
        $vendor = sys_get_temp_dir().'/blade-fmt-test-vendor-'.uniqid();
        mkdir($vendor.'/bin', 0777, true);
        mkdir($vendor.'/composer');
        $this->vendorDirs[] = $vendor;

        $versions = $version === null ? [] : ['laravel/pint' => ['version' => $version]];
        file_put_contents(
            $vendor.'/composer/installed.php',
            '<?php return '.var_export(['versions' => $versions], true).';',
        );

        return $vendor.'/bin/pint';
    }

    #[Test]
    public function it_reads_the_installed_pint_version(): void
    {
        $this->assertSame('1.32.2.0', PintFormatter::installedPintVersion($this->pintPathForVersion('1.32.2.0')));
    }

    #[Test]
    public function it_returns_no_version_when_pint_is_not_in_the_composer_metadata(): void
    {
        $this->assertNull(PintFormatter::installedPintVersion($this->pintPathForVersion(null)));
    }

    #[Test]
    public function it_returns_no_version_when_there_is_no_composer_metadata(): void
    {
        $this->assertNull(PintFormatter::installedPintVersion(sys_get_temp_dir().'/blade-fmt-missing/bin/pint'));
    }

    #[Test]
    public function it_supports_parallel_from_the_release_that_introduced_it(): void
    {
        $this->assertFalse(PintFormatter::supportsParallel($this->pintPathForVersion('1.22.1.0')));
        $this->assertTrue(PintFormatter::supportsParallel($this->pintPathForVersion('1.23.0.0')));
        $this->assertTrue(PintFormatter::supportsParallel($this->pintPathForVersion('1.32.2.0')));
    }

    #[Test]
    public function it_does_not_assume_parallel_support_for_an_unknown_version(): void
    {
        $this->assertFalse(PintFormatter::supportsParallel($this->pintPathForVersion(null)));
    }

    #[Test]
    public function it_runs_a_large_batch_in_parallel(): void
    {
        $pint = $this->pintPathForVersion('1.32.2.0');

        $this->assertSame(
            [$pint, '/tmp/chunks', '--quiet', '--parallel'],
            PintFormatter::buildPintCommand($pint, '/tmp/chunks', 16),
        );
    }

    #[Test]
    public function it_runs_a_small_batch_in_a_single_process(): void
    {
        $pint = $this->pintPathForVersion('1.32.2.0');

        $this->assertSame(
            [$pint, '/tmp/chunks', '--quiet'],
            PintFormatter::buildPintCommand($pint, '/tmp/chunks', 15),
        );
    }

    #[Test]
    public function it_does_not_pass_parallel_to_a_pint_that_predates_it(): void
    {
        $pint = $this->pintPathForVersion('1.22.1.0');

        $this->assertSame(
            [$pint, '/tmp/chunks', '--quiet'],
            PintFormatter::buildPintCommand($pint, '/tmp/chunks', 500),
        );
    }

    #[Test]
    public function it_passes_the_config_path_to_pint(): void
    {
        $pint = $this->pintPathForVersion('1.32.2.0');

        $this->assertSame(
            [$pint, '/tmp/chunks', '--quiet', '--config', 'pint.json', '--parallel'],
            PintFormatter::buildPintCommand($pint, '/tmp/chunks', 16, 'pint.json'),
        );
    }

    #[Test]
    public function it_formats_a_batch_large_enough_to_run_in_parallel(): void
    {
        $chunks = [];
        $expected = [];
        for ($i = 0; $i < 20; $i++) {
            $chunks['chunk'.$i] = "<?php\n\n\$value{$i}=[1,2,3];\n";
            $expected['chunk'.$i] = "<?php\n\n\$value{$i} = [1, 2, 3];\n?>";
        }

        $this->assertSame($expected, (new PintFormatter)->formatBatch($chunks));
    }
}
