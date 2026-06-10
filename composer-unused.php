<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

return static function (Configuration $config): Configuration {
    return $config
        // Used via Blade components (<flux:*>), not via PHP symbols.
        ->addNamedFilter(NamedFilter::fromString('livewire/flux'))
        // Blade compiler optimization, used via @blaze directives.
        ->addNamedFilter(NamedFilter::fromString('livewire/blaze'))
        // Starter kit scaffolding tool, used via chisel markers in source files.
        ->addNamedFilter(NamedFilter::fromString('laravel/chisel'));
};
