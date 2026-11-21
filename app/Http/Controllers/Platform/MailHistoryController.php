<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Platform\MailAttempt;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MailHistoryController extends Controller
{
    private const STATUS = [
        'sent' => 'Aceito pelo SMTP',
        'failed' => 'Falha no envio',
        'pending' => 'Resultado não confirmado',
    ];

    private const TYPES = [
        'installer_invitation' => 'Convite de implantação',
        'company_activation' => 'Ativação da loja',
        'password_reset' => 'Redefinição de senha',
    ];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(self::STATUS))],
            'type' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'recipient' => ['nullable', 'string', 'max:254'],
        ]);

        $query = MailAttempt::query();
        if (filled($filters['type'] ?? null)) $query->where('type', $filters['type']);
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['recipient'] ?? null)) {
            $search = strtr(trim($filters['recipient']), ['!' => '!!', '%' => '!%', '_' => '!_']);
            $query->whereRaw("recipient LIKE ? ESCAPE '!'", ['%'.$search.'%']);
        }

        return response()->view('platform.emails.index', [
            'filters' => $filters,
            'statuses' => self::STATUS,
            'types' => self::TYPES,
            'attempts' => $query->orderByDesc('created_at')->orderByDesc('id')->paginate(30)->appends($filters),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(int $id)
    {
        $attempt = MailAttempt::findOrFail($id);
        $preview = isset(self::TYPES[$attempt->type]) ? view('mail.transactional', [
            'kind' => $attempt->type,
            'companyName' => $attempt->company_name,
            'recipientName' => $attempt->recipient,
            'preview' => true,
        ])->render() : null;

        return response()->view('platform.emails.show', [
            'attempt' => $attempt,
            'status' => self::STATUS[$attempt->status] ?? 'Desconhecido',
            'type' => self::TYPES[$attempt->type] ?? 'Outro',
            'preview' => $preview,
        ])->header('Cache-Control', 'private, no-store');
    }
}
