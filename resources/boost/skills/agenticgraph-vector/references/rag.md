# RAG examples

Docs: `/docs/vector-guide-building-rag`, `/docs/vector-guide-tracing-integration`

The package retrieves. Prompting, generation and citations are your code.

## In a controller

```php
use AgenticGraph\Vector\Facades\Vector;
use Prism\Prism\Facades\Prism;

public function ask(string $question): string
{
    $results = Vector::store('pgvector')
        ->collection('knowledge_base')
        ->where('lang', 'en')
        ->similarTo($question)
        ->take(5)
        ->minScore(0.7)
        ->get();

    if ($results->count() === 0) {
        return 'I could not find anything relevant.';
    }

    // Provider and model come from your config (example keys). The visitor's question is the user
    // message only; it is never concatenated into the instructions.
    $response = Prism::text()
        ->using(config('rag.provider'), config('rag.model'))
        ->withSystemPrompt(
            "Answer using ONLY the context below. If it is not enough, say so.\n\nContext:\n".
            $results->toContext("\n\n---\n\n")
        )
        ->withPrompt($question)
        ->asText();

    return $response->text;
}
```

The `VectorOperationCompleted` event fires. No span is recorded: the search did not run inside a graph node.

## Join hits back to your database

```php
$results = Vector::store('pgvector')->collection('products')->similarTo($query)->take(10)->get();

$products = Product::query()
    ->whereIn('id', $results->pluck('id'))
    ->get()
    ->sortBy(fn (Product $p) => array_search($p->id, $results->pluck('id')));
```

## In an AgenticGraph workflow

Requires `agenticgraph/agenticgraph`. With its tracing on, the search in `RetrieveNode` becomes a `vector.search` span under that node. The retrieve node stores `toContextItems()` (plain arrays, safe in a checkpoint); the generate node hands them to `context()`, which joins them into `{context}` and records them as `run()->sources()` and on the llm span.

```php
namespace App\Nodes;

use AgenticGraph\Vector\Facades\Vector;
use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\AbstractNode;

final class RetrieveNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $results = Vector::store('pgvector')
            ->collection('knowledge_base')
            ->similarTo((string) $state->get('query', ''))
            ->take((int) $state->get('top_k', 3))
            ->minScore(0.7)
            ->get();

        return $state
            ->set('documents', $results->toContextItems())
            ->set('retrieved_ids', $results->pluck('id'))
            ->set('retrieved_scores', $results->pluck('score'));
    }
}
```

```php
namespace App\Nodes;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\PrismNode;

final class GenerateNode extends PrismNode
{
    public function execute(StateContract $state): StateContract
    {
        $answer = $this->llm()->prompt(
            "Answer using ONLY the context below.\n\nQuestion:\n{query}\n\nContext:\n{context}"
        )->vars(['query' => (string) $state->get('query', '')])->context($state->get('documents', []), separator: "\n\n---\n\n")->text();

        return $state->set('answer', $answer);
    }
}
```

```php
namespace App\Nodes;

use Shahzad\AgenticGraph\Contracts\StateContract;
use Shahzad\AgenticGraph\Nodes\AbstractNode;

final class CiteSourcesNode extends AbstractNode
{
    public function execute(StateContract $state): StateContract
    {
        $scores = $state->get('retrieved_scores', []);

        $citations = collect($state->get('retrieved_ids', []))
            ->map(fn ($id, $i) => sprintf('- [%s] score=%s', $id, number_format($scores[$i] ?? 0, 4)))
            ->implode("\n");

        return $state->set('citations', $citations);
    }
}
```

The graph is a class with a static `build()`, registered in config:

```php
namespace App\Graphs;

use App\Nodes\CiteSourcesNode;
use App\Nodes\GenerateNode;
use App\Nodes\RetrieveNode;
use Shahzad\AgenticGraph\Graph\Graph;
use Shahzad\AgenticGraph\Graph\GraphBuilder;

final class RagGraph
{
    public static function build(): Graph
    {
        return GraphBuilder::create('rag')
            ->addNode('retrieve', RetrieveNode::class)
            ->addNode('generate', GenerateNode::class)
            ->addNode('cite', CiteSourcesNode::class)
            ->addEdge('retrieve', 'generate')
            ->addEdge('generate', 'cite')
            ->setEntryPoint('retrieve')
            ->addExitPoint('cite')
            ->build();
    }
}

// config/agenticgraph.php: 'graphs' => ['rag' => \App\Graphs\RagGraph::class],
```

```php
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\State\State;

$final = AgenticGraph::run('rag', new State(['query' => $query, 'top_k' => 5]))->getFinalState();

$final->get('answer');
$final->get('citations');
```
