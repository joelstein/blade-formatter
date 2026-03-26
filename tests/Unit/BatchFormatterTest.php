<?php

namespace Tests\Unit;

use BladeFormatter\BatchFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BatchFormatterTest extends TestCase
{
    #[Test]
    public function it_fires_callback_for_each_file(): void
    {
        $formatter = new BatchFormatter(
            enablePint: false,
            enableTailwindSort: false,
        );

        $files = [
            '/tmp/a.blade.php' => "<div>\n<p>A</p>\n</div>",
            '/tmp/b.blade.php' => "<div>\n    <p>B</p>\n</div>",
        ];

        $callbackPaths = [];

        $formatter->formatBatch($files, function (string $path, string $formatted) use (&$callbackPaths) {
            $callbackPaths[] = $path;
        });

        $this->assertSame(['/tmp/a.blade.php', '/tmp/b.blade.php'], $callbackPaths);
    }

    #[Test]
    public function callback_receives_formatted_content(): void
    {
        $formatter = new BatchFormatter(
            enablePint: false,
            enableTailwindSort: false,
        );

        $files = [
            '/tmp/test.blade.php' => "<div>\n<p>Hello</p>\n</div>",
        ];

        $callbackResults = [];

        $results = $formatter->formatBatch($files, function (string $path, string $formatted) use (&$callbackResults) {
            $callbackResults[$path] = $formatted;
        });

        // Callback content should match the returned results
        $this->assertSame($results, $callbackResults);
        // Content should be indented
        $this->assertStringContainsString('    <p>Hello</p>', $callbackResults['/tmp/test.blade.php']);
    }

    #[Test]
    public function it_works_without_callback(): void
    {
        $formatter = new BatchFormatter(
            enablePint: false,
            enableTailwindSort: false,
        );

        $files = [
            '/tmp/test.blade.php' => "<div>\n<p>Hello</p>\n</div>",
        ];

        $results = $formatter->formatBatch($files);

        $this->assertStringContainsString('    <p>Hello</p>', $results['/tmp/test.blade.php']);
    }

    #[Test]
    public function it_skips_indentation_for_markdown_mail_templates(): void
    {
        $formatter = new BatchFormatter(
            enablePint: false,
            enableTailwindSort: false,
        );

        $content = "<x-mail::message :unsubscribeUrl=\"\$url\">\n# Hello\n\nContent here.\n\n<x-mail::button :url=\"\$url\">\nView\n</x-mail::button>\n\n</x-mail::message>";

        $files = [
            '/tmp/mail.blade.php' => $content,
        ];

        $results = $formatter->formatBatch($files);

        // Content should NOT be indented — Markdown whitespace is significant
        $this->assertSame($content, $results['/tmp/mail.blade.php']);
    }

    #[Test]
    public function it_preserves_fqcns_in_regular_blade_php_blocks(): void
    {
        $formatter = new BatchFormatter(
            enablePint: true,
            enableTailwindSort: false,
        );

        $input = <<<'BLADE'
@php
    $isPending = $status === \App\Enums\Status::Pending;
    $isComplete = $status === \App\Enums\Status::Complete;
@endphp
BLADE;

        $results = $formatter->formatBatch(['/tmp/test.blade.php' => $input]);
        $result = $results['/tmp/test.blade.php'];

        $this->assertStringContainsString('\App\Enums\Status::Pending', $result);
        $this->assertStringContainsString('\App\Enums\Status::Complete', $result);
    }

    #[Test]
    public function it_preserves_use_statements_in_regular_blade_php_blocks(): void
    {
        $formatter = new BatchFormatter(
            enablePint: true,
            enableTailwindSort: false,
        );

        $input = <<<'BLADE'
@php
    use App\Enums\Status;

    $isPending = $status === Status::Pending;
@endphp
BLADE;

        $results = $formatter->formatBatch(['/tmp/test.blade.php' => $input]);
        $result = $results['/tmp/test.blade.php'];

        $this->assertStringContainsString('use App\Enums\Status;', $result);
        $this->assertStringContainsString('Status::Pending', $result);
    }

    #[Test]
    public function it_hoists_blade_fqcns_to_sfc_use_statements(): void
    {
        $formatter = new BatchFormatter(
            enablePint: true,
            enableTailwindSort: false,
        );

        $input = <<<'BLADE'
<?php

use Livewire\Component;

new class extends Component {
    public string $name = '';
};
?>

<div>
    @php
        $isPending = $status === App\Enums\Status::Pending;
        $post = new App\Models\Post;
    @endphp
</div>
BLADE;

        $results = $formatter->formatBatch(['/tmp/test.blade.php' => $input]);
        $result = $results['/tmp/test.blade.php'];

        // FQCNs from Blade are hoisted as use statements in the PHP section
        $this->assertStringContainsString('use App\Enums\Status;', $result);
        $this->assertStringContainsString('use App\Models\Post;', $result);

        // Blade section uses short names
        $this->assertStringContainsString('Status::Pending', $result);
        $this->assertStringContainsString('new Post', $result);

        // FQCNs should not remain in the Blade section
        preg_match('/\?>\s*(.*)/s', $result, $bladePart);
        $this->assertStringNotContainsString('App\Enums\Status::Pending', $bladePart[1]);
        $this->assertStringNotContainsString('App\Models\Post', $bladePart[1]);
    }

    #[Test]
    public function it_hoists_use_statements_from_sfc_php_blocks(): void
    {
        $formatter = new BatchFormatter(
            enablePint: true,
            enableTailwindSort: false,
        );

        $input = <<<'BLADE'
<?php

use Livewire\Component;

new class extends Component {
    public string $name = '';
};
?>

<div>
    @php
        use App\Enums\Status;

        $isPending = $status === Status::Pending;
    @endphp
</div>
BLADE;

        $results = $formatter->formatBatch(['/tmp/test.blade.php' => $input]);
        $result = $results['/tmp/test.blade.php'];

        // use statement should be hoisted to PHP section, not left in @php block
        $this->assertStringContainsString('use App\Enums\Status;', $result);
        $this->assertStringContainsString('Status::Pending', $result);

        // The @php block should not contain the use statement
        preg_match('/@php(.*?)@endphp/s', $result, $phpBlock);
        $this->assertStringNotContainsString('use App\\', $phpBlock[1]);
    }

    #[Test]
    public function it_deduplicates_hoisted_use_statements(): void
    {
        $formatter = new BatchFormatter(
            enablePint: true,
            enableTailwindSort: false,
        );

        $input = <<<'BLADE'
<?php

use App\Enums\Status;
use Livewire\Component;

new class extends Component {
    public Status $status;
};
?>

<div>
    @php
        $isPending = $status === App\Enums\Status::Pending;
    @endphp
</div>
BLADE;

        $results = $formatter->formatBatch(['/tmp/test.blade.php' => $input]);
        $result = $results['/tmp/test.blade.php'];

        // Should use short name in Blade
        $this->assertStringContainsString('Status::Pending', $result);

        // Should not duplicate the use statement
        $this->assertSame(1, substr_count($result, 'use App\Enums\Status;'));
    }

    #[Test]
    public function it_sorts_hoisted_use_statements(): void
    {
        $formatter = new BatchFormatter(
            enablePint: true,
            enableTailwindSort: false,
        );

        $input = <<<'BLADE'
<?php

use Livewire\Component;

new class extends Component {
    public string $name = '';
};
?>

<div>
    @php
        $post = new App\Models\Post;
        $isPending = $status === App\Enums\Status::Pending;
    @endphp
</div>
BLADE;

        $results = $formatter->formatBatch(['/tmp/test.blade.php' => $input]);
        $result = $results['/tmp/test.blade.php'];

        // Use statements should be sorted alphabetically
        $useEnumsPos = strpos($result, 'use App\Enums\Status;');
        $useModelsPos = strpos($result, 'use App\Models\Post;');
        $useLivewirePos = strpos($result, 'use Livewire\Component;');

        $this->assertNotFalse($useEnumsPos);
        $this->assertNotFalse($useModelsPos);
        $this->assertNotFalse($useLivewirePos);
        $this->assertLessThan($useModelsPos, $useEnumsPos);
        $this->assertLessThan($useLivewirePos, $useModelsPos);
    }

    #[Test]
    public function it_handles_instanceof_in_sfc_hoisting(): void
    {
        $formatter = new BatchFormatter(
            enablePint: true,
            enableTailwindSort: false,
        );

        $input = <<<'BLADE'
<?php

use Livewire\Component;

new class extends Component {};
?>

<div>
    @php
        $isPost = $item instanceof App\Models\Post;
    @endphp
</div>
BLADE;

        $results = $formatter->formatBatch(['/tmp/test.blade.php' => $input]);
        $result = $results['/tmp/test.blade.php'];

        $this->assertStringContainsString('use App\Models\Post;', $result);
        $this->assertStringContainsString('instanceof Post', $result);
        $this->assertStringNotContainsString('instanceof App\Models\Post', $result);
    }

    #[Test]
    public function it_preserves_hoisted_imports_on_subsequent_saves(): void
    {
        $formatter = new BatchFormatter(
            enablePint: true,
            enableTailwindSort: false,
        );

        // Simulate the output of a first save (imports already hoisted, short names in Blade)
        $input = <<<'BLADE'
<?php

use App\Enums\Status;
use Livewire\Component;

new class extends Component {
    public string $name = '';
};
?>

<div>
    @php
        $isPending = $status === Status::Pending;
    @endphp
</div>
BLADE;

        $results = $formatter->formatBatch(['/tmp/test.blade.php' => $input]);
        $result = $results['/tmp/test.blade.php'];

        // Pint should NOT have stripped the import — it's used in Blade
        $this->assertStringContainsString('use App\Enums\Status;', $result);
        $this->assertStringContainsString('Status::Pending', $result);

        // No dummy references should leak into the output
        $this->assertStringNotContainsString('blade-refs', $result);
        $this->assertStringNotContainsString('::class', $result);
    }
}
