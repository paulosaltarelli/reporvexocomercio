<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Seller;
use App\Models\SellerAssignment as AssignmentLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SellerAssignment
{
    public function recordInitial(Document $document, Seller $seller, int $actorId): void
    {
        DB::connection('tenant')->transaction(function () use ($document, $seller, $actorId) {
            $document = Document::lockForUpdate()->findOrFail($document->id);
            if ((int) $document->salesperson_id !== $seller->id) throw new \LogicException('O vendedor inicial não corresponde ao documento.');
            if ($document->assignments()->where('source', 'creation')->exists()) return;
            $actor = $this->actor($actorId);
            $eligible = app(CommercialRules::class)->seller($actorId, null, $seller->id);
            $this->log($document, $actor, null, null, $eligible, 'Vendedor vinculado na criação do documento.', 'creation');
        }, 3);
    }

    public function assign(int $documentId, int $actorId, int $salespersonId, ?string $reason = null): Document
    {
        return $this->assignInternal($documentId, $actorId, $salespersonId, $reason, 'assignment');
    }

    /** Resolve the database owner; the executor is not allowed to steal document credit. */
    public function forInvoice(int $documentId, int $actorId, array $payment): Seller
    {
        return DB::connection('tenant')->transaction(function () use ($documentId, $actorId, $payment) {
            $actor = $this->actor($actorId);
            $document = Document::lockForUpdate()->findOrFail($documentId);
            $approved = ($document->type === 'quote' && $document->status === 'approved')
                || ($document->type === 'work_order' && $document->status === 'completed');
            if ($document->sale_id || ! $approved) {
                throw ValidationException::withMessages(['salesperson_id' => 'O documento não está disponível para faturamento.']);
            }
            if (! $document->salesperson_id) {
                if ($actor->role !== 'admin') {
                    throw ValidationException::withMessages(['salesperson_id' => 'Um administrador precisa vincular o vendedor deste documento legado antes de faturar.']);
                }
                if (! isset($payment['salesperson_id']) && ! isset($payment['seller_id'])) {
                    throw ValidationException::withMessages(['salesperson_id' => 'Selecione explicitamente o vendedor do documento legado antes de faturar.']);
                }
                $seller = app(CommercialRules::class)->seller($actorId,
                    isset($payment['seller_id']) ? (int) $payment['seller_id'] : null,
                    isset($payment['salesperson_id']) ? (int) $payment['salesperson_id'] : null);
                $this->assignInternal($document->id, $actorId, $seller->id,
                    $payment['assignment_reason'] ?? 'Vinculação explícita de vendedor antes do faturamento.', 'legacy_invoice');
                return $seller;
            }
            $seller = Seller::whereKey($document->salesperson_id)->sharedLock()->firstOrFail();
            if (! $seller->active) {
                throw ValidationException::withMessages(['salesperson_id' => 'O vendedor do documento está inativo. Um administrador deve reatribuir o documento com justificativa antes de faturar.']);
            }
            if ((isset($payment['salesperson_id']) && (int) $payment['salesperson_id'] !== $seller->id)
                || (isset($payment['seller_id']) && ($seller->user_id === null || (int) $payment['seller_id'] !== (int) $seller->user_id))) {
                throw ValidationException::withMessages(['salesperson_id' => 'O vendedor já está vinculado ao documento. Solicite uma reatribuição administrativa antes de mudar o crédito.']);
            }

            return $seller;
        }, 3);
    }

    private function assignInternal(int $documentId, int $actorId, int $salespersonId, ?string $reason, string $source): Document
    {
        return DB::connection('tenant')->transaction(function () use ($documentId, $actorId, $salespersonId, $reason, $source) {
            $actor = $this->actor($actorId);
            if ($actor->role !== 'admin') throw ValidationException::withMessages(['salesperson_id' => 'Somente administradores podem reatribuir vendedores.']);
            $document = Document::lockForUpdate()->findOrFail($documentId);
            if ((int) $document->salesperson_id === $salespersonId) return $document;
            if ($document->sale_id || ! in_array($document->status, ['draft', 'approved', 'in_progress', 'completed'], true)) {
                throw ValidationException::withMessages(['salesperson_id' => 'Não é possível reatribuir um documento faturado ou cancelado.']);
            }
            $seller = Seller::whereKey($salespersonId)->sharedLock()->firstOrFail();
            if (! $seller->active) throw ValidationException::withMessages(['salesperson_id' => 'Selecione um vendedor ativo para a atribuição.']);
            $reason = $reason === null ? null : trim($reason);
            if ($document->salesperson_id && ($reason === null || mb_strlen($reason) < 3)) {
                throw ValidationException::withMessages(['reason' => 'Informe uma justificativa de pelo menos três caracteres para a troca de vendedor.']);
            }
            $reason ??= 'Vinculação inicial de vendedor ao documento.';
            if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
                throw ValidationException::withMessages(['reason' => 'A justificativa deve ter entre três e mil caracteres.']);
            }
            $previousId = $document->salesperson_id ? (int) $document->salesperson_id : null;
            $previousName = $document->seller_name;
            $document->update(['salesperson_id' => $seller->id, 'seller_name' => $seller->name]);
            $this->log($document, $actor, $previousId, $previousName, $seller, $reason, $source);

            return $document;
        }, 3);
    }

    private function actor(int $id): User
    {
        $actor = User::where('active', true)->sharedLock()->find($id);
        if (! $actor) throw ValidationException::withMessages(['salesperson_id' => 'O usuário responsável precisa estar ativo.']);

        return $actor;
    }

    private function log(Document $document, User $actor, ?int $previousId, ?string $previousName, Seller $seller, string $reason, string $source): void
    {
        AssignmentLog::create(['document_id' => $document->id, 'previous_seller_id' => $previousId,
            'new_seller_id' => $seller->id, 'previous_seller_name' => $previousName, 'new_seller_name' => $seller->name,
            'actor_id' => $actor->id, 'actor_name' => $actor->name, 'reason' => $reason, 'source' => $source]);
    }
}
