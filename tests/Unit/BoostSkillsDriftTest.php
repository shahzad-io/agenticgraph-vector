<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Manager;

/*
 * The Boost skill in resources/boost names package classes, methods, enum cases and env keys.
 * This test fails when one of them no longer exists. It checks:
 * - every AgenticGraph\Vector\... class, and every short name a skill imports or uses;
 * - Name::method(), Name->method() and Name::CASE, with facades resolved to their target;
 * - $var->method() and chains, typed from new X, return types, or the common names below;
 * - every VECTOR_* key and every key in a dotenv block, against config/vector.php.
 * Interface methods are also looked up on the src classes that implement the interface.
 * - every Shahzad\AgenticGraph\... class and method the references use, from the sibling agenticgraph repo
 *   (AGENTICGRAPH_CORE_PATH, default ../agenticgraph), loaded through core's own autoloader. Core's suite checks
 *   its own skills; this one checks the vector skill's use of core.
 * Receivers it cannot type are skipped. Methods defined in the same code block are allowed.
 */

const DRIFT_VARS = [
    'results' => 'AgenticGraph\Vector\ValueObjects\SearchResult',
    'provider' => 'AgenticGraph\Vector\Contracts\EmbeddingProviderContract',
    'capabilities' => 'AgenticGraph\Vector\ValueObjects\StoreCapabilities',
    'state' => 'Shahzad\AgenticGraph\Contracts\StateContract',
];

const DRIFT_PREFIX = 'AgenticGraph\\Vector\\';

const DRIFT_CORE_PREFIX = 'Shahzad\\AgenticGraph\\';

function driftRoot(): string
{
    return dirname(__DIR__, 2);
}

function driftCoreRoot(): string
{
    return getenv('AGENTICGRAPH_CORE_PATH') ?: dirname(driftRoot()).'/agenticgraph';
}

/**
 * Core is a sibling repo, not a Composer dependency here. Its classes load through an autoloader appended after this
 * package's, so the test stubs under tests/Stubs (a stub HttpClientInstrumentation, a narrower TracerExporter) keep
 * priority. Core's own vendor/autoload.php prepends itself and would shadow them for every test that runs later.
 */
function driftLoadCore(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $src = driftCoreRoot().'/src/';
    if (! is_dir($src)) {
        throw new RuntimeException("Core source missing at {$src}: set AGENTICGRAPH_CORE_PATH.");
    }
    spl_autoload_register(static function (string $class) use ($src): void {
        $prefix = 'Shahzad\\AgenticGraph\\';
        if (str_starts_with($class, $prefix) && is_file($file = $src.str_replace('\\', '/', substr($class, strlen($prefix))).'.php')) {
            require_once $file;
        }
    });
}

/** @return array<string, string> skill-relative path => contents */
function driftFiles(): array
{
    $files = [];
    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(driftRoot().'/resources/boost', FilesystemIterator::SKIP_DOTS));
    foreach ($dir as $file) {
        if (in_array($file->getExtension(), ['md', 'php'], true)) {
            $files[substr($file->getPathname(), strlen(driftRoot()) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($files);

    return $files;
}

/** @return array<string, list<string>> short name => FQCNs, from src */
function driftClassIndex(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }
    driftLoadCore();
    $index = [];
    foreach ([driftRoot(), driftCoreRoot()] as $base) {
        $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/src', FilesystemIterator::SKIP_DOTS));
        foreach ($dir as $file) {
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+([\w\\\\]+);/m', $src, $ns) && preg_match('/^(?:final |abstract |readonly )*(?:class|interface|enum|trait)\s+(\w+)/m', $src, $cls)) {
                $index[$cls[1]][] = $ns[1].'\\'.$cls[1];
            }
        }
    }

    return $index;
}

function driftTypeExists(string $fqcn): bool
{
    driftLoadCore();

    return class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn) || trait_exists($fqcn);
}

