<?php
namespace App\Delivery;

/** No live connector is registered until credentials, consent and homologation are verified. */
final class Providers
{
    public const LABELS = ['ifood'=>'iFood', 'aiqfome'=>'aiqfome', 'uairango'=>'UaiRango'];
    public const DOCUMENTATION = [
        'ifood'=>'https://developer.ifood.com.br/en-US/docs/food/guides/modules/authentication/intro',
        'aiqfome'=>'https://developer.aiqfome.com/docs/guides/get-started/step-1',
        'uairango'=>'https://www.uairango.com/connect/',
    ];
    public static function require(string $provider): void { abort_unless(array_key_exists($provider, self::LABELS), 404); }
    public static function liveAvailable(string $provider): bool { self::require($provider); return false; }
}
