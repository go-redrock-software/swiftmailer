<?php

use Rector\Config\RectorConfig;
use Rector\Custom\Rector\RenameUnderscoreToNamespaceRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Renaming\Rector\Name\RenameClassRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->sets([
        PHPUnitSetList::PHPUNIT_100,
    ]);
    $rectorConfig->paths([
        'tests',
    ]);

    // Namespace migration tooling (run generate_class_map.php first)
    if (\file_exists(__DIR__.'/class_map.php')) {
        $rectorConfig->rule(RenameUnderscoreToNamespaceRector::class);
        $classMap = include __DIR__.'/class_map.php';
        $rectorConfig->ruleWithConfiguration(RenameClassRector::class, $classMap);
    }

    $rectorConfig->autoloadPaths([
        __DIR__.'/lib',
        __DIR__.'/tests',
    ]);
};