/** A facade stands for its target class. @return list<class-string> */
function driftTargets(string $fqcn): array
{
    if (is_subclass_of($fqcn, Facade::class)) {
        $accessor = (new ReflectionMethod($fqcn, 'getFacadeAccessor'))->invoke(null);

        return is_string($accessor) && driftTypeExists($accessor) ? [$accessor, $fqcn] : [$fqcn];
    }

    return driftTypeExists($fqcn) ? [$fqcn] : [];
}

/** Package classes only; vendor and app classes resolve to nothing. @param array<string, string> $uses @return list<class-string> */
function driftResolve(string $name, array $uses): array
{
    $name = ltrim(str_replace('\\\\', '\\', $name), '\\');
    $fqcns = match (true) {
        str_contains($name, '\\') => [$name],
        isset($uses[$name]) => [$uses[$name]],
        default => driftClassIndex()[$name] ?? [],
    };
    $fqcns = array_filter($fqcns, fn (string $c): bool => str_starts_with($c, DRIFT_PREFIX) || str_starts_with($c, DRIFT_CORE_PREFIX));

    return array_merge(...array_values(array_map('driftTargets', $fqcns)));
}

/** src classes that implement an interface. @return list<class-string> */
function driftImplementors(string $interface): array
{
    if (! interface_exists($interface)) {
        return [];
    }

    return array_values(array_filter(
        array_merge(...array_values(driftClassIndex())),
        fn (string $c): bool => class_exists($c) && is_subclass_of($c, $interface),
    ));
}

/** The method's owner: the class itself, or for an interface an implementing src class. */
function driftOwner(string $class, string $method): ?string
{
    if (method_exists($class, $method)) {
        return $class;
    }
    foreach (driftImplementors($class) as $impl) {
        if (method_exists($impl, $method)) {
            return $impl;
        }
    }

    return null;
}

/** Forwards unknown calls through __call. A Laravel Manager forwards to any driver, so it does not count. */
function driftForwards(string $class): bool
{
    return method_exists($class, '__call') && ! is_subclass_of($class, Manager::class);
}

/** A class has the method, or forwards unknown calls. */
function driftHasMethod(string $class, string $method): bool
{
    return driftOwner($class, $method) !== null || driftForwards($class);
}

/** @param list<class-string> $classes @return list<class-string> */
function driftReturnTypes(array $classes, string $method): array
{
    $types = [];
    foreach ($classes as $class) {
        $class = driftOwner($class, $method) ?? $class;
        if (! method_exists($class, $method)) {
            // A call forwarded through __call is taken as fluent (McpRequest returns $this)
            if (driftForwards($class)) {
                $types[] = $class;
            }

            continue;
        }
        $type = (new ReflectionMethod($class, $method))->getReturnType();
        $named = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($named as $t) {
            if ($t instanceof ReflectionNamedType && ! $t->isBuiltin()) {
                $types[] = in_array($t->getName(), ['self', 'static'], true) ? $class : $t->getName();
            }
        }
    }

    return array_values(array_filter(array_unique($types), 'driftTypeExists'));
}

/**
 * Type of the expression that ends just before $pos (the receiver of ->method).
 *
 * @param  array<string, list<class-string>>  $vars
 * @param  array<string, string>  $uses
 * @return list<class-string>
 */
function driftReceiver(string $code, int $pos, array $vars, array $uses): array
{
    $left = rtrim(substr($code, 0, $pos));
    if (preg_match('/\$(\w+)$/', $left, $m)) {
        return $vars[$m[1]] ?? [];
    }
    if (! str_ends_with($left, ')')) {
        return [];
    }
    $depth = 0;
    for ($i = strlen($left) - 1; $i >= 0; $i--) {
        $depth += $left[$i] === ')' ? 1 : ($left[$i] === '(' ? -1 : 0);
        if ($depth === 0) {
            break;
        }
    }
    $head = substr($left, 0, max($i, 0));
    if (preg_match('/new\s+([\w\\\\]+)\s*$/', $head, $m)) {
        return driftResolve($m[1], $uses);
    }
    if (preg_match('/([\w\\\\]+)::(\w+)\s*$/', $head, $m)) {
        return driftReturnTypes(driftResolve($m[1], $uses), $m[2]);
    }
    if (preg_match('/->(\w+)\s*$/', $head, $m)) {
        $receiver = driftReceiver($head, strlen($head) - strlen($m[0]), $vars, $uses);

        return driftReturnTypes($receiver, $m[1]);
    }

    return [];
}

