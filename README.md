# Blade Formatter

A tool that formats Blade templates and Livewire Single File Components. Like [Laravel Pint](https://laravel.com/docs/pint) for Blade — run a single command and your files are clean.

## What It Does

Blade Formatter runs three formatters in sequence:

1. **PHP formatting** — Formats PHP in Livewire SFC sections and `@php`/`@endphp` blocks using Laravel Pint
2. **Blade indentation** — Auto-indents Blade templates with proper nesting for directives, HTML, and components
3. **Tailwind class sorting** — Sorts Tailwind CSS classes using Prettier with [prettier-plugin-tailwindcss](https://github.com/tailwindlabs/prettier-plugin-tailwindcss)

Each formatter can be enabled or disabled independently.

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

# Only format files changed since a branch
vendor/bin/blade-format --diff=main
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

## VS Code Extension

The extension provides format-on-save and automatic PHP/Blade language switching in Livewire SFCs and `@php`/`@endphp` blocks. All settings are under `bladeFormatter.*`:

| Setting | Default | Description |
|---------|---------|-------------|
| `enable` | `true` | Enable or disable the extension |
| `formatOnSave` | `true` | Format Blade files on save |
| `enableLanguageSwitching` | `true` | Switch PHP/Blade language modes in SFCs |
| `executablePath` | `""` | Custom path to `blade-format` binary |

Formatting options (indent size, enable/disable formatters, etc.) are configured in `blade-formatter.json` — not in VS Code settings. This keeps CLI and editor output consistent.

## CI Integration

```yaml
- name: Check formatting
  run: |
    vendor/bin/pint --test
    vendor/bin/blade-format --test
```

## License

MIT
