<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Reads the resolved company's operational configuration without cross-request caching. */
final class OperationalModes
{
    public const SEGMENTS = [
        'commerce' => 'Comércio em geral',
        'restaurant' => 'Restaurante e alimentação',
        'supermarket' => 'Supermercado e autosserviço',
    ];

    public const MODES = [
        'commerce' => 'Balcão e serviços',
        'restaurant' => 'Mesas e comandas',
        'supermarket' => 'Caixa de supermercado',
    ];

    public const ROUTES = [
        'commerce' => 'desk',
        'restaurant' => 'restaurant.index',
        'supermarket' => 'supermarket.index',
    ];

    public const PERMISSIONS = [
        'commerce' => 'desk.use',
        'restaurant' => 'restaurant.use',
        'supermarket' => 'supermarket.use',
    ];

    public function current(): array
    {
        return app(CompanyOperations::class)->current();
    }

    public function enabled(string $mode): bool
    {
        return isset(self::MODES[$mode]) && in_array($mode, $this->current()['operation_modes'], true);
    }

    public function require(string $mode): void
    {
        if (!$this->enabled($mode)) {
            throw ValidationException::withMessages(['operation_modes' => 'Este atendimento não está habilitado para a empresa. Confira Operação e implantação.']);
        }
    }

    public function landing(User $user): ?string
    {
        $configuration = $this->current();
        $modes = array_unique([$configuration['default_operation'], ...$configuration['operation_modes']]);
        foreach ($modes as $mode) {
            if ($mode === 'restaurant' && in_array($mode, $configuration['operation_modes'], true) && !$user->allows('restaurant.use') && $user->allows('restaurant.kitchen')) {
                return 'restaurant.production';
            }
            if (in_array($mode, $configuration['operation_modes'], true) && $user->allows(self::PERMISSIONS[$mode])) {
                return self::ROUTES[$mode];
            }
        }
        return null;
    }
}
