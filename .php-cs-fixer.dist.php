<?php

// php-cs-fixer config (committed default). Consumed by treefmt's php formatter
// (see treefmt.toml) and by a standalone `php-cs-fixer fix`. treefmt passes
// explicit file paths, which override the finder below in path-mode=override;
// the finder is for standalone runs.
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/api'])
    ->exclude('vendor');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder);
