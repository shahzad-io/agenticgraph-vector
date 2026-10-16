<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\ValueObjects;

use AgenticGraph\Vector\Enums\StoreCapability;

final readonly class StoreCapabilities
{
    /** @var list<StoreCapability> */
    public array $capabilities;

    public function __construct(StoreCapability ...$capabilities)
    {
        $this->capabilities = array_values($capabilities);
    }

    public function supports(StoreCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
