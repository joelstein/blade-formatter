<?php

namespace Tests\Unit;

use BladeFormatter\BatchFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BatchFormatterTest extends TestCase
{
    #[Test]
    public function an_unclosed_disable_comment_opts_out_the_rest_of_the_file(): void
    {
        $content = "{{-- blade-formatter-disable --}}\n<div>\n<p>Untouched</p>\n</div>";

        $result = $this->format($content);

        $this->assertSame($content, $result);
    }

    #[Test]
    public function it_formats_around_a_disabled_region(): void
    {
        $content = "<div>\n<p>Before</p>\n{{-- blade-formatter-disable --}}\n<pre>\n  keep    this\n</pre>\n{{-- blade-formatter-enable --}}\n<p>After</p>\n</div>";

        $result = $this->format($content);

        // The region survives byte-for-byte, including its own indentation.
        $this->assertStringContainsString("{{-- blade-formatter-disable --}}\n<pre>\n  keep    this\n</pre>\n{{-- blade-formatter-enable --}}", $result);

        // Everything outside it is still formatted.
        $this->assertStringContainsString('    <p>Before</p>', $result);
        $this->assertStringContainsString('    <p>After</p>', $result);
    }

    #[Test]
    public function it_handles_multiple_disabled_regions_in_one_file(): void
    {
        $content = "<div>\n{{-- blade-formatter-disable --}}\n<p>One</p>\n{{-- blade-formatter-enable --}}\n<p>Formatted</p>\n{{-- blade-formatter-disable --}}\n<p>Two</p>\n{{-- blade-formatter-enable --}}\n</div>";

        $result = $this->format($content);

        $this->assertStringContainsString("{{-- blade-formatter-disable --}}\n<p>One</p>\n", $result);
        $this->assertStringContainsString("{{-- blade-formatter-disable --}}\n<p>Two</p>\n", $result);
        $this->assertStringContainsString('    <p>Formatted</p>', $result);
    }

    #[Test]
    public function a_disable_comment_inside_a_disabled_region_is_just_text(): void
    {
        $content = "{{-- blade-formatter-disable --}}\n<p>{{-- blade-formatter-disable --}}</p>\n{{-- blade-formatter-enable --}}\n<div>\n<p>After</p>\n</div>";

        $result = $this->format($content);

        $this->assertStringContainsString("<p>{{-- blade-formatter-disable --}}</p>", $result);
        $this->assertStringContainsString('    <p>After</p>', $result);
    }

    #[Test]
    public function an_enable_comment_without_a_disable_is_ignored(): void
    {
        $content = "<div>\n{{-- blade-formatter-enable --}}\n<p>Hello</p>\n</div>";

        $result = $this->format($content);

        $this->assertStringContainsString('    <p>Hello</p>', $result);
        $this->assertStringContainsString('    {{-- blade-formatter-enable --}}', $result);
    }

    #[Test]
    public function a_disabled_region_does_not_stop_later_files_being_formatted(): void
    {
        $disabled = "{{-- blade-formatter-disable --}}\n<div>\n<p>A</p>\n</div>";

        $files = [
            '/tmp/a.blade.php' => $disabled,
            '/tmp/b.blade.php' => "<div>\n<p>B</p>\n</div>",
        ];

        $seen = [];
        $results = (new BatchFormatter(enablePint: false, enableTailwindSort: false))
            ->formatBatch($files, function (string $path, string $formatted) use (&$seen) {
                $seen[$path] = $formatted;
            });

        // Both files are reported, in the original order, so progress output
        // and the "already formatted" count stay accurate.
        $this->assertSame(['/tmp/a.blade.php', '/tmp/b.blade.php'], array_keys($seen));
        $this->assertSame($disabled, $seen['/tmp/a.blade.php']);
        $this->assertSame("<div>\n    <p>B</p>\n</div>", $results['/tmp/b.blade.php']);
    }

    #[Test]
    public function disable_markers_tolerate_whitespace_variations(): void
    {
        $this->assertCount(1, BatchFormatter::disabledRegions('{{--blade-formatter-disable--}}'));
        $this->assertCount(1, BatchFormatter::disabledRegions('{{--    blade-formatter-disable    --}}'));
        $this->assertSame([], BatchFormatter::disabledRegions('{{-- blade-formatter-disabled --}}'));
        $this->assertSame([], BatchFormatter::disabledRegions('<div>no comment here</div>'));
    }

    #[Test]
    public function a_region_spans_from_its_disable_marker_through_its_enable_marker(): void
    {
        $content = "a{{-- blade-formatter-disable --}}b{{-- blade-formatter-enable --}}c";

        $regions = BatchFormatter::disabledRegions($content);

        $this->assertCount(1, $regions);
        $this->assertSame(
            '{{-- blade-formatter-disable --}}b{{-- blade-formatter-enable --}}',
            substr($content, $regions[0]['start'], $regions[0]['end'] - $regions[0]['start']),
        );
    }

    #[Test]
    public function masking_and_restoring_round_trips_unchanged_content(): void
    {
        $content = "<div>\n{{-- blade-formatter-disable --}}\n<p>Kept</p>\n{{-- blade-formatter-enable --}}\n</div>";

        [$masked, $originals] = BatchFormatter::maskDisabledRegions($content);

        $this->assertStringNotContainsString('<p>Kept</p>', $masked);
        $this->assertSame($content, BatchFormatter::restoreDisabledRegions($masked, $originals));
    }

    #[Test]
    public function restoring_fails_loudly_when_a_placeholder_was_lost(): void
    {
        [, $originals] = BatchFormatter::maskDisabledRegions(
            "{{-- blade-formatter-disable --}}\n<p>Kept</p>\n{{-- blade-formatter-enable --}}",
        );

        // A formatter that ate the placeholder must not cause silent data loss.
        $this->assertNull(BatchFormatter::restoreDisabledRegions('<div>placeholder gone</div>', $originals));
    }

    private function format(string $content): string
    {
        return $this->formatAt('/tmp/a.blade.php', $content);
    }

    private function formatAt(string $path, string $content): string
    {
        $results = (new BatchFormatter(enablePint: false, enableTailwindSort: false))
            ->formatBatch([$path => $content]);

        return $results[$path];
    }

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
    public function it_skips_indentation_for_published_mail_views(): void
    {
        // What `vendor:publish --tag=laravel-mail` writes: the pieces
        // `<x-mail::message>` is assembled from, which never mention it.
        $content = <<<'BLADE'
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{ Illuminate\Mail\Markdown::parse($slot) }}

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. @lang('All rights reserved.')
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
BLADE;

        $this->assertSame($content, $this->format($content));
    }

    #[Test]
    public function it_skips_indentation_for_templates_under_a_vendor_mail_path(): void
    {
        // The plain-text theme references no component at all, so only the
        // path says it is whitespace-sensitive Markdown.
        $content = "@if (! empty(\$header))\n{!! strip_tags(\$header) !!}\n@endif\n\n{!! strip_tags(\$slot) !!}\n";

        $path = '/tmp/resources/views/vendor/mail/text/message.blade.php';

        $this->assertSame($content, $this->formatAt($path, $content));
    }

    #[Test]
    public function it_still_indents_templates_outside_a_mail_path_that_use_no_mail_components(): void
    {
        $path = '/tmp/resources/views/mail-preview.blade.php';

        $result = $this->formatAt($path, "<div>\n<p>Hello</p>\n</div>");

        $this->assertStringContainsString('    <p>Hello</p>', $result);
    }

    #[Test]
    public function it_still_sorts_tailwind_classes_in_mail_views(): void
    {
        exec('npx prettier --version 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->markTestSkipped('Prettier with prettier-plugin-tailwindcss is not installed.');
        }

        // Only indentation breaks Markdown rendering; class order does not.
        $content = "<x-mail::layout>\n<div class=\"font-bold text-red-500 flex\">\nHello\n</div>\n</x-mail::layout>";

        $path = '/tmp/resources/views/vendor/mail/html/themed.blade.php';

        $formatter = new BatchFormatter(enablePint: false, enableTailwindSort: true);
        $result = $formatter->formatBatch([$path => $content])[$path];

        $this->assertStringContainsString('class="flex font-bold text-red-500"', $result);
        // Still unindented
        $this->assertStringContainsString("\n<div class=", $result);
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

    #[Test]
    public function it_hoists_backslash_prefixed_fqcns_from_blade(): void
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

<div
    x-data="{
        items: @js(\App\ValueObjects\Config::items()),
        labels: @js(\App\ValueObjects\Config::labels()),
    }"
