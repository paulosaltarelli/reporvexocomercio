<?php

namespace App\Console\Commands;

use App\Fiscal\PendingNfceCertificate;
use App\Models\Tenant;
use App\Services\TenantConnection;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\StreamableInputInterface;

final class StageFiscalCertificate extends Command
{
    protected $signature = 'vendra:fiscal-stage-pfx {tenant_id : ID central da unidade} {cnpj : CNPJ cadastrado da unidade}';

    protected $description = 'Prepara o PFX recebido em stdin para NFC-e em homologação. A senha e o CSC são informados em Minha empresa.';

    public function handle(TenantConnection $connection, PendingNfceCertificate $certificates): int
    {
        $id = filter_var($this->argument('tenant_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || (string) $id !== $this->argument('tenant_id')) {
            $this->error('Informe o ID central da unidade.');
            return self::FAILURE;
        }
        $stream = ($this->input instanceof StreamableInputInterface ? $this->input->getStream() : null) ?? STDIN;
        if (stream_isatty($stream)) {
            $this->error('Redirecione o arquivo binário para a entrada padrão.');
            return self::FAILURE;
        }

        try {
            $tenant = Tenant::where('active', true)->findOrFail($id);
            $connection->connect($tenant);
            $pfx = stream_get_contents($stream, PendingNfceCertificate::MAX_BYTES + 1);
            if ($pfx === false) {
                $this->error('Falha ao ler o arquivo da entrada padrão.');
                return self::FAILURE;
            }
            $certificates->stage($tenant, $this->argument('cnpj'), $pfx);
        } catch (ValidationException $e) {
            $this->error($e->validator->errors()->first());
            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('Não foi possível preparar o PFX. Confira a unidade, a liberação de homologação e o armazenamento fiscal privado.');
            return self::FAILURE;
        } finally {
            $connection->disconnect();
        }

        $this->info('Certificado preparado. O administrador pode informar a senha e o CSC em Minha empresa.');
        return self::SUCCESS;
    }
}
