<?php

return [
    'default' => BladeFormatter\Commands\DefaultCommand::class,
    'paths' => [app_path('Commands')],
    'add' => [],
    'hidden' => [
        NunoMaduro\LaravelConsoleSummary\SummaryCommand::class,
        Symfony\Component\Console\Command\DumpCompletionCommand::class,
    ],
    'remove' => [],
];
