<?php

$finder = Symfony\Component\Finder\Finder::create()
    ->notPath('vendor')
    ->notPath('bootstrap')
    ->notPath('storage')
    ->notPath('nova')
    ->in(__DIR__)
    ->name('*.php')
    ->notName('*.blade.php');

return (new PhpCsFixer\Config)
    ->setRules([
        '@PSR2' => true,
        'array_syntax' => true,
        'ordered_imports' => ['sort_algorithm' => 'length'],
        'no_unused_imports' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder);
