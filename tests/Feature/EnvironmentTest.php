<?php

// it('has environment page', function () {
//     $response = $this->get('/environment');

//     $response->assertStatus(200);
// });
declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('runs the test suite against the dedicated PostgreSQL test database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('billing_engine_testing');
});
