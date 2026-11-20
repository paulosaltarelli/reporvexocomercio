<?php

namespace App\Tenancy;

final class CompanyHosts
{
    public const DEMO_SLUG = 'vexo';

    public static function demoHost(): string
    {
        return strtolower(trim((string) config('tenancy.demo_host'), '. '));
    }

    public static function domain(): string
    {
        return strtolower(trim((string) config('tenancy.company_domain'), '. '));
    }

    public static function legacyHosts(): array
    {
        return array_unique(array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            ...array_map('trim', explode(',', strtolower((string) config('tenancy.legacy_hosts')))),
        ]));
    }

    public static function reserved(string $slug): bool
    {
        $alias = self::demoHost();
        if ($slug === 'lojavendra' || ($alias !== '' && $slug === explode('.', $alias, 2)[0])) return true;
        if (self::domain() === '') return false;
        return in_array($slug, ['www', 'mail', 'smtp', 'admin', 'painel', 'loja', 'vendra', 'api', 'autodiscover'], true)
            || in_array($slug.'.'.self::domain(), self::legacyHosts(), true);
    }

    public static function url(string $slug, string $route = 'login'): string
    {
        $path = route($route, ['company' => $slug], false);
        if ($slug === self::DEMO_SLUG && self::demoHost() !== '') return 'https://'.self::demoHost().$path;
        if (self::domain() === '' || self::reserved($slug)) return url($path);
        return 'https://'.$slug.'.'.self::domain().$path;
    }
}
