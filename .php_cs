<?php

return PhpCsFixer\Config::create()
    ->setUsingCache(false)
    ->setRules([
        '@PSR2' => true,
        '@Symfony' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['align_double_arrow' => true, 'align_equals' => null],
        'concat_space' => ['spacing' => 'one'],
        'ordered_imports' => true,

        // Overrides
        'phpdoc_summary' => false,
    ])
    ->setFinder(PhpCsFixer\Finder::create()
        ->in(['.'])
        ->exclude('vendor')
        ->exclude('tests')
    );
