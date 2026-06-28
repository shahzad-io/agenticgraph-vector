<?php

use AgenticGraph\Vector\VectorServiceProvider;

it('registers the service provider', function () {
    $providers = collect($this->app->getLoadedProviders())
        ->filter()
        ->keys();

    expect($providers)->toContain(VectorServiceProvider::class);
});

it('loads the vector config', function () {
    expect(config('vector'))->toBeArray();
});

it('can publish the vector config', function () {
    $this->artisan('vendor:publish', [
        '--provider' => VectorServiceProvider::class,
        '--tag' => 'vector-config',
    ])->assertSuccessful();
});
