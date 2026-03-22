<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    |
    | Directories to scan for Blade files. Relative to the project root.
    |
    */

    'paths' => [
        'resources/views',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exclude
    |--------------------------------------------------------------------------
    |
    | Paths or patterns to exclude from formatting.
    |
    */

    'exclude' => [
        'vendor/mail',
        'vendor/notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Indent Size
    |--------------------------------------------------------------------------
    |
    | Number of spaces per indentation level for Blade templates.
    |
    */

    'indent_size' => 4,

    /*
    |--------------------------------------------------------------------------
    | Enable Pint
    |--------------------------------------------------------------------------
    |
    | Format the PHP section of Livewire SFCs using Laravel Pint.
    |
    */

    'enable_pint' => true,

    /*
    |--------------------------------------------------------------------------
    | Pint Config Path
    |--------------------------------------------------------------------------
    |
    | Path to a pint.json config file. When null, Pint uses its default
    | config resolution (looks for pint.json in the project root).
    |
    */

    'pint_config_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Enable Tailwind Sort
    |--------------------------------------------------------------------------
    |
    | Sort Tailwind CSS classes using Prettier with prettier-plugin-tailwindcss.
    | Requires: npm install -D prettier prettier-plugin-tailwindcss
    |
    */

    'enable_tailwind_sort' => true,

    /*
    |--------------------------------------------------------------------------
    | Prettier Path
    |--------------------------------------------------------------------------
    |
    | Path to the Prettier binary. Defaults to 'npx' which uses npx to
    | find Prettier. Set to a direct path like 'node_modules/.bin/prettier'
    | for faster execution.
    |
    */

    'prettier_path' => 'npx',

    /*
    |--------------------------------------------------------------------------
    | Enable Indentation
    |--------------------------------------------------------------------------
    |
    | Auto-indent Blade templates with proper nesting for HTML tags,
    | Blade directives, and component tags.
    |
    */

    'enable_indentation' => true,

];
