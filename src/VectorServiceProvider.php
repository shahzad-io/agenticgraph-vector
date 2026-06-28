<?php

declare(strict_types=1);

namespace AgenticGraph\Vector;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class VectorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vector')
            ->hasConfigFile();
    }
}
