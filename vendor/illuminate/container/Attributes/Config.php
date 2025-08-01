<?php

namespace RectorPrefix202507\Illuminate\Container\Attributes;

use Attribute;
use RectorPrefix202507\Illuminate\Contracts\Container\Container;
use RectorPrefix202507\Illuminate\Contracts\Container\ContextualAttribute;
#[Attribute(Attribute::TARGET_PARAMETER)]
class Config implements ContextualAttribute
{
    public string $key;
    /**
     * @var mixed
     */
    public $default = null;
    /**
     * Create a new class instance.
     * @param mixed $default
     */
    public function __construct(string $key, $default = null)
    {
        $this->key = $key;
        $this->default = $default;
    }
    /**
     * Resolve the configuration value.
     *
     * @param  self  $attribute
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return mixed
     */
    public static function resolve(self $attribute, Container $container)
    {
        return $container->make('config')->get($attribute->key, $attribute->default);
    }
}
