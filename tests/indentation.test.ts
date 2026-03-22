import { describe, it, expect } from 'vitest';
import { formatIndentation } from '../src/formatters/indentation';

const indent = (input: string, size = 4) => formatIndentation(input, size);

describe('HTML tag indentation', () => {
    it('indents children of opening tags', () => {
        const input = `
<div>
<p>Hello</p>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <p>Hello</p>
</div>`);
    });

    it('handles nested tags', () => {
        const input = `
<div>
<section>
<p>Hello</p>
</section>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <section>
        <p>Hello</p>
    </section>
</div>`);
    });

    it('does not indent after void elements', () => {
        const input = `
<div>
<input>
<img>
<p>Hello</p>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <input>
    <img>
    <p>Hello</p>
</div>`);
    });

    it('does not indent after self-closing tags', () => {
        const input = `
<div>
<br />
<hr />
<p>Hello</p>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <br />
    <hr />
    <p>Hello</p>
</div>`);
    });

    it('handles same-line open and close tags', () => {
        const input = `
<div>
<p>Hello</p>
<p>World</p>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <p>Hello</p>
    <p>World</p>
</div>`);
    });

    it('preserves empty lines', () => {
        const input = `
<div>

<p>Hello</p>

</div>`;

        expect(indent(input)).toBe(`
<div>

    <p>Hello</p>

</div>`);
    });
});

describe('Blade component indentation', () => {
    it('indents children of flux components', () => {
        const input = `
<flux:modal>
<p>Content</p>
</flux:modal>`;

        expect(indent(input)).toBe(`
<flux:modal>
    <p>Content</p>
</flux:modal>`);
    });

    it('indents children of x- components', () => {
        const input = `
<x-layout>
<x-slot name="header">
<h1>Title</h1>
</x-slot>
<p>Body</p>
</x-layout>`;

        expect(indent(input)).toBe(`
<x-layout>
    <x-slot name="header">
        <h1>Title</h1>
    </x-slot>
    <p>Body</p>
</x-layout>`);
    });

    it('does not indent after self-closing components', () => {
        const input = `
<div>
<flux:button />
<x-icon name="check" />
<p>Hello</p>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <flux:button />
    <x-icon name="check" />
    <p>Hello</p>
</div>`);
    });

    it('does not indent after self-closing tags with > in attribute values', () => {
        const input = `
<flux:dropdown>
<flux:profile :initials="auth()->user()->initials()" />
<flux:menu>
<p>Content</p>
</flux:menu>
</flux:dropdown>`;

        expect(indent(input)).toBe(`
<flux:dropdown>
    <flux:profile :initials="auth()->user()->initials()" />
    <flux:menu>
        <p>Content</p>
    </flux:menu>
</flux:dropdown>`);
    });
});

describe('Blade directive indentation', () => {
    it('indents @if/@endif blocks', () => {
        const input = `
@if($show)
<p>Visible</p>
@endif`;

        expect(indent(input)).toBe(`
@if($show)
    <p>Visible</p>
@endif`);
    });

    it('handles @else at same level as @if', () => {
        const input = `
@if($show)
<p>Yes</p>
@else
<p>No</p>
@endif`;

        expect(indent(input)).toBe(`
@if($show)
    <p>Yes</p>
@else
    <p>No</p>
@endif`);
    });

    it('handles @elseif at same level as @if', () => {
        const input = `
@if($a)
<p>A</p>
@elseif($b)
<p>B</p>
@else
<p>C</p>
@endif`;

        expect(indent(input)).toBe(`
@if($a)
    <p>A</p>
@elseif($b)
    <p>B</p>
@else
    <p>C</p>
@endif`);
    });

    it('indents @foreach/@endforeach blocks', () => {
        const input = `
@foreach($items as $item)
<li>{{ $item }}</li>
@endforeach`;

        expect(indent(input)).toBe(`
@foreach($items as $item)
    <li>{{ $item }}</li>
@endforeach`);
    });

    it('indents nested directives', () => {
        const input = `
@foreach($items as $item)
@if($item->active)
<li>{{ $item->name }}</li>
@endif
@endforeach`;

        expect(indent(input)).toBe(`
@foreach($items as $item)
    @if($item->active)
        <li>{{ $item->name }}</li>
    @endif
@endforeach`);
    });

    it('indents @auth/@endauth blocks', () => {
        const input = `
@auth
<p>Logged in</p>
@endauth`;

        expect(indent(input)).toBe(`
@auth
    <p>Logged in</p>
@endauth`);
    });

    it('indents @switch/@case/@endswitch blocks', () => {
        const input = `
@switch($type)
@case('a')
<p>A</p>
@break
@default
<p>Default</p>
@endswitch`;

        expect(indent(input)).toBe(`
@switch($type)
    @case('a')
        <p>A</p>
        @break
    @default
        <p>Default</p>
@endswitch`);
    });

    it('does not indent after inline @php @endphp', () => {
        const input = `
<div>
@php $var = 'value'; @endphp
<p>After</p>
</div>`;

        expect(indent(input)).toBe(`
<div>
    @php $var = 'value'; @endphp
    <p>After</p>
</div>`);
    });

    it('still indents block @php @endphp', () => {
        const input = `
<div>
@php
$var = 'value';
@endphp
<p>After</p>
</div>`;

        expect(indent(input)).toBe(`
<div>
    @php
        $var = 'value';
    @endphp
    <p>After</p>
</div>`);
    });
});

