# Blade Formatter

A Laravel package that formats Blade templates and Livewire Single File Components. Similar to how [Laravel Pint](https://laravel.com/docs/pint) formats PHP — run a single command and your Blade files are clean.

Includes a VS Code extension for format-on-save.

## What It Does

Blade Formatter runs three formatters in sequence:

1. **PHP formatting** — Formats the PHP section of Livewire SFCs using Laravel Pint
2. **Tailwind class sorting** — Sorts Tailwind CSS classes using Prettier with [prettier-plugin-tailwindcss](https://github.com/tailwindlabs/prettier-plugin-tailwindcss) (the official Tailwind Labs solution)
3. **Blade indentation** — Auto-indents Blade templates with proper nesting for HTML tags, Blade directives (`@if`/`@foreach`/`@switch`, etc.), and component tags (`<x-*>`, `<flux:*>`, `<livewire:*>`)

Each formatter can be enabled or disabled independently.

### What Gets Indented

- HTML tags (including `<x-*>`, `<flux:*>`, `<livewire:*>` components)
- Blade directives (`@if`/`@endif`, `@foreach`/`@endforeach`, `@switch`/`@case`, etc.)
- Multi-line HTML tag attributes
- Alpine.js `x-data` and other multi-line attribute values with nested braces/brackets
- `@props([...])` and similar multi-line directive arguments

Content inside `@verbatim` and `<pre>` blocks is preserved exactly as-is.

## Requirements

- PHP 8.2+
- Laravel 12+
- Node.js (for Tailwind class sorting via Prettier)

## Installation

Install the package via Composer:

```bash
composer require joelstein/blade-formatter --dev
```

For Tailwind class sorting, install Prettier and the Tailwind plugin:

```bash
npm install -D prettier prettier-plugin-tailwindcss
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=blade-formatter-config
```

## Usage

### Command Line

Format all Blade files:

```bash
vendor/bin/blade-format
```

Or via Artisan:

```bash
php artisan blade:format
```

Format specific files or directories:

```bash
vendor/bin/blade-format resources/views/components
vendor/bin/blade-format resources/views/home.blade.php
```

Check formatting without making changes (useful for CI):

```bash
vendor/bin/blade-format --test
```

Only format git-dirty files:

```bash
vendor/bin/blade-format --dirty
```

### VS Code Extension

The package includes a VS Code extension that formats Blade files on save.

#### Setup

1. Install the PHP package (see [Installation](#installation) above)

2. Build the VS Code extension:

   ```bash
   cd vendor/joelstein/blade-formatter
   npm install
   npm run build
   ```

3. Symlink the extension into VS Code:

   ```bash
   ln -s vendor/joelstein/blade-formatter ~/.vscode/extensions/blade-formatter
   ```

4. Reload VS Code

#### Features

- **Format on save** — Blade files are automatically formatted when you save
- **Manual format** — Use the command palette: `Format Blade File`
- **Language mode switching** — Automatically switches between PHP and Blade language modes based on cursor position in Livewire SFCs, giving you proper intellisense in each section

#### VS Code Settings

All settings are under `bladeFormatter.*`:

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `enable` | boolean | `true` | Enable or disable the extension |
| `formatOnSave` | boolean | `true` | Format Blade files on save |
| `enableLanguageSwitching` | boolean | `true` | Switch PHP/Blade language modes in SFCs |
| `executablePath` | string | `""` | Custom path to `blade-format` binary (defaults to `vendor/bin/blade-format`) |

> **Note:** Formatting options (indent size, enable/disable Pint, Tailwind, etc.) are configured in `blade-formatter.json` or `config/blade-formatter.php` — not in VS Code settings. This keeps CLI and editor formatting consistent.

## Configuration

### Laravel Config

Publish and edit `config/blade-formatter.php`:

```bash
php artisan vendor:publish --tag=blade-formatter-config
```

| Option | Default | Description |
|--------|---------|-------------|
| `paths` | `['resources/views']` | Directories to scan for Blade files |
| `exclude` | `[]` | Paths or patterns to exclude |
| `indent_size` | `4` | Spaces per indentation level |
| `enable_pint` | `true` | Format PHP in Livewire SFCs with Pint |
| `pint_config_path` | `null` | Path to a custom `pint.json` |
| `enable_tailwind_sort` | `true` | Sort Tailwind CSS classes |
| `prettier_path` | `'npx'` | Path to Prettier binary |
| `enable_indentation` | `true` | Auto-indent Blade templates |

### Standalone Config

When using `vendor/bin/blade-format` (without Artisan), create a `blade-formatter.json` in your project root:

```json
{
    "indent_size": 4,
    "enable_pint": true,
    "enable_tailwind_sort": true,
    "enable_indentation": true,
    "prettier_path": "npx",
    "paths": ["resources/views"],
    "exclude": []
}
```

### Faster Tailwind Sorting

By default, the package uses `npx` to run Prettier, which has some startup overhead. For faster execution, point directly to your local binary:

```json
{
    "prettier_path": "node_modules/.bin/prettier"
}
```

## Livewire SFC Support

Livewire Single File Components combine PHP and Blade in one file:

```blade
<?php

use App\Models\User;

new class extends Component {
    public string $name = '';
};
?>

<div>
    <input wire:model="name" />
    <p>Hello, {{ $name }}</p>
</div>
```

Blade Formatter automatically detects SFCs and:

- Formats the PHP section with Laravel Pint
- Formats the Blade section with indentation and Tailwind sorting
- Preserves the boundary between sections

Non-SFC `.blade.php` files get Tailwind class sorting and auto-indentation (Pint is skipped since there's no PHP block).

## CI Integration

Add to your CI pipeline alongside Pint:

```yaml
- name: Check formatting
  run: |
    vendor/bin/pint --test
    vendor/bin/blade-format --test
```

The `--test` flag exits with code 1 if any files need formatting, without modifying them.

## License

MIT