/** @return list<string> problems in one file */
function driftCheck(string $path, string $text): array
{
    $problems = [];
    $config = (string) file_get_contents(driftRoot().'/config/vector.php');
    $vendorConfig = implode("\n", array_map('file_get_contents', glob(driftRoot().'/vendor/prism-php/prism/config/*.php') ?: []));
    // Env names the skill itself defines in an app config example (config/database.php) are not package config.
    preg_match_all("/env\\('([A-Z0-9_]+)'/", $text, $ownEnv);

    preg_match_all('/^use\s+([\w\\\\]+?)(?:\s+as\s+(\w+))?;/m', $text, $useMatches, PREG_SET_ORDER);
    $uses = [];
    foreach ($useMatches as $u) {
        $uses[$u[2] ?? substr(strrchr('\\'.$u[1], '\\'), 1)] = $u[1];
    }

    preg_match_all('/(?:AgenticGraph\\\\{1,2}Vector|Shahzad\\\\{1,2}AgenticGraph)(?:\\\\{1,2}\w+)+/', $text, $fq);
    foreach (array_unique($fq[0]) as $name) {
        $namespace = str_replace('\\\\', '\\', $name).'\\';
        $isNamespace = array_filter(array_merge(...array_values(driftClassIndex())), fn (string $c): bool => str_starts_with($c, $namespace)) !== [];
        if (driftResolve($name, []) === [] && ! $isNamespace) {
            $problems[] = "{$path}: class {$name} does not exist";
        }
    }

    preg_match_all('/(?<![\w\\\\$>])([A-Z]\w+)(::|->)(\w+)(\()?/', $text, $refs, PREG_SET_ORDER);
    foreach ($refs as $ref) {
        [, $class, $op, $member] = $ref;
        $call = $ref[4] ?? '';
        $targets = driftResolve($class, $uses);
        if ($targets === [] || $member === 'class') {
            continue;
        }
        $isCase = $op === '::' && $call === '' && preg_match('/^[A-Z][A-Z0-9_]*$/', $member);
        $found = false;
        foreach ($targets as $t) {
            $found = $found || ($isCase ? defined("{$t}::{$member}") : driftHasMethod($t, $member));
        }
        if (! $found && ($isCase || $call !== '' || str_contains($text, "{$class}{$op}{$member}()`"))) {
            $problems[] = "{$path}: {$class}{$op}{$member} does not exist";
        }
    }

    // Code blocks are typed one at a time; inline code shares the common names.
    preg_match_all('/```php[^\n]*\n(.*?)```/s', $text, $blocks);
    $inline = preg_replace('/```.*?```/s', '', $text);
    preg_match_all('/`([^`\n]*->[^`\n]*)`/', (string) $inline, $spans);
    foreach ([...$blocks[1], ...$spans[1]] as $code) {
        $vars = array_map(fn (string $c): array => [$c], DRIFT_VARS);
        if (preg_match('/extends\s+([\w\\\\]+)/', $code, $ext) && ($parent = driftResolve($ext[1], $uses)) !== []) {
            $vars['this'] = $parent;
        }
        preg_match_all('/\$(\w+)\s*=\s*(.+?);/s', $code, $assigns, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($assigns as $a) {
            $expr = rtrim($a[2][0]);
            $types = driftReceiver($expr.'->x', strlen($expr), $vars, $uses);
            if ($types !== []) {
                $vars[$a[1][0]] = $types;
            } else {
                unset($vars[$a[1][0]]);
            }
        }
        preg_match_all('/function\s+(\w+)/', $code, $own);
        preg_match_all('/->(\w+)\(/', $code, $calls, PREG_OFFSET_CAPTURE);
        foreach ($calls[1] as [$method, $offset]) {
            $receiver = driftReceiver($code, $offset - 2, $vars, $uses);
            if ($receiver === [] || in_array($method, $own[1], true)) {
                continue;
            }
            $exists = array_filter($receiver, fn (string $c): bool => driftHasMethod($c, $method));
            if ($exists === []) {
                $problems[] = "{$path}: ".implode('|', $receiver)."::{$method}() does not exist";
            }
        }
    }

    preg_match_all('/\bVECTOR_[A-Z0-9_]+\b/', $text, $env);
    preg_match_all('/```(?:dotenv|env)[^\n]*\n(.*?)```/s', $text, $envBlocks);
    preg_match_all('/^#?\s*([A-Z][A-Z0-9_]+)=/m', implode("\n", $envBlocks[1]), $blockKeys);
    foreach (array_unique([...$env[0], ...$blockKeys[1]]) as $key) {
        if (! str_contains($config, "env('{$key}'") && ! str_contains($vendorConfig, "env('{$key}'") && ! in_array($key, $ownEnv[1], true)) {
            $problems[] = "{$path}: env {$key} is not in config/vector.php";
        }
    }

    preg_match_all('/\]\((references\/[\w.-]+)\)/', $text, $links);
    foreach ($links[1] as $link) {
        if (! is_file(driftRoot().'/'.dirname($path).'/'.$link)) {
            $problems[] = "{$path}: missing {$link}";
        }
    }

    return array_values(array_unique($problems));
}

it('finds the skills', function () {
    $skills = array_filter(array_keys(driftFiles()), fn (string $p): bool => str_ends_with($p, '/SKILL.md'));

    expect(array_values($skills))->toBe([
        'resources/boost/skills/agenticgraph-vector/SKILL.md',
    ]);
});

it('names only classes, methods, cases and env keys that exist', function () {
    $problems = [];
    foreach (driftFiles() as $path => $text) {
        $problems = [...$problems, ...driftCheck($path, $text)];
    }

    expect($problems)->toBe([]);
});

it('gives every skill a name, a description and the docs commit it condenses', function () {
    foreach (driftFiles() as $path => $text) {
        if (! str_ends_with($path, '/SKILL.md')) {
            continue;
        }
        expect(preg_match('/^---\nname: ([\w-]+)\ndescription: .+\n---\n/', $text, $m))->toBe(1, $path)
            ->and($m[1])->toBe(basename(dirname($path)))
            ->and($text)->toMatch('/^docs-sha: [0-9a-f]{7,40}$/m');
    }
});

it('finds the core source that the core names are checked against', function () {
    expect(is_dir(driftCoreRoot().'/src'))->toBeTrue();
    expect(driftTypeExists('Shahzad\AgenticGraph\Contracts\StateContract'))->toBeTrue();
});

it('catches a renamed core method', function () {
    $text = "```php\n\$state->getRenamed();\n```\nuse Shahzad\\AgenticGraph\\Contracts\\StateContract;";

    expect(driftCheck('x.md', $text))->toContain('x.md: Shahzad\AgenticGraph\Contracts\StateContract::getRenamed() does not exist');
});

it('catches a renamed method', function () {
    $text = "```php\n\$results = Vector::store('pgvector')->collection('a')->similarTo('q')->get();\n\$results->toContextRenamed();\n```";

    expect(driftCheck('x.md', $text))->toContain('x.md: AgenticGraph\Vector\ValueObjects\SearchResult::toContextRenamed() does not exist');
});
