<?php

namespace App\Services\KGarden;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to ERPNext (the core) about who may use the WMS.
 *
 * - OpenID Connect: authorize URL, code exchange, userinfo (kg_app, kg_role, kg_scopes ...)
 * - verify_pin / access_changes with the WMS service account (role KG Access Service)
 */
class AccessClient
{
    private function base(): string
    {
        $url = (string) config('kgarden.erp_url');
        if ($url === '') {
            throw new RuntimeException('KGARDEN_ERP_URL is not set');
        }

        return $url;
    }

    private function service(): PendingRequest
    {
        return Http::timeout(15)->acceptJson()->withHeaders([
            'Authorization' => 'token '.config('kgarden.service.api_key').':'.config('kgarden.service.api_secret'),
        ]);
    }

    public function authorizeUrl(string $state): string
    {
        return $this->base().'/api/method/frappe.integrations.oauth2.authorize?'.http_build_query([
            'client_id' => config('kgarden.oidc.client_id'),
            'response_type' => 'code',
            'scope' => 'openid all',
            'redirect_uri' => config('kgarden.oidc.redirect_uri'),
            'state' => $state,
        ]);
    }

    /** Exchange an authorization code for the person's userinfo (with the kg_* access claims). */
    public function identityFromCode(string $code): array
    {
        $token = Http::timeout(15)->asForm()->acceptJson()
            ->withBasicAuth((string) config('kgarden.oidc.client_id'), (string) config('kgarden.oidc.client_secret'))
            ->post($this->base().'/api/method/frappe.integrations.oauth2.get_token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('kgarden.oidc.redirect_uri'),
                'client_id' => config('kgarden.oidc.client_id'),
            ]);
        $accessToken = $token->json('access_token');
        if (! $token->ok() || ! $accessToken) {
            throw new RuntimeException('ERPNext token endpoint refused the code ('.$token->status().')');
        }

        $info = Http::timeout(15)->acceptJson()->withToken($accessToken)
            ->get($this->base().'/api/method/frappe.integrations.oauth2.openid_profile');
        if (! $info->ok() || ! $info->json('email')) {
            throw new RuntimeException('ERPNext userinfo failed ('.$info->status().')');
        }

        return [
            'user' => (string) $info->json('email'),
            'full_name' => (string) ($info->json('name') ?: $info->json('email')),
            'employee_code' => (string) ($info->json('kg_employee_code') ?? ''),
            'app' => (string) ($info->json('kg_app') ?? ''),
            'granted' => (bool) $info->json('kg_access'),
            'role' => $info->json('kg_role'),
            'scopes' => (array) ($info->json('kg_scopes') ?? []),
        ];
    }

    /** Employee code + PIN at a shared tablet; null when refused (never says which part was wrong). */
    public function verifyPin(string $employeeCode, string $pin, ?string $scope): ?array
    {
        $response = $this->service()->post($this->base().'/api/method/kgarden.access.api.verify_pin', array_filter([
            'app_code' => config('kgarden.app_code'),
            'employee_code' => $employeeCode,
            'pin' => $pin,
            'scope_value' => $scope,
        ], fn ($v) => $v !== null));
        if ($response->status() === 429) {
            throw new TooManyAttempts;
        }
        if (! $response->ok()) {
            throw new RuntimeException('ERPNext verify_pin failed ('.$response->status().')');
        }
        $answer = (array) $response->json('message');

        return ($answer['ok'] ?? false) ? $answer : null;
    }

    /** Access to this app of every person whose access changed since $since (ERPNext local time). */
    public function accessChanges(string $since): array
    {
        $response = $this->service()->get($this->base().'/api/method/kgarden.access.api.access_changes', [
            'app_code' => config('kgarden.app_code'),
            'since' => $since,
        ]);
        if (! $response->ok()) {
            throw new RuntimeException('ERPNext access_changes failed ('.$response->status().')');
        }

        return (array) $response->json('message');
    }
}
