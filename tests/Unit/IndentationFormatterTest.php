<?php

namespace Tests\Unit;

use BladeFormatter\Formatters\IndentationFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IndentationFormatterTest extends TestCase
{
    private IndentationFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new IndentationFormatter;
    }

    private function indent(string $input, int $size = 4): string
    {
        return $this->formatter->format($input, $size);
    }

    // ── HTML tag indentation ──────────────────────────────────────────

    #[Test]
    public function it_indents_children_of_opening_tags(): void
    {
        $input = "\n<div>\n<p>Hello</p>\n</div>";
        $expected = "\n<div>\n    <p>Hello</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_handles_nested_tags(): void
    {
        $input = "\n<div>\n<section>\n<p>Hello</p>\n</section>\n</div>";
        $expected = "\n<div>\n    <section>\n        <p>Hello</p>\n    </section>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_indent_after_void_elements(): void
    {
        $input = "\n<div>\n<input>\n<img>\n<p>Hello</p>\n</div>";
        $expected = "\n<div>\n    <input>\n    <img>\n    <p>Hello</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_indent_after_self_closing_tags(): void
    {
        $input = "\n<div>\n<br />\n<hr />\n<p>Hello</p>\n</div>";
        $expected = "\n<div>\n    <br />\n    <hr />\n    <p>Hello</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_handles_same_line_open_and_close_tags(): void
    {
        $input = "\n<div>\n<p>Hello</p>\n<p>World</p>\n</div>";
        $expected = "\n<div>\n    <p>Hello</p>\n    <p>World</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_preserves_empty_lines(): void
    {
        $input = "\n<div>\n\n<p>Hello</p>\n\n</div>";
        $expected = "\n<div>\n\n    <p>Hello</p>\n\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Blade component indentation ──────────────────────────────────

    #[Test]
    public function it_indents_children_of_flux_components(): void
    {
        $input = "\n<flux:modal>\n<p>Content</p>\n</flux:modal>";
        $expected = "\n<flux:modal>\n    <p>Content</p>\n</flux:modal>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_children_of_x_components(): void
    {
        $input = "\n<x-layout>\n<x-slot name=\"header\">\n<h1>Title</h1>\n</x-slot>\n<p>Body</p>\n</x-layout>";
        $expected = "\n<x-layout>\n    <x-slot name=\"header\">\n        <h1>Title</h1>\n    </x-slot>\n    <p>Body</p>\n</x-layout>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_indent_after_self_closing_components(): void
    {
        $input = "\n<div>\n<flux:button />\n<x-icon name=\"check\" />\n<p>Hello</p>\n</div>";
        $expected = "\n<div>\n    <flux:button />\n    <x-icon name=\"check\" />\n    <p>Hello</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_indent_after_self_closing_tags_with_gt_in_attribute_values(): void
    {
        $input = "\n<flux:dropdown>\n<flux:profile :initials=\"auth()->user()->initials()\" />\n<flux:menu>\n<p>Content</p>\n</flux:menu>\n</flux:dropdown>";
        $expected = "\n<flux:dropdown>\n    <flux:profile :initials=\"auth()->user()->initials()\" />\n    <flux:menu>\n        <p>Content</p>\n    </flux:menu>\n</flux:dropdown>";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Blade directive indentation ──────────────────────────────────

    #[Test]
    public function it_indents_if_endif_blocks(): void
    {
        $input = "\n@if(\$show)\n<p>Visible</p>\n@endif";
        $expected = "\n@if(\$show)\n    <p>Visible</p>\n@endif";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_handles_else_at_same_level_as_if(): void
    {
        $input = "\n@if(\$show)\n<p>Yes</p>\n@else\n<p>No</p>\n@endif";
        $expected = "\n@if(\$show)\n    <p>Yes</p>\n@else\n    <p>No</p>\n@endif";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_handles_elseif_at_same_level_as_if(): void
    {
        $input = "\n@if(\$a)\n<p>A</p>\n@elseif(\$b)\n<p>B</p>\n@else\n<p>C</p>\n@endif";
        $expected = "\n@if(\$a)\n    <p>A</p>\n@elseif(\$b)\n    <p>B</p>\n@else\n    <p>C</p>\n@endif";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_foreach_endforeach_blocks(): void
    {
        $input = "\n@foreach(\$items as \$item)\n<li>{{ \$item }}</li>\n@endforeach";
        $expected = "\n@foreach(\$items as \$item)\n    <li>{{ \$item }}</li>\n@endforeach";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_nested_directives(): void
    {
        $input = "\n@foreach(\$items as \$item)\n@if(\$item->active)\n<li>{{ \$item->name }}</li>\n@endif\n@endforeach";
        $expected = "\n@foreach(\$items as \$item)\n    @if(\$item->active)\n        <li>{{ \$item->name }}</li>\n    @endif\n@endforeach";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_auth_endauth_blocks(): void
    {
        $input = "\n@auth\n<p>Logged in</p>\n@endauth";
        $expected = "\n@auth\n    <p>Logged in</p>\n@endauth";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_error_enderror_blocks(): void
    {
        $input = "\n<div>\n@error('email')\n<span>{{ \$message }}</span>\n@enderror\n</div>";
        $expected = "\n<div>\n    @error('email')\n        <span>{{ \$message }}</span>\n    @enderror\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_feature_endfeature_blocks(): void
    {
        $input = "\n@feature('new-layout')\n<p>New</p>\n@else\n<p>Old</p>\n@endfeature";
        $expected = "\n@feature('new-layout')\n    <p>New</p>\n@else\n    <p>Old</p>\n@endfeature";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_has_section_blocks_closed_by_endif(): void
    {
        $input = "\n<div>\n@hasSection('sidebar')\n<aside></aside>\n@endif\n</div>";
        $expected = "\n<div>\n    @hasSection('sidebar')\n        <aside></aside>\n    @endif\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_section_missing_blocks_closed_by_endif(): void
    {
        $input = "\n@sectionMissing('sidebar')\n<p>No sidebar</p>\n@else\n<p>Has sidebar</p>\n@endif";
        $expected = "\n@sectionMissing('sidebar')\n    <p>No sidebar</p>\n@else\n    <p>Has sidebar</p>\n@endif";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_indent_single_line_has_section_blocks(): void
    {
        $input = "\n<div>\n@hasSection('sidebar') <aside></aside> @endif\n<p>After</p>\n</div>";
        $expected = "\n<div>\n    @hasSection('sidebar') <aside></aside> @endif\n    <p>After</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_nested_has_section_and_if_blocks(): void
    {
        $input = "\n@if(\$show)\n@hasSection('sidebar')\n<aside></aside>\n@endif\n<p>After</p>\n@endif";
        $expected = "\n@if(\$show)\n    @hasSection('sidebar')\n        <aside></aside>\n    @endif\n    <p>After</p>\n@endif";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_switch_case_endswitch_blocks(): void
    {
        $input = "\n@switch(\$type)\n@case('a')\n<p>A</p>\n@break\n@default\n<p>Default</p>\n@endswitch";
        $expected = "\n@switch(\$type)\n    @case('a')\n        <p>A</p>\n        @break\n    @default\n        <p>Default</p>\n@endswitch";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_aligns_comment_before_case_with_the_case_directive(): void
    {
        $input = "\n@switch(\$type)\n{{-- handles a --}}\n@case('a')\n<p>A</p>\n@break\n{{-- handles b --}}\n@case('b')\n<p>B</p>\n@break\n@endswitch";
        $expected = "\n@switch(\$type)\n    {{-- handles a --}}\n    @case('a')\n        <p>A</p>\n        @break\n    {{-- handles b --}}\n    @case('b')\n        <p>B</p>\n        @break\n@endswitch";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_aligns_comment_before_case_when_separated_by_blank_line(): void
    {
        $input = "\n@switch(\$type)\n\n{{-- handles a --}}\n@case('a')\n<p>A</p>\n@break\n@endswitch";
        $expected = "\n@switch(\$type)\n\n    {{-- handles a --}}\n    @case('a')\n        <p>A</p>\n        @break\n@endswitch";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_indent_after_inline_php_endphp(): void
    {
        $input = "\n<div>\n@php \$var = 'value'; @endphp\n<p>After</p>\n</div>";
        $expected = "\n<div>\n    @php \$var = 'value'; @endphp\n    <p>After</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_indent_after_inline_php_expression(): void
    {
        $input = "\n<div>\n@php(\$limit = app(Config::class)->perPage)\n<flux:input wire:model=\"name\" />\n<flux:switch wire:model=\"active\" />\n</div>";
        $expected = "\n<div>\n    @php(\$limit = app(Config::class)->perPage)\n    <flux:input wire:model=\"name\" />\n    <flux:switch wire:model=\"active\" />\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_still_indents_block_php_endphp(): void
    {
        $input = "\n<div>\n@php\n\$var = 'value';\n@endphp\n<p>After</p>\n</div>";
        $expected = "\n<div>\n    @php\n        \$var = 'value';\n    @endphp\n    <p>After</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_preserves_relative_whitespace_in_php_blocks(): void
    {
        $input = "\n<div>\n@php\n\$list = collect(\$items)\n    ->map(fn (\$i) => \$i->name)\n    ->join(', ');\n@endphp\n</div>";
        $expected = "\n<div>\n    @php\n        \$list = collect(\$items)\n            ->map(fn (\$i) => \$i->name)\n            ->join(', ');\n    @endphp\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_placeholder_endplaceholder_blocks(): void
    {
        $input = "\n@placeholder\n<p>Loading...</p>\n@endplaceholder";
        $expected = "\n@placeholder\n    <p>Loading...</p>\n@endplaceholder";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Brace handling ──────────────────────────────────────────────

    #[Test]
    public function it_handles_else_brace_at_correct_indent(): void
    {
        $input = "\n<div>\nif (\$a) {\n\$b = 1;\n} else {\n\$b = 2;\n}\n</div>";
        $expected = "\n<div>\n    if (\$a) {\n        \$b = 1;\n    } else {\n        \$b = 2;\n    }\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_ignores_braces_inside_quoted_strings(): void
    {
        $input = "\n<div>\n@t('{count, plural, one {# item} other {# items}}')\n<p>After</p>\n</div>";
        $expected = "\n<div>\n    @t('{count, plural, one {# item} other {# items}}')\n    <p>After</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Multi-line tag indentation ───────────────────────────────────

    #[Test]
    public function it_indents_attributes_of_multi_line_tags(): void
    {
        $input = "\n<flux:input\nname=\"email\"\ntype=\"email\"\nrequired\n/>";
        $expected = "\n<flux:input\n    name=\"email\"\n    type=\"email\"\n    required\n/>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_aligns_closing_bracket_with_opening_tag(): void
    {
        $input = "\n<div\nclass=\"container\"\n>\n<p>Hello</p>\n</div>";
        $expected = "\n<div\n    class=\"container\"\n>\n    <p>Hello</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_handles_self_closing_multi_line_tags_without_adding_child_indent(): void
    {
        $input = "\n<div>\n<flux:input\nname=\"email\"\ntype=\"email\"\n/>\n<p>After</p>\n</div>";
        $expected = "\n<div>\n    <flux:input\n        name=\"email\"\n        type=\"email\"\n    />\n    <p>After</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_nested_multi_line_tags(): void
    {
        $input = "\n<div>\n<form\nmethod=\"POST\"\naction=\"/submit\"\n>\n<flux:input\nname=\"email\"\nrequired\n/>\n</form>\n</div>";
        $expected = "\n<div>\n    <form\n        method=\"POST\"\n        action=\"/submit\"\n    >\n        <flux:input\n            name=\"email\"\n            required\n        />\n    </form>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_directive_content_inside_multi_line_tags(): void
    {
        $input = "\n<a\n@if(\$linked)\nhref=\"https://example.com\"\ntarget=\"_blank\"\n@endif\nclass=\"btn\"\n>\nlink\n</a>";
        $expected = "\n<a\n    @if(\$linked)\n        href=\"https://example.com\"\n        target=\"_blank\"\n    @endif\n    class=\"btn\"\n>\n    link\n</a>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_if_else_inside_multi_line_tags(): void
    {
        $input = "\n<a\n@if(\$linked)\nhref=\"https://example.com\"\n@else\nhref=\"#\"\n@endif\nclass=\"btn\"\n>\nlink\n</a>";
        $expected = "\n<a\n    @if(\$linked)\n        href=\"https://example.com\"\n    @else\n        href=\"#\"\n    @endif\n    class=\"btn\"\n>\n    link\n</a>";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Alpine x-data indentation ────────────────────────────────────

    #[Test]
    public function it_indents_x_data_object_contents(): void
    {
        $input = "\n<div\nx-data=\"{\nopen: false,\nname: '',\n}\"\n>\n<p>Hello</p>\n</div>";
        $expected = "\n<div\n    x-data=\"{\n        open: false,\n        name: '',\n    }\"\n>\n    <p>Hello</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_nested_functions_in_x_data(): void
    {
        $input = "\n<div\nx-data=\"{\nopen: false,\ntoggle() {\nthis.open = !this.open;\n},\n}\"\n>\n<p>Content</p>\n</div>";
        $expected = "\n<div\n    x-data=\"{\n        open: false,\n        toggle() {\n            this.open = !this.open;\n        },\n    }\"\n>\n    <p>Content</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_x_data_brace_content_on_tag_opening_line(): void
    {
        $input = "\n<div class=\"flex\" x-data=\"{\nopen: false,\n}\">\n<p>Hello</p>\n</div>";
        $expected = "\n<div class=\"flex\" x-data=\"{\n    open: false,\n}\">\n    <p>Hello</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_multiline_alpine_attribute_values(): void
    {
        $input = "\n<button\nx-on:click=\"\nconst a = 1;\nconst b = 2;\n\"\nclass=\"btn\"\n>\nClick\n</button>";
        $expected = "\n<button\n    x-on:click=\"\n        const a = 1;\n        const b = 2;\n    \"\n    class=\"btn\"\n>\n    Click\n</button>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_ternary_operators_in_multiline_attribute_values(): void
    {
        $input = "\n<form\nx-on:submit.prevent=\"\ncondition\n? doA()\n: doB();\n\"\n>";
        $expected = "\n<form\n    x-on:submit.prevent=\"\n        condition\n            ? doA()\n            : doB();\n    \"\n>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_dot_chained_methods_in_multiline_attribute_values(): void
    {
        $input = "\n<flux:input\nx-on:input=\"\nvalue\n.toLowerCase()\n.trim()\n\"\n/>";
        $expected = "\n<flux:input\n    x-on:input=\"\n        value\n            .toLowerCase()\n            .trim()\n    \"\n/>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_ternary_operators_in_multi_line_tag_attributes(): void
    {
        $input = "\n<button\n:class=\"condition\n? 'active'\n: 'inactive'\"\nclass=\"btn\"\n>";
        $expected = "\n<button\n    :class=\"condition\n        ? 'active'\n        : 'inactive'\"\n    class=\"btn\"\n>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_else_blocks_in_multiline_attribute_values(): void
    {
        $input = <<<'BLADE'

<input
x-effect="
rawValue; focused;
$nextTick(() => {
if (!focused) {
const num = parseFloat(rawValue);
if (rawValue !== '' && !isNaN(num) && isFinite(num)) {
$el.value = num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
} else {
$el.value = rawValue;
}
}
});
"
/>
BLADE;
        $expected = <<<'BLADE'

<input
    x-effect="
        rawValue; focused;
        $nextTick(() => {
            if (!focused) {
                const num = parseFloat(rawValue);
                if (rawValue !== '' && !isNaN(num) && isFinite(num)) {
                    $el.value = num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                } else {
                    $el.value = rawValue;
                }
            }
        });
    "
/>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_outdents_closing_paren_of_multi_line_directive_condition(): void
    {
        $input = "\n@if (\n! app()->isLocal()\n&& ! auth()->check()\n|| \$override\n)\n<p>Content</p>\n@endif";
        $expected = "\n@if (\n    ! app()->isLocal()\n    && ! auth()->check()\n    || \$override\n)\n    <p>Content</p>\n@endif";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_treats_inline_section_with_two_arguments_as_non_block(): void
    {
        $input = "\n@extends('pdf')\n\n@section('title', 'Invoice')\n\n@section('content')\n<div>Content</div>\n@endsection";
        $expected = "\n@extends('pdf')\n\n@section('title', 'Invoice')\n\n@section('content')\n    <div>Content</div>\n@endsection";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_continuation_lines_inside_braces(): void
    {
        $input = "\n{!! t('test', [\n'key' => 'value'\n. 'more',\n]) !!}";
        $expected = "\n{!! t('test', [\n    'key' => 'value'\n        . 'more',\n]) !!}";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── @class inside tags ──────────────────────────────────────────

    #[Test]
    public function it_indents_class_directive_on_tag_opening_line(): void
    {
        $input = "\n<div @class([\n'h-3',\n'bg-green-500' => true,\n])></div>";
        $expected = "\n<div @class([\n    'h-3',\n    'bg-green-500' => true,\n])></div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_class_directive_on_own_attribute_line(): void
    {
        $input = "\n<div\n@class([\n'h-3',\n])\n>\nContent\n</div>";
        $expected = "\n<div\n    @class([\n        'h-3',\n    ])\n>\n    Content\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_children_when_tag_has_inline_class_directive_with_arrow_operators(): void
    {
        $input = "\n<nav @class(['active' => \$open, 'hidden' => ! \$open])>\n@if (\$show)\n<p>Hi</p>\n@endif\n</nav>";
        $expected = "\n<nav @class(['active' => \$open, 'hidden' => ! \$open])>\n    @if (\$show)\n        <p>Hi</p>\n    @endif\n</nav>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_children_of_a_component_whose_class_directive_compares_with_greater_than(): void
    {
        $input = "\n<x-panel @class(['total', 'text-red-600' => \$order->due > 0])>\n{{ \$order->due }}\n</x-panel>";
        $expected = "\n<x-panel @class(['total', 'text-red-600' => \$order->due > 0])>\n    {{ \$order->due }}\n</x-panel>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_children_of_a_namespaced_component_whose_class_directive_compares_with_less_than(): void
    {
        $input = "\n<flux:badge @class(['dim' => \$count < 5])>\n{{ \$count }}\n</flux:badge>";
        $expected = "\n<flux:badge @class(['dim' => \$count < 5])>\n    {{ \$count }}\n</flux:badge>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_treats_a_tag_as_multi_line_when_its_class_directive_compares_with_greater_than(): void
    {
        $input = "\n<x-panel @class(['text-red-600' => \$order->due > 0])\nsize=\"lg\"\n>\nTotal\n</x-panel>";
        $expected = "\n<x-panel @class(['text-red-600' => \$order->due > 0])\n    size=\"lg\"\n>\n    Total\n</x-panel>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_keeps_greater_than_in_plain_text_from_affecting_indentation(): void
    {
        $input = "\n<div>\n5 > 3 is true\n<span>ok</span>\n</div>";
        $expected = "\n<div>\n    5 > 3 is true\n    <span>ok</span>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_style_directive_on_own_attribute_line(): void
    {
        $input = "\n<div\n@style([\n'color: red' => \$highlight,\n])\n>\nContent\n</div>";
        $expected = "\n<div\n    @style([\n        'color: red' => \$highlight,\n    ])\n>\n    Content\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Brace nesting outside tags ───────────────────────────────────

    #[Test]
    public function it_indents_props_array_contents(): void
    {
        $input = "\n@props([\n'on',\n'color' => 'blue',\n])";
        $expected = "\n@props([\n    'on',\n    'color' => 'blue',\n])";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_nested_arrays_in_props(): void
    {
        $input = "\n@props([\n'options' => [\n'a',\n'b',\n],\n])";
        $expected = "\n@props([\n    'options' => [\n        'a',\n        'b',\n    ],\n])";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Mixed content ────────────────────────────────────────────────

    #[Test]
    public function it_handles_a_realistic_blade_template(): void
    {
        $input = "\n<div>\n@if(\$users->count())\n<ul>\n@foreach(\$users as \$user)\n<li>\n<x-avatar\n:src=\"\$user->avatar\"\n:alt=\"\$user->name\"\n/>\n<span>{{ \$user->name }}</span>\n</li>\n@endforeach\n</ul>\n@else\n<p>No users found.</p>\n@endif\n</div>";

        $expected = "\n<div>\n    @if(\$users->count())\n        <ul>\n            @foreach(\$users as \$user)\n                <li>\n                    <x-avatar\n                        :src=\"\$user->avatar\"\n                        :alt=\"\$user->name\"\n                    />\n                    <span>{{ \$user->name }}</span>\n                </li>\n            @endforeach\n        </ul>\n    @else\n        <p>No users found.</p>\n    @endif\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_handles_custom_indent_size(): void
    {
        $input = "\n<div>\n<p>Hello</p>\n</div>";
        $expected = "\n<div>\n  <p>Hello</p>\n</div>";

        $this->assertSame($expected, $this->indent($input, 2));
    }

    // ── Preserved blocks ─────────────────────────────────────────────

    #[Test]
    public function it_preserves_content_inside_verbatim(): void
    {
        $input = "\n<div>\n@verbatim\n    <div>\n        {{ \$unprocessed }}\n    </div>\n@endverbatim\n</div>";
        $expected = "\n<div>\n    @verbatim\n    <div>\n        {{ \$unprocessed }}\n    </div>\n@endverbatim\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_preserves_content_inside_pre_tags(): void
    {
        $input = "\n<div>\n<pre>\n  line 1\n    line 2\n      line 3\n</pre>\n</div>";
        $expected = "\n<div>\n    <pre>\n  line 1\n    line 2\n      line 3\n</pre>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_inject_whitespace_before_a_closing_pre_tag(): void
    {
        // Whitespace before </pre> is rendered literally, so an unindented
        // closing tag must stay unindented no matter how deep the nesting.
        $input = "\n<div>\n<section>\n<pre>\ncode\n</pre>\n</section>\n</div>";
        $expected = "\n<div>\n    <section>\n        <pre>\ncode\n</pre>\n    </section>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_keeps_existing_indentation_of_a_closing_pre_tag(): void
    {
        // If the author already indented </pre>, that whitespace is part of the
        // rendered content — leave it exactly as written rather than "fixing" it.
        $input = "\n<div>\n<pre>\ncode\n        </pre>\n</div>";
        $expected = "\n<div>\n    <pre>\ncode\n        </pre>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_inject_whitespace_before_endverbatim(): void
    {
        // @verbatim emits its contents raw, so indenting @endverbatim would add
        // leading spaces to the last line of output.
        $input = "\n<div>\n<section>\n@verbatim\n{{ raw }}\n@endverbatim\n</section>\n</div>";
        $expected = "\n<div>\n    <section>\n        @verbatim\n{{ raw }}\n@endverbatim\n    </section>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_continues_indenting_siblings_after_a_preserved_block(): void
    {
        // Leaving the closing line untouched must not disturb level tracking.
        $input = "\n<div>\n<pre>\ncode\n</pre>\n<p>After</p>\n@verbatim\n{{ raw }}\n@endverbatim\n<p>End</p>\n</div>";
        $expected = "\n<div>\n    <pre>\ncode\n</pre>\n    <p>After</p>\n    @verbatim\n{{ raw }}\n@endverbatim\n    <p>End</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_content_inside_script_tags(): void
    {
        $input = "\n<div>\n<script>\nfunction hello() {\nconsole.log('hi');\n}\n</script>\n</div>";
        $expected = "\n<div>\n    <script>\n        function hello() {\n            console.log('hi');\n        }\n    </script>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_content_inside_style_tags(): void
    {
        $input = "\n<div>\n<style>\n.foo {\ncolor: red;\n}\n</style>\n</div>";
        $expected = "\n<div>\n    <style>\n        .foo {\n            color: red;\n        }\n    </style>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_handles_single_line_preserved_blocks_without_entering_preserve_mode(): void
    {
        $input = "\n<div>\n<pre>single line</pre>\n<p>After</p>\n</div>";
        $expected = "\n<div>\n    <pre>single line</pre>\n    <p>After</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Blade expression delimiters ──────────────────────────────────

    #[Test]
    public function it_does_not_count_double_curly_braces_as_brace_nesting(): void
    {
        $input = "\n<div>\n<p>{{ \$user->name }}</p>\n<p>{{ \$user->email }}</p>\n</div>";
        $expected = "\n<div>\n    <p>{{ \$user->name }}</p>\n    <p>{{ \$user->email }}</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_count_unescaped_braces_as_brace_nesting(): void
    {
        $input = "\n<div>\n<p>{!! \$html !!}</p>\n<p>After</p>\n</div>";
        $expected = "\n<div>\n    <p>{!! \$html !!}</p>\n    <p>After</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_count_blade_comments_as_brace_nesting(): void
    {
        $input = "\n<div>\n{{-- This is a comment --}}\n<p>After</p>\n</div>";
        $expected = "\n<div>\n    {{-- This is a comment --}}\n    <p>After</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_ignores_directives_and_tags_inside_multi_line_blade_comments(): void
    {
        $input = "\n<div>\n{{-- Wraps the grid in an\n@island so only it re-renders,\n<section> and @if are prose here\n</div> too. --}}\n<p>After</p>\n</div>";
        $expected = "\n<div>\n    {{-- Wraps the grid in an\n    @island so only it re-renders,\n    <section> and @if are prose here\n    </div> too. --}}\n    <p>After</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_resumes_formatting_after_a_multi_line_blade_comment(): void
    {
        $input = "\n{{--\n@foreach note\n--}}\n@if (\$a)\n<p>A</p>\n@endif";
        $expected = "\n{{--\n@foreach note\n--}}\n@if (\$a)\n    <p>A</p>\n@endif";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_handles_mixed_blade_expressions_and_real_braces(): void
    {
        $input = "\n@props([\n'name' => '{{ \$default }}',\n])";
        $expected = "\n@props([\n    'name' => '{{ \$default }}',\n])";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Inline tags in text content ─────────────────────────────────

    #[Test]
    public function it_does_not_indent_for_inline_tags_that_wrap_in_text(): void
    {
        $input = "\n<li>\nClick <x-ui>Settings</x-ui>, select the items\nyou want to update, and click <x-ui>Save\nChanges</x-ui>.\n</li>";
        $expected = "\n<li>\n    Click <x-ui>Settings</x-ui>, select the items\n    you want to update, and click <x-ui>Save\n    Changes</x-ui>.\n</li>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_indent_for_inline_elements_that_wrap(): void
    {
        $input = "\n<p>\nHowever,\n<strong>admin users can override the default settings for\nthis section</strong>. This provides additional flexibility.\n</p>";
        $expected = "\n<p>\n    However,\n    <strong>admin users can override the default settings for\n    this section</strong>. This provides additional flexibility.\n</p>";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Dynamic tag names ───────────────────────────────────────────

    #[Test]
    public function it_indents_content_inside_dynamic_tags(): void
    {
        $input = "\n<{{ \$as }}>\n{{ \$slot }}\n</{{ \$as }}>";
        $expected = "\n<{{ \$as }}>\n    {{ \$slot }}\n</{{ \$as }}>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_multi_line_dynamic_tag_with_attributes_class(): void
    {
        $input = "\n<{{ \$as }} {{ \$attributes->class([\n'border-l-4 bg-gray-50',\n'mt-0' => \$as === 'div',\n]) }}>\n{{ \$slot }}\n</{{ \$as }}>";
        $expected = "\n<{{ \$as }} {{ \$attributes->class([\n    'border-l-4 bg-gray-50',\n    'mt-0' => \$as === 'div',\n]) }}>\n    {{ \$slot }}\n</{{ \$as }}>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_handles_single_line_dynamic_tags(): void
    {
        $input = "\n<div>\n<{{ \$as }}>Content</{{ \$as }}>\n</div>";
        $expected = "\n<div>\n    <{{ \$as }}>Content</{{ \$as }}>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_handles_self_closing_dynamic_tags(): void
    {
        $input = "\n<div>\n<{{ \$tag }} />\n<p>After</p>\n</div>";
        $expected = "\n<div>\n    <{{ \$tag }} />\n    <p>After</p>\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_handles_dynamic_tags_with_simple_attributes(): void
    {
        $input = "\n<{{ \$as }}\nclass=\"foo\"\n>\n{{ \$slot }}\n</{{ \$as }}>";
        $expected = "\n<{{ \$as }}\n    class=\"foo\"\n>\n    {{ \$slot }}\n</{{ \$as }}>";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Conditional wrapping (crossed HTML/Blade nesting) ───────────

    #[Test]
    public function it_aligns_endif_with_if_when_html_tag_opens_between_them(): void
    {
        $input = "\n@if (\$wrap)\n<div>\n@endif\n<p>Content</p>\n@if (\$wrap)\n</div>\n@endif";
        $expected = "\n@if (\$wrap)\n    <div>\n@endif\n<p>Content</p>\n@if (\$wrap)\n    </div>\n@endif";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_aligns_endif_in_nested_conditional_wrap(): void
    {
        $input = "\n@if (\$outer)\n@if (\$wrap)\n<div>\n@endif\n<p>Content</p>\n@if (\$wrap)\n</div>\n@endif\n@endif";
        $expected = "\n@if (\$outer)\n    @if (\$wrap)\n        <div>\n    @endif\n    <p>Content</p>\n    @if (\$wrap)\n        </div>\n    @endif\n@endif";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── No-indent elements ──────────────────────────────────────────

    #[Test]
    public function it_does_not_indent_children_of_html_tag(): void
    {
        $input = "\n<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>Test</title>\n</head>\n<body>\n<div>\n<p>Hello</p>\n</div>\n</body>\n</html>";
        $expected = "\n<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"utf-8\">\n    <title>Test</title>\n</head>\n<body>\n    <div>\n        <p>Hello</p>\n    </div>\n</body>\n</html>";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Level restore with forgotten closing tags ───────────────────

    #[Test]
    public function it_restores_level_after_conditional_wrap_and_tracks_forgotten_closing_tag(): void
    {
        $input = "\n@if (\$outer)\n@if (\$wrap)\n<div>\n@endif\n<figure>\n<p>Content</p>\n</figure>\n@if (\$wrap)\n</div>\n@endif\n@endif";
        $expected = "\n@if (\$outer)\n    @if (\$wrap)\n        <div>\n    @endif\n    <figure>\n        <p>Content</p>\n    </figure>\n    @if (\$wrap)\n        </div>\n    @endif\n@endif";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Nested ternary depth ────────────────────────────────────────

    #[Test]
    public function it_indents_nested_ternary_operators_with_cumulative_depth(): void
    {
        $input = "\n<div\n:class=\"a\n? 'x'\n: b\n? 'y'\n: 'z'\"\n></div>";
        $expected = "\n<div\n    :class=\"a\n        ? 'x'\n        : b\n            ? 'y'\n            : 'z'\"\n></div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_resets_ternary_depth_on_non_ternary_lines(): void
    {
        $input = "\n<div\n:class=\"a\n? 'x'\n: 'y'\"\nclass=\"test\"\n:other=\"c\n? 'p'\n: 'q'\"\n></div>";
        $expected = "\n<div\n    :class=\"a\n        ? 'x'\n        : 'y'\"\n    class=\"test\"\n    :other=\"c\n        ? 'p'\n        : 'q'\"\n></div>";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Component inline text-after-tag ─────────────────────────────

    #[Test]
    public function it_does_not_indent_for_components_with_inline_text(): void
    {
        $input = "\n<p>\n<x-nav-link id=\"test\">Learn more about\nthis feature</x-nav-link>.\nMore text here.\n</p>";
        $expected = "\n<p>\n    <x-nav-link id=\"test\">Learn more about\n    this feature</x-nav-link>.\n    More text here.\n</p>";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Logical operators are not continuation-indented ─────────────

    #[Test]
    public function it_does_not_add_continuation_indent_for_logical_operators(): void
    {
        $input = "\n@if (\n! \$a\n&& ! \$b\n|| \$c\n)\n<p>Content</p>\n@endif";
        $expected = "\n@if (\n    ! \$a\n    && ! \$b\n    || \$c\n)\n    <p>Content</p>\n@endif";

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Single-line directives inside multi-line tags ───────────────

    #[Test]
    public function it_does_not_increase_depth_for_single_line_directive_in_multi_line_tag(): void
    {
        $input = <<<'BLADE'

<x-dropdown
{{ $attributes->class('group') }}
@if ($open) expanded @endif
data-role="menu"
>
<div>Content</div>
</x-dropdown>
BLADE;
        $expected = <<<'BLADE'

<x-dropdown
    {{ $attributes->class('group') }}
    @if ($open) expanded @endif
    data-role="menu"
>
    <div>Content</div>
</x-dropdown>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }
    // ── Multi-line echo expressions inside multi-line tags ──────────

    #[Test]
    public function it_indents_a_multi_line_echo_array_inside_a_multi_line_tag(): void
    {
        $input = <<<'BLADE'

<dl
{{ $attributes->class([
'grid gap-x-6',
// Two columns only once there is room for them.
'sm:inline-grid sm:grid-cols-[repeat(2,auto)]',
]) }}
>
{{ $slot }}
</dl>
BLADE;
        $expected = <<<'BLADE'

<dl
    {{ $attributes->class([
        'grid gap-x-6',
        // Two columns only once there is room for them.
        'sm:inline-grid sm:grid-cols-[repeat(2,auto)]',
    ]) }}
>
    {{ $slot }}
</dl>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_a_raw_echo_array_inside_a_multi_line_tag(): void
    {
        $input = <<<'BLADE'

<div
{!! $attributes->merge([
'class' => 'p-4',
]) !!}
>
<span>Content</span>
</div>
BLADE;
        $expected = <<<'BLADE'

<div
    {!! $attributes->merge([
        'class' => 'p-4',
    ]) !!}
>
    <span>Content</span>
</div>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_ignores_braces_in_string_literals_inside_a_multi_line_tag(): void
    {
        $input = <<<'BLADE'

<div>
<x-option
:label="t('{count, plural, one {# item} other {# items}}', ['count' => $count])"
:hint="t('{count, plural, one {Move to {name}} other {Move all to {name}}}', ['count' => $count])"
/>
<p>After</p>
</div>
BLADE;
        $expected = <<<'BLADE'

<div>
    <x-option
        :label="t('{count, plural, one {# item} other {# items}}', ['count' => $count])"
        :hint="t('{count, plural, one {Move to {name}} other {Move all to {name}}}', ['count' => $count])"
    />
    <p>After</p>
</div>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_aligns_forelse_empty_with_its_opening_directive(): void
    {
        $input = <<<'BLADE'
<div>
@forelse ($items as $item)
<div>
<span>{{ $item }}</span>
</div>
@empty
<span>None</span>
@endforelse
</div>
<p>After</p>
BLADE;
        $expected = <<<'BLADE'
<div>
    @forelse ($items as $item)
        <div>
            <span>{{ $item }}</span>
        </div>
    @empty
        <span>None</span>
    @endforelse
</div>
<p>After</p>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_still_treats_empty_with_an_argument_as_a_block(): void
    {
        $input = <<<'BLADE'
<div>
@empty($items)
<p>None</p>
@endempty
@empty ($others)
<p>No others</p>
@endempty
</div>
BLADE;
        $expected = <<<'BLADE'
<div>
    @empty($items)
        <p>None</p>
    @endempty
    @empty ($others)
        <p>No others</p>
    @endempty
</div>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }

    // ── Livewire, Volt, and newer Laravel block directives ───────────

    #[Test]
    public function it_indents_livewire_island_blocks(): void
    {
        $input = <<<'BLADE'
<div>
@island
<p>Hello</p>
@endisland
@island(name: 'stats', lazy: true)
<div>
<span>{{ $this->total }}</span>
</div>
@placeholder
<p>Loading...</p>
@endplaceholder
@endisland
</div>
BLADE;
        $expected = <<<'BLADE'
<div>
    @island
        <p>Hello</p>
    @endisland
    @island(name: 'stats', lazy: true)
        <div>
            <span>{{ $this->total }}</span>
        </div>
        @placeholder
            <p>Loading...</p>
        @endplaceholder
    @endisland
</div>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_island_blocks_inside_components(): void
    {
        $input = <<<'BLADE'
<x-card>
@island
<p>Hello</p>
@endisland
</x-card>
BLADE;
        $expected = <<<'BLADE'
<x-card>
    @island
        <p>Hello</p>
    @endisland
</x-card>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_volt_blocks(): void
    {
        $input = <<<'BLADE'
<x-layout>
@volt('counter')
<div>
<button wire:click="increment">{{ $count }}</button>
</div>
@endvolt
</x-layout>
BLADE;
        $expected = <<<'BLADE'
<x-layout>
    @volt('counter')
        <div>
            <button wire:click="increment">{{ $count }}</button>
        </div>
    @endvolt
</x-layout>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_indents_session_and_context_blocks(): void
    {
        $input = <<<'BLADE'
<div>
@session('status')
<p>{{ $value }}</p>
@endsession
@context('locale')
<p>{{ $value }}</p>
@endcontext
</div>
BLADE;
        $expected = <<<'BLADE'
<div>
    @session('status')
        <p>{{ $value }}</p>
    @endsession
    @context('locale')
        <p>{{ $value }}</p>
    @endcontext
</div>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_matches_camel_case_closing_directives(): void
    {
        $input = <<<'BLADE'
<div>
@pushOnce('scripts')
<script></script>
@endPushOnce
@pushIf($shouldPush, 'scripts')
<script></script>
@endPushIf
@prependOnce('scripts')
<script></script>
@endPrependOnce
<p>After</p>
</div>
BLADE;
        $expected = <<<'BLADE'
<div>
    @pushOnce('scripts')
        <script></script>
    @endPushOnce
    @pushIf($shouldPush, 'scripts')
        <script></script>
    @endPushIf
    @prependOnce('scripts')
        <script></script>
    @endPrependOnce
    <p>After</p>
</div>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_matches_directive_names_case_insensitively(): void
    {
        $input = <<<'BLADE'
<div>
@pushonce('scripts')
<script></script>
@endpushonce
@hassection('sidebar')
<aside>@yield('sidebar')</aside>
@endif
</div>
BLADE;
        $expected = <<<'BLADE'
<div>
    @pushonce('scripts')
        <script></script>
    @endpushonce
    @hassection('sidebar')
        <aside>@yield('sidebar')</aside>
    @endif
</div>
BLADE;

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_keeps_single_line_camel_case_blocks_flat(): void
    {
        $input = "<div>
@pushOnce('scripts') <script></script> @endPushOnce
<p>After</p>
</div>";
        $expected = "<div>
    @pushOnce('scripts') <script></script> @endPushOnce
    <p>After</p>
</div>";

        $this->assertSame($expected, $this->indent($input));
    }
}
