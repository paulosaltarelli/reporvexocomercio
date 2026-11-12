<?php

namespace Tests\Unit;

use App\Fiscal\DocumentState;
use App\Fiscal\Environment;
use App\Fiscal\ProductionGate;
use App\Fiscal\ProviderResult;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FiscalSafetyTest extends TestCase
{
    public function test_uncertain_submission_cannot_be_requeued_or_renumbered(): void
    {
        $this->assertFalse(DocumentState::Uncertain->canTransitionTo(DocumentState::Pending));
        $this->assertFalse(DocumentState::Uncertain->canTransitionTo(DocumentState::Sent));
        $this->assertTrue(DocumentState::Uncertain->canTransitionTo(DocumentState::Authorized));
        $this->assertTrue(DocumentState::Uncertain->canTransitionTo(DocumentState::Rejected));
    }

    public function test_http_success_without_fiscal_evidence_is_not_authorization(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProviderResult(DocumentState::Authorized);
    }

    public function test_cancelled_document_cannot_be_authorized_by_late_response(): void
    {
        $this->assertFalse(DocumentState::Cancelled->canTransitionTo(DocumentState::Authorized));
        $this->assertTrue(DocumentState::Cancelled->canTransitionTo(DocumentState::Cancelled));
    }

    public function test_demonstration_blocks_production_even_with_approval(): void
    {
        $this->expectException(DomainException::class);
        (new ProductionGate)->assertAllowed(Environment::Production, true, true);
    }

    public function test_real_company_still_requires_explicit_production_approval(): void
    {
        $this->expectException(DomainException::class);
        (new ProductionGate)->assertAllowed(Environment::Production, false, false);
    }

    public function test_homologation_does_not_require_production_approval(): void
    {
        (new ProductionGate)->assertAllowed(Environment::Homologation, true, false);
        $this->addToAssertionCount(1);
    }
}
