<?php

declare (strict_types=1);
namespace RectorPrefix202507;

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;
return static function (RectorConfig $rectorConfig) : void {
    $rectorConfig->ruleWithConfiguration(RenameClassRector::class, ['Symfony\\Component\\EventDispatcher\\LegacyEventDispatcherProxy' => 'Symfony\\Component\\EventDispatcher\\EventDispatcherInterface']);
};
