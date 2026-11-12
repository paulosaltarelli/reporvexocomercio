<?php

namespace App\Fiscal;

/**
 * The adapter is bound to ONE server-resolved issuer/environment and secret store.
 * No method may allocate a new reference or number on retry. An ambiguous result
 * must be reconciled with consult(), never retried as a new issue().
 * All external calls happen outside commerce/database transactions.
 */
interface FiscalProvider
{
    public function issue(string $reference, #[\SensitiveParameter] array $frozenPayload): ProviderResult;

    public function consult(string $reference): ProviderResult;

    public function cancel(string $reference, string $reason, string $operationId): ProviderResult;

    public function invalidate(int $series, int $first, int $last, int $year, string $reason, string $operationId): ProviderResult;

    /** Authorized XML including protocol; never an unauthenticated remote URL. */
    public function xml(string $reference): string;

    /** Official representation derived from authorized XML, not the internal receipt. */
    public function danfe(string $reference): string;
}