describe('multi-line tag indentation', () => {
    it('indents attributes of multi-line tags', () => {
        const input = `
<flux:input
name="email"
type="email"
required
/>`;

        expect(indent(input)).toBe(`
<flux:input
    name="email"
    type="email"
    required
/>`);
    });

    it('aligns closing bracket with opening tag', () => {
        const input = `
<div
class="container"
>
<p>Hello</p>
</div>`;

        expect(indent(input)).toBe(`
<div
    class="container"
>
    <p>Hello</p>
</div>`);
    });

    it('handles self-closing multi-line tags without adding child indent', () => {
        const input = `
<div>
<flux:input
name="email"
type="email"
/>
<p>After</p>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <flux:input
        name="email"
        type="email"
    />
    <p>After</p>
</div>`);
    });

    it('indents nested multi-line tags', () => {
        const input = `
<div>
<form
method="POST"
action="/submit"
>
<flux:input
name="email"
required
/>
</form>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <form
        method="POST"
        action="/submit"
    >
        <flux:input
            name="email"
            required
        />
    </form>
</div>`);
    });
});

describe('Alpine x-data indentation', () => {
    it('indents x-data object contents', () => {
        const input = `
<div
x-data="{
open: false,
name: '',
}"
>
<p>Hello</p>
</div>`;

        expect(indent(input)).toBe(`
<div
    x-data="{
        open: false,
        name: '',
    }"
>
    <p>Hello</p>
</div>`);
    });

    it('indents nested functions in x-data', () => {
        const input = `
<div
x-data="{
open: false,
toggle() {
this.open = !this.open;
},
}"
>
<p>Content</p>
</div>`;

        expect(indent(input)).toBe(`
<div
    x-data="{
        open: false,
        toggle() {
            this.open = !this.open;
        },
    }"
>
    <p>Content</p>
</div>`);
    });
});

describe('brace nesting outside tags', () => {
    it('indents @props array contents', () => {
        const input = `
@props([
'on',
'color' => 'blue',
])`;

        expect(indent(input)).toBe(`
@props([
    'on',
    'color' => 'blue',
])`);
    });

    it('indents nested arrays in @props', () => {
        const input = `
@props([
'options' => [
'a',
'b',
],
])`;

        expect(indent(input)).toBe(`
@props([
    'options' => [
        'a',
        'b',
    ],
])`);
    });
});

describe('mixed content', () => {
    it('handles a realistic Blade template', () => {
        const input = `
<div>
@if($users->count())
<ul>
@foreach($users as $user)
<li>
<x-avatar
:src="$user->avatar"
:alt="$user->name"
/>
<span>{{ $user->name }}</span>
</li>
@endforeach
</ul>
@else
<p>No users found.</p>
@endif
</div>`;

        expect(indent(input)).toBe(`
<div>
    @if($users->count())
        <ul>
            @foreach($users as $user)
                <li>
                    <x-avatar
                        :src="$user->avatar"
                        :alt="$user->name"
                    />
                    <span>{{ $user->name }}</span>
                </li>
            @endforeach
        </ul>
    @else
        <p>No users found.</p>
    @endif
</div>`);
    });

    it('handles custom indent size', () => {
        const input = `
<div>
<p>Hello</p>
</div>`;

        expect(indent(input, 2)).toBe(`
<div>
  <p>Hello</p>
</div>`);
    });
});

describe('preserved blocks', () => {
    it('preserves content inside @verbatim', () => {
        const input = `
<div>
@verbatim
    <div>
        {{ $unprocessed }}
    </div>
@endverbatim
</div>`;

        expect(indent(input)).toBe(`
<div>
    @verbatim
    <div>
        {{ $unprocessed }}
    </div>
    @endverbatim
</div>`);
    });

    it('preserves content inside <pre> tags', () => {
        const input = `
<div>
<pre>
  line 1
    line 2
      line 3
</pre>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <pre>
  line 1
    line 2
      line 3
    </pre>
</div>`);
    });

    it('indents content inside <script> tags', () => {
        const input = `
<div>
<script>
function hello() {
console.log('hi');
}
</script>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <script>
        function hello() {
            console.log('hi');
        }
    </script>
</div>`);
    });

    it('indents content inside <style> tags', () => {
        const input = `
<div>
<style>
.foo {
color: red;
}
</style>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <style>
        .foo {
            color: red;
        }
    </style>
</div>`);
    });

    it('handles single-line preserved blocks without entering preserve mode', () => {
        const input = `
<div>
<pre>single line</pre>
<p>After</p>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <pre>single line</pre>
    <p>After</p>
</div>`);
    });
});

describe('Blade expression delimiters', () => {
    it('does not count {{ }} as brace nesting', () => {
        const input = `
<div>
<p>{{ $user->name }}</p>
<p>{{ $user->email }}</p>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <p>{{ $user->name }}</p>
    <p>{{ $user->email }}</p>
</div>`);
    });

    it('does not count {!! !!} as brace nesting', () => {
        const input = `
<div>
<p>{!! $html !!}</p>
<p>After</p>
</div>`;

        expect(indent(input)).toBe(`
<div>
    <p>{!! $html !!}</p>
    <p>After</p>
</div>`);
    });

    it('does not count {{-- --}} comments as brace nesting', () => {
        const input = `
<div>
{{-- This is a comment --}}
<p>After</p>
</div>`;

        expect(indent(input)).toBe(`
<div>
    {{-- This is a comment --}}
    <p>After</p>
</div>`);
    });

    it('handles mixed Blade expressions and real braces', () => {
        const input = `
@props([
'name' => '{{ $default }}',
])`;

        expect(indent(input)).toBe(`
@props([
    'name' => '{{ $default }}',
])`);
    });
});
