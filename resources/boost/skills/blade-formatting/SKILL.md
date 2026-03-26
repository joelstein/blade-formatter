---
name: blade-formatting
description: Format Blade templates and Livewire SFCs using Blade Formatter with Pint, indentation, and Tailwind class sorting.
---

# Blade Formatting

## When to Apply

- After creating or modifying Blade templates or Livewire Single File Components.
- When setting up CI pipelines that include code formatting checks.
- When configuring formatting preferences for a Laravel project.

## When NOT to Apply

- For PHP files outside of Blade templates — use Laravel Pint directly.
- For non-Blade HTML or Twig templates.

## Process

### Step 1: Format Blade Files

Run the formatter after making changes to Blade files:

```bash
# Format all Blade files in configured paths
vendor/bin/blade-format

# Format a specific directory
vendor/bin/blade-format resources/views/components

# Format only files changed since a branch
vendor/bin/blade-format --diff=main
```

### Step 2: Check Formatting in CI

Use `--test` to check without modifying files. Combine with `--bail` to fail fast:

```bash
vendor/bin/blade-format --test
vendor/bin/blade-format --test --bail
```

### Step 3: Configure (Optional)

Create `blade-formatter.json` in the project root to customize behavior:

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

| Option | Description |
|--------|-------------|
| `paths` | Directories to scan for Blade files. Defaults to `resources/views`. |
| `exclude` | Glob patterns to exclude from formatting. |
| `indent_size` | Number of spaces per indentation level. Defaults to `4`. |
| `pint_config_path` | Path to a custom Pint configuration file. Uses Pint's default resolution if `null`. |
| `enable_pint` | Enable PHP formatting via Laravel Pint. |
| `enable_tailwind_sort` | Enable Tailwind CSS class sorting via Prettier. |
| `enable_indentation` | Enable Blade template auto-indentation. |
| `prettier_path` | Path to the Prettier binary. |

### Step 4: Install VS Code Extension (Optional)

```bash
code --install-extension vendor/joelstein/blade-formatter/builds/blade-formatter.vsix
```

The extension provides format-on-save and automatic PHP/Blade language switching in Livewire SFCs. Formatting options are configured in `blade-formatter.json`, not VS Code settings.

## Checklist

- [ ] Blade files are formatted before committing
- [ ] CI pipeline includes `vendor/bin/blade-format --test`
- [ ] `blade-formatter.json` is committed to version control (if customized)
- [ ] Node.js dependencies installed if using Tailwind class sorting (`prettier`, `prettier-plugin-tailwindcss`)

## References

- [Laravel Pint](https://laravel.com/docs/pint)
- [Prettier Plugin Tailwind CSS](https://github.com/tailwindlabs/prettier-plugin-tailwindcss)
