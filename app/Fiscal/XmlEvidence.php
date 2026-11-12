<?php

namespace App\Fiscal;

use App\Models\FiscalCredential;
use NFePHP\Common\Signer;

final class XmlEvidence
{
    public function authorization(#[\SensitiveParameter] string $xml, #[\SensitiveParameter] array $payload, #[\SensitiveParameter] FiscalCredential $credential): array
    {
        try {
            if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                throw new \RuntimeException;
            }
            app(FiscalSchema::class)->validate($xml, $payload['_schema_package'] ?? FiscalSchema::PACKAGE);
            $dom = new \DOMDocument;
            $dom->preserveWhiteSpace = false;
            if (! @$dom->loadXML($xml, LIBXML_NONET)) {
                throw new \RuntimeException;
            }
            $xp = new \DOMXPath($dom);
            $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            $xp->registerNamespace('s', 'http://www.w3.org/2000/09/xmldsig#');
            if ($dom->documentElement->localName !== 'nfeProc' || $xp->query('//n:infNFe')->length !== 1 || $xp->query('//s:Signature')->length !== 1 || $xp->query('//n:infProt')->length !== 1) {
                throw new \RuntimeException;
            }
            $value = fn (string $q) => $xp->evaluate('string('.$q.')');
            $base = '/n:nfeProc/n:NFe/n:infNFe';
            $prot = '/n:nfeProc/n:protNFe/n:infProt';
            $key = $value($prot.'/n:chNFe');
            if ($value($prot.'/n:cStat') !== '100' || $value($prot.'/n:tpAmb') !== '2' || $value($base.'/n:ide/n:tpAmb') !== '2' || $value($base.'/n:ide/n:mod') !== '65' || $value($base.'/n:ide/n:tpEmis') !== '1'
                || $value($base.'/n:emit/n:CNPJ') !== $payload['cnpj_emitente'] || (int) $value($base.'/n:ide/n:nNF') !== (int) $payload['numero'] || (int) $value($base.'/n:ide/n:serie') !== (int) $payload['serie']
                || $value($base.'/@Id') !== 'NFe'.$key || $value('//s:Reference/@URI') !== '#NFe'.$key || ! preg_match('/^[0-9A-Z]{44}$/D', $key)
                || bccomp($value($base.'/n:total/n:ICMSTot/n:vNF'), $payload['valor_total'], 2) !== 0 || ! $value($prot.'/n:nProt') || $value($prot.'/n:digVal') !== $value('//s:DigestValue')) {
                throw new \RuntimeException;
            }
            $cert = '-----BEGIN CERTIFICATE-----'.PHP_EOL.chunk_split($value('//s:X509Certificate'), 64, PHP_EOL).'-----END CERTIFICATE-----';
            if (! hash_equals($payload['_certificate_fingerprint'] ?? $credential->certificate_fingerprint, (string) openssl_x509_fingerprint($cert, 'sha256')) || ! Signer::isSigned($xml, 'infNFe')) {
                throw new \RuntimeException;
            }

            return ['key' => $key, 'protocol' => $value($prot.'/n:nProt'), 'authorized_at' => $value($prot.'/n:dhRecbto')];
        } catch (\Throwable) {
            throw new FiscalTransportException('authorization_xml_invalid');
        }
    }
}
