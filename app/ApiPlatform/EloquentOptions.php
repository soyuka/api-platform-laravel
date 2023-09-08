<?php

namespace App\ApiPlatform;

use ApiPlatform\State\OptionsInterface;

class EloquentOptions implements OptionsInterface {
    public function __construct(public string $model) {}
}
