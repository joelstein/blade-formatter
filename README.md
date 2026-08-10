# Blade Formatter

A tool that formats Blade templates and Livewire Single File Components. Like [Laravel Pint](https://laravel.com/docs/pint) for Blade — run a single command and your files are clean. Includes a VS Code extension with format-on-save and automatic PHP/Blade language switching.

## What It Does

Blade Formatter runs three formatters in sequence:

1. **PHP formatting** — Formats PHP in Livewire SFC sections and `@php`/`@endphp` blocks using Laravel Pint
2. **Blade indentation** — Auto-indents Blade templates with proper nesting for directives, HTML, components, and Alpine.js attributes
3. **Tailwind class sorting** — Sorts Tailwind CSS classes using Prettier with [prettier-plugin-tailwindcss](https://github.com/tailwindlabs/prettier-plugin-tailwindcss)

Each formatter can be enabled or disabled independently. Markdown mail templates are never indented, since Markdown renders an indented line as a code block.

## Why Not Prettier?

Blade Formatter only changes leading whitespace. It re-indents, but doesn't reflow. Line breaks are left as written.

Other tools, including `pint --blade`, run Blade through Prettier, which also joins short lines, wraps at a print width, and moves attributes onto their own lines. That works well for plenty of projects. A few reasons Blade Formatter doesn't:

- HTML whitespace can be meaningful in ways JSX whitespace usually isn't, so rewrapping sometimes changes what renders.
- Blade mixes PHP and HTML, and how a template reads best tends to depend on what it's doing more than on a fixed width.
- Line length and attributes-per-line are things developers reasonably disagree about.
- Blade components are often one deeply nested tree, since there's no easy way to define and reuse smaller components within a single file. A tall component is fine, since it folds in the editor, but wrapping compounds at every level of nesting.

Mostly this is preference. If you'd rather have full reflow, `pint --blade` does it well.

## Skipped Files

Some Blade files are never formatted, because reformatting them would change what they do rather than just how they look:

| File | Why |
|------|-----|
| `Envoy.blade.php`, `envoy.blade.php` | Indentation inside `@task` is passed through to the shell |
| `resources/boost/guidelines/**` | Whitespace-sensitive Markdown |
| Templates using an `<x-mail::>` component, and anything under `vendor/mail` | Whitespace-sensitive Markdown mail |

These are skipped even when named explicitly on the command line.

Mail templates are a partial case: only indentation is skipped. Pint and Tailwind sorting still run, since neither changes the whitespace Markdown reads.

### Opting Out

Wrap a region in `blade-formatter-disable` / `blade-formatter-enable` comments to leave it byte-for-byte unchanged. Everything outside the region is still formatted:

```blade
<div class="flex gap-2 p-4">
    {{-- blade-formatter-disable --}}
    <pre><code>&lt;x-button class="p-4 flex gap-2"&gt;Save&lt;/x-button&gt;</code></pre>
    {{-- blade-formatter-enable --}}
</div>
```

Omit the closing comment to opt out the rest of the file. Put one at the top and the whole file is skipped.

Class attribute order never affects CSS, so Tailwind sorting is normally safe everywhere, including inside `@verbatim` where Vue and Handlebars templates genuinely want it. The exception is markup that is itself the content: documentation pages showing code samples, or fixtures asserted against as strings. Sorting rewrites the example you are demonstrating, even when it is HTML-escaped inside `<pre>`.

## Requirements

- PHP 8.2+
- Node.js (for Tailwind class sorting)

## Installation

```bash
composer require joelstein/blade-formatter --dev
npm install -D prettier prettier-plugin-tailwindcss

# VS Code extension (optional)
code --install-extension vendor/joelstein/blade-formatter/builds/blade-formatter.vsix
```

The VS Code extension requires the [Laravel](https://marketplace.visualstudio.com/items?itemName=laravel.vscode-laravel) extension.

## Usage

```bash
# Format all Blade files
vendor/bin/blade-format

# Format specific files or directories
vendor/bin/blade-format resources/views/components

# Check formatting without making changes (for CI)
vendor/bin/blade-format --test

# Stop on first file that would change (for CI)
vendor/bin/blade-format --bail

# Fix files, but still exit non-zero if anything changed (for CI)
vendor/bin/blade-format --repair

# Only format files changed since a branch
vendor/bin/blade-format --diff=main

# Only format files with uncommitted changes (including untracked)
vendor/bin/blade-format --dirty
```

## Configuration

Create a `blade-formatter.json` in your project root:

```json
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
```

`exclude` takes a path substring or a delimited regex, matched relative to the working directory, and applies to every run mode — including `--diff`, `--dirty`, and paths named on the command line. `flux/icon`, `vendor/mail`, and `vendor/notifications` are always excluded.

## VS Code Extension

The extension provides format-on-save and automatic PHP/Blade language switching in Livewire SFCs. All settings are under `bladeFormatter.*`:

| Setting | Default | Description |
|---------|---------|-------------|
| `enable` | `true` | Enable or disable the extension |
| `formatOnSave` | `true` | Format Blade files on save |
| `enableLanguageSwitching` | `true` | Switch PHP/Blade language modes in SFCs |
| `executablePath` | `""` | Custom path to `blade-format` binary |

Formatting options (indent size, enable/disable formatters, etc.) are configured in `blade-formatter.json` — not in VS Code settings. This keeps CLI and editor output consistent.

> **Note:** If you use the [Laravel Pint](https://marketplace.visualstudio.com/items?itemName=laravel.vscode-laravel) VS Code extension with `Laravel.pint.runOnSave` enabled, Pint will strip `use` statements from SFCs that are only referenced in Blade — undoing the blade formatter's work. Disable Pint's run-on-save for Blade files in your `.vscode/settings.json`:
>
> ```json
> {
>   "[blade]": {
>     "Laravel.pint.runOnSave": false
>   }
> }
> ```

## CI Integration

```yaml
- name: Check formatting
  run: |
    vendor/bin/pint --test
    vendor/bin/blade-format --test
```

## License

MIT
