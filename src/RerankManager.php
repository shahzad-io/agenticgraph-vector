<?php

declare(strict_types=1);

namespace AgenticGraph\Vector;

use AgenticGraph\Vector\Contracts\RerankerContract;
use AgenticGraph\Vector\Exceptions\VectorRerankException;
use AgenticGraph\Vector\Rerankers\ApiReranker;
use AgenticGraph\Vector\Rerankers\DeterministicReranker;
use AgenticGraph\Vector\Rerankers\InstrumentedReranker;
use AgenticGraph\Vector\Rerankers\NullReranker;
use AgenticGraph\Vector\Rerankers\PrismReranker;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves named rerankers from `vector.rerank.providers`. Each entry names its `driver` (cohere, voyage, prism,
 * null, deterministic, or one registered with extend()). There is no default reranker. Every reranker comes
 * back wrapped in an InstrumentedReranker (events, fail-open, the timeout from `vector.rerank.timeout`).
 */
class RerankManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $default = (string) $this->config->get('vector.rerank.default', '');

        if ($default === '') {
            throw new VectorRerankException('No reranker named and no default configured. Pass rerank(using: \'name\') or set VECTOR_RERANK_PROVIDER (vector.rerank.default) to an entry of vector.rerank.providers.');
        }

        return $default;
    }

    public function reranker(?string $name = null): InstrumentedReranker
    {
        return $this->driver($name);
    }

    /**
     * @param  string  $driver  the reranker name
     */
    protected function createDriver($driver): InstrumentedReranker
    {
        $name = $driver;
        /** @var array<string, mixed>|null $config */
        $config = $this->config->get("vector.rerank.providers.{$name}");

        if ($config === null && ! isset($this->customCreators[$name])) {
            throw new InvalidArgumentException("Reranker [{$name}] is not defined in vector.rerank.providers.");
        }

        $config = ($config ?? []) + ['timeout' => (int) $this->config->get('vector.rerank.timeout', 5)];
        $driverName = (string) ($config['driver'] ?? $name);

        $reranker = match (true) {
            isset($this->customCreators[$driverName]) => $this->customCreators[$driverName]($this->container, $config, $name),
            $driverName === 'cohere', $driverName === 'voyage' => new ApiReranker($driverName, $name, $config),
            $driverName === 'prism' => new PrismReranker($name, $config),
            $driverName === 'null' => new NullReranker,
            $driverName === 'deterministic' => new DeterministicReranker,
            default => throw new InvalidArgumentException("Reranker driver [{$driverName}] for reranker [{$name}] is not supported."),
        };

        if (! $reranker instanceof RerankerContract) {
            throw new InvalidArgumentException("Reranker [{$name}] does not implement RerankerContract.");
        }

        return new InstrumentedReranker($name, (string) ($config['model'] ?? $driverName), $reranker, (bool) $this->config->get('vector.rerank.fail_open', true));
    }
}
