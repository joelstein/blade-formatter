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
    public function it_indents_switch_case_endswitch_blocks(): void
    {
        $input = "\n@switch(\$type)\n@case('a')\n<p>A</p>\n@break\n@default\n<p>Default</p>\n@endswitch";
        $expected = "\n@switch(\$type)\n    @case('a')\n        <p>A</p>\n        @break\n    @default\n        <p>Default</p>\n@endswitch";

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
        $input = "\n<div>\n@php(\$globalLimit = app(Settings::class)->maxRequestsPerUserPerYear)\n<flux:input wire:model=\"formMaxRequestsPerYear\" />\n<flux:switch wire:model=\"formIsAdmin\" />\n</div>";
        $expected = "\n<div>\n    @php(\$globalLimit = app(Settings::class)->maxRequestsPerUserPerYear)\n    <flux:input wire:model=\"formMaxRequestsPerYear\" />\n    <flux:switch wire:model=\"formIsAdmin\" />\n</div>";

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
        $expected = "\n<div>\n    @verbatim\n    <div>\n        {{ \$unprocessed }}\n    </div>\n    @endverbatim\n</div>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_preserves_content_inside_pre_tags(): void
    {
        $input = "\n<div>\n<pre>\n  line 1\n    line 2\n      line 3\n</pre>\n</div>";
        $expected = "\n<div>\n    <pre>\n  line 1\n    line 2\n      line 3\n    </pre>\n</div>";

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
        $input = "\n<li>\nClick <x-ui>Edit Schedule</x-ui>, select the range of hours\nyou want to close, and click <x-ui>Edit Selected\nHours</x-ui>.\n</li>";
        $expected = "\n<li>\n    Click <x-ui>Edit Schedule</x-ui>, select the range of hours\n    you want to close, and click <x-ui>Edit Selected\n    Hours</x-ui>.\n</li>";

        $this->assertSame($expected, $this->indent($input));
    }

    #[Test]
    public function it_does_not_indent_for_inline_elements_that_wrap(): void
    {
        $input = "\n<p>\nHowever,\n<strong>administrators and captains can create commitments on\nclosed hours</strong>. This provides a creative workaround.\n</p>";
        $expected = "\n<p>\n    However,\n    <strong>administrators and captains can create commitments on\n    closed hours</strong>. This provides a creative workaround.\n</p>";

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
}
