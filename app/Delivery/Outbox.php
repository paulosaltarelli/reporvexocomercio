<?php
namespace App\Delivery;

use App\Models\OperationRecord;
use App\Services\{CompanyOperations, InstallerAccess, OperationalModes};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Durable local retry policy. Live transport deliberately unavailable in this release. */
final class Outbox
{
    public const MAX_ATTEMPTS = 5;
    public const RETRY_SECONDS = [30, 120, 600, 1800, 3600];

    public function retry(int $id, int $actor): Event
    {
        return DB::connection('tenant')->transaction(function () use ($id, $actor) {
            app(CompanyOperations::class)->lock();
            app(OperationalModes::class)->require('restaurant');
            InstallerAccess::authorizedActor($actor,'delivery.manage');
            $event = Event::where('direction','outbox')->whereHas('order')->lockForUpdate()->findOrFail($id);
            if ($event->simulation || $event->order?->simulation || in_array($event->status,['processed','simulated'],true)) {
                throw ValidationException::withMessages(['event'=>'Eventos de teste ou já concluídos não podem ser enviados ao provedor.']);
            }
            if ($event->attempts >= self::MAX_ATTEMPTS) throw ValidationException::withMessages(['event'=>'O limite de cinco tentativas foi atingido. Confira a causa antes de uma nova operação.']);
            if ($event->next_attempt_at?->isFuture()) throw ValidationException::withMessages(['event'=>'Aguarde o horário da próxima tentativa.']);
            $integration = Integration::where('provider',$event->provider)->first();
            if (!$integration?->enabled || !Providers::liveAvailable($event->provider)) {
                $event->update(['status'=>'blocked','last_error'=>'Aguardando credenciais, autorização da loja e homologação oficial. Nenhuma chamada externa foi realizada.','next_attempt_at'=>null]);
                OperationRecord::create(['action'=>'delivery_outbox_blocked','version'=>1,'created_at'=>now(),'before'=>[],'user_id'=>$actor,'after'=>['event_id'=>$event->id,'provider'=>$event->provider]]);
                return $event;
            }
            // Fail closed even if a DB flag was manually changed. A reviewed adapter must replace this guard.
            throw new \LogicException('Não existe adaptador homologado registrado para este provedor.');
        },3);
    }
}
