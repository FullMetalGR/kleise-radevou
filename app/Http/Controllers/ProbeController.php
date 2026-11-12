<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Db\TransactionRunner;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;

/**
 * Answers which schema a transaction for the resolved tenant runs on. The runner asserts
 * current_schema() against the tenant before the closure runs, so an answer other than the
 * tenant's schema cannot come back without a 500; the route exists so that a test, or an
 * operator checking a deployment, can watch that assertion hold request after request on one
 * connection.
 */
final class ProbeController extends Controller
{
    public function __invoke(CurrentTenant $current, TransactionRunner $runner): JsonResponse
    {
        $tenant = $current->get();

        $schema = $runner->run($tenant, fn (Connection $db): mixed => $db->scalar('select current_schema()'));

        return new JsonResponse(['slug' => $tenant->slug, 'schema' => $schema]);
    }
}
