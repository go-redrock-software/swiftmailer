<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

return (new Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => false,
        '@PHPUnit100Migration:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'php_unit_fqcn_annotation' => true,
        'no_unreachable_default_argument_value' => false,
        'braces' => ['allow_single_line_closure' => false],
        'heredoc_to_nowdoc' => false,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_types_order' => ['null_adjustment' => 'always_last', 'sort_algorithm' => 'none'],
        'native_function_invocation' => ['include' => ['@all'], 'scope' => 'all'],
        'modernize_strpos' => true,
        'class_attributes_separation' => ['elements' => ['const' => 'one', 'method' => 'one', 'property' => 'one']],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'binary_operator_spaces' => ['default' => 'align_single_space_minimal'],
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
            'keep_multiple_spaces_after_comma' => false,
            'after_heredoc' => true,
            'attribute_placement' => 'same_line',

        ],
        'no_unused_imports' => true,
        'no_extra_blank_lines' => true,
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof' => true,
        'no_blank_lines_after_phpdoc' => true,
        'no_closing_tag' => true,
        'method_chaining_indentation'=> true,

    ])
    ->setRiskyAllowed(true)
    ->setFinder(
        Finder::create()
            ->in(__DIR__)
            ->name('*.php')
            ->exclude(['vendor', 'node_modules'])
    )
    ->setParallelConfig(ParallelConfigFactory::detect())
;