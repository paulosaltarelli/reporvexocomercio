<?php

namespace App\Platform;

use App\Fiscal\Snapshot;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Queue safe metadata only. No tenant, database, login or password is created by HTTP. */
final class RequestCompanyProvision
{
    public function request(#[\SensitiveParameter] array $input, int $actorId, int $version): ProvisionRequest
    {
        Access::admin($actorId, $version);
        foreach (['name', 'admin_name', 'admin_email'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }
        $data = Validator::make($input, [
            'name' => 'required|string|min:2|max:180',
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'admin_name' => 'required|string|min:2|max:180', 'admin_email' => 'required|email|max:180|not_regex:/@support\.vexo\.invalid$/i',
            'is_demonstration' => 'required|boolean', 'request_key' => 'required|uuid',
            'password' => 'prohibited', 'admin_password' => 'prohibited', 'password_confirmation' => 'prohibited',
            'db_host' => 'prohibited', 'db_name' => 'prohibited', 'db_username' => 'prohibited', 'db_password' => 'prohibited',
            'active' => 'prohibited', 'storage_mode' => 'prohibited', 'fiscal_demonstration' => 'prohibited',
        ])->validate();
        if (\App\Tenancy\CompanyHosts::reserved($data['slug'])) {
            throw ValidationException::withMessages(['slug' => 'Esta identificação está reservada. Escolha outro subdomínio.']);
        }
        $data['name'] = trim($data['name']);
        $data['admin_name'] = trim($data['admin_name']);
        $data['admin_email'] = mb_strtolower(trim($data['admin_email']));
        $data['is_demonstration'] = (bool) $data['is_demonstration'];
        $key = $data['request_key'];
        unset($data['request_key']);
        $hash = Snapshot::hash($data);
        $existing = function () use ($key, $hash) {
            $request = ProvisionRequest::where('request_key', $key)->first();
            if ($request && ! hash_equals($request->payload_sha256, $hash)) {
                throw ValidationException::withMessages(['request_key' => 'Esta chave já foi usada com outros dados. Reabra o formulário.']);
            }
            return $request;
        };
        try {
            return DB::connection('central')->transaction(function () use ($actorId, $version, $data, $key, $hash, $existing) {
                $actor = PlatformAdmin::whereKey($actorId)->lockForUpdate()->first();
                abort_unless($actor && $actor->active && (int) $actor->auth_version === $version, 403);
                if ($request = $existing()) {
                    return $request;
                }
                if (Tenant::where('slug', $data['slug'])->exists()) {
                    throw ValidationException::withMessages(['slug' => 'Este identificador já pertence a uma empresa cadastrada.']);
                }
                if (ProvisionRequest::where('slug', $data['slug'])->whereIn('status', ['pending', 'provisioning'])->exists()) {
                    throw ValidationException::withMessages(['slug' => 'Já existe uma solicitação de implantação em andamento com este identificador.']);
                }
                $request = ProvisionRequest::create([...$data, 'request_key' => $key, 'requested_by' => $actor->id, 'status' => 'pending', 'active_slug' => $data['slug'], 'payload_sha256' => $hash]);
                PlatformAudit::create(['platform_admin_id' => $actor->id, 'provision_request_id' => $request->id, 'actor_name' => $actor->name, 'action' => 'company.provision_requested', 'changes' => ['status' => 'pending', 'slug' => $data['slug'], 'is_demonstration' => $data['is_demonstration']]]);
                return $request;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if ($request = $existing()) {
                return $request;
            }
            throw ValidationException::withMessages(['slug' => 'Não foi possível reservar este identificador. Confira se já há uma implantação em andamento.']);
        } catch (\Illuminate\Database\QueryException) {
            throw ValidationException::withMessages(['company' => 'Não foi possível registrar a solicitação. Tente novamente com a mesma chave de solicitação.']);
        }
    }
}
