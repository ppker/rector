<?php

declare (strict_types=1);
namespace RectorPrefix202507;

use Rector\Config\RectorConfig;
use Rector\Symfony\Symfony52\Rector\MethodCall\ValidatorBuilderEnableAnnotationMappingRector;
return static function (RectorConfig $rectorConfig) : void {
    $rectorConfig->rules([
        # https://github.com/symfony/symfony/blob/5.x/UPGRADE-5.2.md#validator
        ValidatorBuilderEnableAnnotationMappingRector::class,
    ]);
};