>
</div>
BLADE;

        $results = $formatter->formatBatch(['/tmp/test.blade.php' => $input]);
        $result = $results['/tmp/test.blade.php'];

        $this->assertStringContainsString('use App\ValueObjects\Config;', $result);
        $this->assertStringContainsString('@js(Config::items())', $result);
        $this->assertStringContainsString('@js(Config::labels())', $result);
        // No stray backslash before the short name
        $this->assertStringNotContainsString('\Config::', $result);
    }

    #[Test]
    public function it_formats_sfc_and_regular_php_blocks_in_the_same_batch(): void
    {
        $formatter = new BatchFormatter(
            enablePint: true,
            enableTailwindSort: false,
        );

        $sfc = <<<'BLADE'
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

        $regular = <<<'BLADE'
@php
    use App\Enums\Priority;

    $isUrgent = $priority===Priority::Urgent;
@endphp
BLADE;

        $results = $formatter->formatBatch([
            '/tmp/sfc.blade.php' => $sfc,
            '/tmp/regular.blade.php' => $regular,
        ]);

        // The SFC block's import is hoisted to the PHP section...
        preg_match('/@php(.*?)@endphp/s', $results['/tmp/sfc.blade.php'], $sfcBlock);
        $this->assertStringNotContainsString('use App\\', $sfcBlock[1]);
        $this->assertStringContainsString("use App\\Enums\\Status;\nuse Livewire\\Component;", $results['/tmp/sfc.blade.php']);

        // ...while the regular file's block keeps its own, and is still formatted.
        $this->assertStringContainsString('use App\Enums\Priority;', $results['/tmp/regular.blade.php']);
        $this->assertStringContainsString('$priority === Priority::Urgent', $results['/tmp/regular.blade.php']);

        $this->assertSame([], $formatter->getAllWarnings());
    }

    #[Test]
    public function it_warns_and_leaves_classes_alone_when_prettier_fails(): void
    {
        $formatter = new BatchFormatter(
            enablePint: false,
            enableTailwindSort: true,
            prettierPath: '/nonexistent/prettier',
        );

        $withClasses = '<div class="font-bold text-red-500 flex"></div>';
        $withoutClasses = '<p>Hello</p>';

        $results = $formatter->formatBatch([
            '/tmp/with.blade.php' => $withClasses,
            '/tmp/without.blade.php' => $withoutClasses,
        ]);

        $this->assertSame($withClasses, $results['/tmp/with.blade.php']);
        $this->assertCount(1, $formatter->getWarnings('/tmp/with.blade.php'));
        $this->assertStringStartsWith('Tailwind sorting skipped: ', $formatter->getWarnings('/tmp/with.blade.php')[0]);
        $this->assertSame([], $formatter->getWarnings('/tmp/without.blade.php'));
    }
}
