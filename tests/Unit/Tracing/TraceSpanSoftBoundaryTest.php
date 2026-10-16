<?php

declare(strict_types=1);

/**
 * Architectural invariant: Vector production code must never hard-import agenticgraph.
 *
 * @see src/Tracing/TraceSpan.php: resolves Tracer via TRACER_CLASS string + app()->make() only.
 */
test('production src must not contain hard agenticgraph imports or ::class references', function (): void {
    $srcPath = realpath(__DIR__.'/../../../src');
    expect($srcPath)->not->toBeFalse();

    $violations = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcPath, FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        $relative = str_replace($srcPath.DIRECTORY_SEPARATOR, '', $file->getPathname());

        if (preg_match('/^\s*use\s+Shahzad\\\\AgenticGraph\\\\/m', $content) === 1) {
            $violations[] = "{$relative}: hard `use Shahzad\\AgenticGraph\\...` import";
        }

        if (preg_match('/\bShahzad\\\\AgenticGraph\\\\Tracing\\\\Tracer::class\b/', $content) === 1) {
            $violations[] = "{$relative}: `Shahzad\\AgenticGraph\\Tracing\\Tracer::class` reference";
        }

        if (preg_match('/\bShahzad\\\\AgenticGraph\\\\Facades\\\\Trace::class\b/', $content) === 1) {
            $violations[] = "{$relative}: `Shahzad\\AgenticGraph\\Facades\\Trace::class` reference";
        }

        if ($relative === 'Tracing'.DIRECTORY_SEPARATOR.'TraceSpan.php') {
            $violations = array_merge($violations, traceSpanSoftBoundaryViolations($content, $relative));
        }
    }

    $message = <<<'MSG'
TraceSpan must resolve agenticgraph's Tracer via the string constant + app()->make() only.
A hard `use` import or `::class` reference (likely added by Pint or an IDE) breaks the standalone boundary: revert to string-based resolution.
MSG;

    if ($violations !== []) {
        $message .= "\n\nViolations:\n- ".implode("\n- ", $violations);
    }

    expect($violations)->toBeEmpty($message);
});

/**
 * @return list<string>
 */
function traceSpanSoftBoundaryViolations(string $content, string $relative): array
{
    $violations = [];

    if (! str_contains($content, "private const TRACER_CLASS = 'Shahzad\\AgenticGraph\\Tracing\\Tracer'")) {
        $violations[] = "{$relative}: missing TRACER_CLASS string constant";
    }

    if (! str_contains($content, 'app()->make(self::TRACER_CLASS)')) {
        $violations[] = "{$relative}: must resolve Tracer via app()->make(self::TRACER_CLASS)";
    }

    $codeWithoutComments = preg_replace('#/\*.*?\*/#s', '', $content) ?? $content;
    $codeWithoutComments = preg_replace('#//[^\n]*#', '', $codeWithoutComments) ?? $codeWithoutComments;

    if (preg_match('/\bTracer::class\b/', $codeWithoutComments) === 1) {
        $violations[] = "{$relative}: bare `Tracer::class` reference";
    }

    if (preg_match('/@var\s+.*\\\\Tracer/', $codeWithoutComments) === 1) {
        $violations[] = "{$relative}: PHPDoc `@var` referencing Tracer type";
    }

    $codeWithoutStrings = preg_replace("/'(?:\\\\.|[^'\\\\])*'/", "''", $codeWithoutComments) ?? $codeWithoutComments;
    $codeWithoutStrings = preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '""', $codeWithoutStrings) ?? $codeWithoutStrings;

    if (str_contains($codeWithoutStrings, 'Shahzad\\AgenticGraph')) {
        $violations[] = "{$relative}: Shahzad\\AgenticGraph symbol outside string literal";
    }

    return $violations;
}
