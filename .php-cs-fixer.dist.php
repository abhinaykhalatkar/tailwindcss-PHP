<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(array_filter([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/tools',
        __DIR__ . '/bin',
    ], 'is_dir'))
    // php-cs-fixer's own manifest and vendor tree live under tools/.
    ->exclude('php-cs-fixer');

$config = new PhpCsFixer\Config();

return $config
    ->setRiskyAllowed(true)
    ->setLineEnding("\n")
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setRules([
        '@PSR12' => true,
        '@PHP82Migration' => true,
        // The 8.2 floor makes these two rewrites possible (0o777 octal prefix, ??=), but they
        // touch about 85 files for style only; adopting them is a separate decision.
        'octal_notation' => false,
        'assign_null_coalescing_to_coalesce_equal' => false,
        'declare_strict_types' => true,
        'native_function_invocation' => [
            'include' => ['@all'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
        'ordered_imports' => true,
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'phpdoc_align' => ['align' => 'left'],
        'void_return' => true,
        'strict_comparison' => true,
        'strict_param' => true,
    ])
    ->setFinder($finder);
