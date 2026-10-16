<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Tests\Support;

use AgenticGraph\Vector\Facades\Embeddings;
use AgenticGraph\Vector\ValueObjects\Document;

final class StandaloneRagCorpus
{
    /**
     * @return array{
     *     documents: list<Document>,
     *     anchors: array<string, string>,
     *     total: int,
     *     precomputed: int,
     *     content_only: int
     * }
     */
    public static function build(int $fillerCount = 114, int $precomputedCount = 24): array
    {
        $anchors = self::anchorDocuments();
        $fillers = self::fillerDocuments($fillerCount);

        $documents = [...$anchors, ...$fillers];
        $precomputedIds = [];

        foreach (array_slice($fillers, 0, $precomputedCount) as $document) {
            $precomputedIds[] = (string) $document->id;
        }

        $documents = array_map(
            static function (Document $document) use ($precomputedIds): Document {
                if (! in_array((string) $document->id, $precomputedIds, true)) {
                    return $document;
                }

                return Document::make(
                    id: $document->id,
                    content: $document->content,
                    metadata: $document->metadata,
                    vector: Embeddings::using('prism')->embed($document->content),
                );
            },
            $documents,
        );

        $anchorQueries = [
            'How do Laravel queue workers process background jobs using Redis as the queue driver?' => 'anchor-laravel-queues',
            'PostgreSQL pgvector extension cosine similarity search with HNSW vector index' => 'anchor-pgvector-hnsw',
            'Backpropagation algorithm computes gradients during neural network training' => 'anchor-ml-backprop',
            'Sourdough starter fermentation and artisan bread baking techniques' => 'anchor-cooking-sourdough',
            'Breach of contract damages and legal remedies in commercial agreements' => 'anchor-legal-breach',
            'Half marathon training plan with weekly mileage and pace progression' => 'anchor-sports-marathon',
        ];

        return [
            'documents' => $documents,
            'anchors' => $anchorQueries,
            'total' => count($documents),
            'precomputed' => $precomputedCount,
            'content_only' => count($documents) - $precomputedCount,
        ];
    }

    /**
     * @return list<Document>
     */
    private static function anchorDocuments(): array
    {
        return [
            Document::make(
                'anchor-laravel-queues',
                'Laravel queue workers process background jobs asynchronously. Configure Redis as the queue driver, '
                .'run php artisan queue:work, and dispatch jobs with ShouldQueue for reliable background processing.',
                ['category' => 'laravel', 'lang' => 'en', 'featured' => true, 'priority' => 10, 'year' => 2024],
            ),
            Document::make(
                'anchor-pgvector-hnsw',
                'The pgvector extension stores embedding vectors in PostgreSQL. Cosine similarity search uses '
                .'the <=> operator; HNSW indexes accelerate approximate nearest neighbor retrieval at scale.',
                ['category' => 'database', 'lang' => 'en', 'featured' => true, 'priority' => 9, 'year' => 2024],
            ),
            Document::make(
                'anchor-ml-backprop',
                'Backpropagation algorithm computes gradients for neural network training. Chain rule propagates '
                .'error signals backward through layers to update weights via gradient descent optimization.',
                ['category' => 'ml', 'lang' => 'en', 'featured' => true, 'priority' => 8, 'year' => 2023],
            ),
            Document::make(
                'anchor-cooking-sourdough',
                'Sourdough starter fermentation requires feeding wild yeast cultures daily. Artisan bread baking '
                .'uses long autolyse rests, stretch-and-fold techniques, and dutch oven baking for crisp crusts.',
                ['category' => 'cooking', 'lang' => 'en', 'featured' => false, 'priority' => 5, 'year' => 2022],
            ),
            Document::make(
                'anchor-legal-breach',
                'Breach of contract occurs when a party fails to perform obligations. Legal remedies include '
                .'compensatory damages, specific performance, and injunctive relief in commercial agreements.',
                ['category' => 'legal', 'lang' => 'en', 'featured' => false, 'priority' => 6, 'year' => 2021],
            ),
            Document::make(
                'anchor-sports-marathon',
                'Half marathon training plan builds weekly mileage gradually. Include tempo runs, long runs, '
                .'rest days, and pace progression toward race-day goal splits over twelve to sixteen weeks.',
                ['category' => 'sports', 'lang' => 'en', 'featured' => true, 'priority' => 7, 'year' => 2024],
            ),
        ];
    }

    /**
     * @return list<Document>
     */
    private static function fillerDocuments(int $count): array
    {
        $categories = ['laravel', 'database', 'ml', 'cooking', 'legal', 'sports'];
        $languages = ['en', 'es', 'fr'];
        $documents = [];

        for ($index = 0; $index < $count; $index++) {
            $category = $categories[$index % count($categories)];
            $lang = $languages[$index % count($languages)];

            $documents[] = Document::make(
                id: "filler-{$index}",
                content: self::fillerContent($category, $index, $lang),
                metadata: [
                    'category' => $category,
                    'lang' => $lang,
                    'featured' => $index % 4 === 0,
                    'priority' => ($index % 10) + 1,
                    'year' => 2020 + ($index % 5),
                ],
            );
        }

        return $documents;
    }

    private static function fillerContent(string $category, int $index, string $lang): string
    {
        $topic = match ($category) {
            'laravel' => 'Eloquent ORM relationships and migration patterns',
            'database' => 'SQL indexing strategies and query planner hints',
            'ml' => 'Random forest ensemble classifiers for tabular data',
            'cooking' => 'Mediterranean salad dressings with olive oil and herbs',
            'legal' => 'Intellectual property licensing for software products',
            'sports' => 'Swimming interval workouts for triathlon preparation',
        };

        return "[{$lang}] {$topic}: reference note {$index} with varied vocabulary to reduce accidental semantic overlap.";
    }
}
