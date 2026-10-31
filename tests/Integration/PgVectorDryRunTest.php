<?php

use AgenticGraph\Vector\Tests\Support\PgvectorTestHelper;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    if (! PgvectorTestHelper::isAvailable()) {
        $this->markTestSkipped(PgvectorTestHelper::skipReason());
    }
});

it('leaves a fresh schema untouched on a dry run, and needs no CREATE rights', function (bool $restricted) {
    $schema = PgvectorTestHelper::schema().'_dry';
    $role = "{$schema}_reader";
    $admin = PgvectorTestHelper::pdo();
    $admin->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
    $admin->exec("CREATE SCHEMA {$schema}");
    $extensions = $admin->query('SELECT extname FROM pg_extension ORDER BY extname')->fetchAll(PDO::FETCH_COLUMN);

    config()->set('database.connections.pgvector.search_path', "{$schema},public");
    DB::purge('pgvector');

    if ($restricted) {
        $admin->exec("DROP ROLE IF EXISTS {$role}");
        $admin->exec("CREATE ROLE {$role} NOLOGIN");
        $admin->exec("GRANT USAGE ON SCHEMA {$schema} TO {$role}");
        DB::connection('pgvector')->statement("SET ROLE {$role}");
    }

    try {
        $this->artisan('vector:migrate', ['alias' => 'docs', '--dry-run' => true])
            ->expectsOutputToContain('Collection [docs] was not found.')
            ->assertFailed()
            ->run();

        $tables = $admin->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
        $tables->execute([$schema]);

        expect((int) $tables->fetchColumn())->toBe(0)
            ->and($admin->query('SELECT extname FROM pg_extension ORDER BY extname')->fetchAll(PDO::FETCH_COLUMN))->toBe($extensions);
    } finally {
        DB::purge('pgvector');
        $admin->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        $admin->exec("DROP ROLE IF EXISTS {$role}");
    }
})->with(['owner' => false, 'no CREATE rights' => true]);
