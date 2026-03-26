## Blade Formatter

This package formats Blade templates and Livewire Single File Components. It runs three formatters in sequence: PHP formatting via Laravel Pint, Blade indentation, and Tailwind CSS class sorting via Prettier. Each formatter can be enabled or disabled independently.

### Usage

Format all Blade files with `vendor/bin/blade-format`. Pass paths to format specific files or directories:

@verbatim
<code-snippet name="Format Blade files" lang="bash">
# Format all Blade files
vendor/bin/blade-format

# Format specific files or directories
vendor/bin/blade-format resources/views/components

# Check formatting without making changes (for CI)
vendor/bin/blade-format --test

# Stop on first file that would change (for CI)
vendor/bin/blade-format --bail

# Only format files changed since a branch
vendor/bin/blade-format --diff=main
</code-snippet>
@endverbatim

### Configuration

Configure via `blade-formatter.json` in the project root. All settings are optional:

@verbatim
<code-snippet name="blade-formatter.json" lang="json">
{
    "paths": ["resources/views"],
    "exclude": [],
    "indent_size": 4,
    "pint_config_path": null,
    "enable_pint": true,
    "enable_tailwind_sort": true,
    "enable_indentation": true,
    "prettier_path": "node_modules/.bin/prettier"
}
</code-snippet>
@endverbatim

### CI Integration

Run `vendor/bin/blade-format --test` alongside Pint in CI pipelines. It returns a non-zero exit code if any files would change:

@verbatim
<code-snippet name="CI workflow step" lang="yaml">
- name: Check formatting
  run: |
    vendor/bin/pint --test
    vendor/bin/blade-format --test
</code-snippet>
@endverbatim

### Key Behaviors

- Markdown mail templates (e.g. `<x-mail::message>`) are automatically skipped to preserve whitespace-sensitive formatting.
- Tailwind class sorting requires Node.js and the `prettier` and `prettier-plugin-tailwindcss` npm packages.
- PHP formatting in `@php`/`@endphp` blocks and Livewire SFC sections uses the project's Pint configuration.
