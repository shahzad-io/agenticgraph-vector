<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Concerns;

use Illuminate\Contracts\Container\Container;

/**
 * The managers are scoped (new per Octane request and per queued job), but extend() is called once, in a service
 * provider's boot(). Every instance of a manager shares one driver list, held by the container's
 * `vector.extensions`, so drivers added on the first instance are on every later one.
 */
trait KeepsExtensions
{
    public function __construct(Container $container)
    {
        parent::__construct($container);

        $extensions = $container->make('vector.extensions');
        $extensions->{static::class} ??= [];
        $this->customCreators = &$extensions->{static::class};
    }
}
