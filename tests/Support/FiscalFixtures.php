<?php

namespace Tests\Support;

use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;

/** Synthetic certificates/XML only. These fixtures were NEVER authorized by SEFAZ. */
final class FiscalFixtures
{
    public static function certificate(string $cnpj = '11222333000181'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'vendra-cert-');
        file_put_contents($path, "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ext]\nkeyUsage=critical,digitalSignature,keyEncipherment\nsubjectAltName=otherName:2.16.76.1.3.3;UTF8:".$cnpj."\n");
        try {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $csr = openssl_csr_new(['commonName' => 'SIMULACAO VENDRA SEM VALOR FISCAL', 'organizationName' => 'TEST CERTIFICATE'], $key, ['config' => $path, 'digest_alg' => 'sha256']);
            $cert = openssl_csr_sign($csr, null, $key, 30, ['config' => $path, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256']);
            openssl_pkcs12_export($cert, $pfx, $key, 'test-only-password');

            return ['pfx' => $pfx, 'password' => 'test-only-password', 'fingerprint' => openssl_x509_fingerprint($cert, 'sha256')];
        } finally {
            unlink($path);
        }
    }

    public static function rule(): array
    {
        return ['cfop' => '5102', 'icms_code' => '102', 'pis_code' => '07', 'cofins_code' => '07', 'gtin' => 'SEM GTIN', 'rtc_mode' => 'standard', 'rtc_cst' => '000', 'rtc_class' => '000001', 'ibs_uf_rate' => '0.1', 'ibs_municipal_rate' => '0', 'cbs_rate' => '0.9', 'transparency_rate' => '0'];
    }

    /** Synthetic SEFAZ-shaped response to the exact signed request; never official evidence. */
    public static function authorizationResponse(string $signedXml, bool $consult = false): string
    {
        $dom = new \DOMDocument;
        $dom->loadXML($signedXml, LIBXML_NONET);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $xp->registerNamespace('s', 'http://www.w3.org/2000/09/xmldsig#');
        $key = substr($xp->evaluate('string(//n:infNFe/@Id)'), 3);
        $digest = $xp->evaluate('string(//s:DigestValue)');
        $protocol = '<protNFe versao="4.00"><infProt><tpAmb>2</tpAmb><verAplic>SIMULACAO</verAplic><chNFe>'.$key.'</chNFe><dhRecbto>'.date('c').'</dhRecbto><nProt>135260000000001</nProt><digVal>'.$digest.'</digVal><cStat>100</cStat><xMotivo>SIMULACAO NAO AUTORIZADA SEFAZ</xMotivo></infProt></protNFe>';
        if ($consult) {
            return '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>2</tpAmb><verAplic>SIMULACAO</verAplic><cStat>100</cStat><xMotivo>SIMULACAO</xMotivo><cUF>35</cUF><dhRecbto>'.date('c').'</dhRecbto><chNFe>'.$key.'</chNFe>'.$protocol.'</retConsSitNFe>';
        }
        return '<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>2</tpAmb><verAplic>SIMULACAO</verAplic><cStat>104</cStat><xMotivo>SIMULACAO LOTE PROCESSADO</xMotivo><cUF>35</cUF><dhRecbto>'.date('c').'</dhRecbto>'.$protocol.'</retEnviNFe>';
    }

    public static function cancellationExchange(string $key, string $protocol, string $reason, array $fixture): array
    {
        $eventId = 'ID110111'.$key.'01';
        $request = '<evento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><infEvento Id="'.$eventId.'"><cOrgao>'.substr($key, 0, 2).'</cOrgao><tpAmb>2</tpAmb><CNPJ>11222333000181</CNPJ><chNFe>'.$key.'</chNFe><dhEvento>'.date('c').'</dhEvento><tpEvento>110111</tpEvento><nSeqEvento>1</nSeqEvento><verEvento>1.00</verEvento><detEvento versao="1.00"><descEvento>Cancelamento</descEvento><nProt>'.$protocol.'</nProt><xJust>'.htmlspecialchars($reason, ENT_XML1).'</xJust></detEvento></infEvento></evento>';
        $request = Signer::sign(Certificate::readPfx($fixture['pfx'], $fixture['password']), $request, 'infEvento', 'Id', OPENSSL_ALGO_SHA1, Signer::CANONICAL, 'evento');
        $event = '<retEvento versao="1.00"><infEvento><tpAmb>2</tpAmb><verAplic>SIMULACAO</verAplic><cOrgao>35</cOrgao><cStat>135</cStat><xMotivo>SIMULACAO</xMotivo><chNFe>'.$key.'</chNFe><tpEvento>110111</tpEvento><xEvento>Cancelamento registrado</xEvento><nSeqEvento>1</nSeqEvento><dhRegEvento>'.date('c').'</dhRegEvento><nProt>135260000000002</nProt></infEvento></retEvento>';
        return ['request' => '<envEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><idLote>1</idLote>'.$request.'</envEvento>', 'response' => '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><idLote>1</idLote><tpAmb>2</tpAmb><verAplic>SIMULACAO</verAplic><cOrgao>35</cOrgao><cStat>128</cStat><xMotivo>SIMULACAO</xMotivo>'.$event.'</retEnvEvento>'];
    }

    public static function xml(array $payload, array $fixture): array
    {
        $n = str_pad($payload['numero'], 9, '0', STR_PAD_LEFT);
        $s = str_pad($payload['serie'], 3, '0', STR_PAD_LEFT);
        $body = '35'.date('ym').$payload['cnpj_emitente'].'65'.$s.$n.'1'.'12345678';
        $sum = 0;
        $weight = 2;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }
        $digit = 11 - ($sum % 11);
        if ($digit >= 10) {
            $digit = 0;
        } $key = $body.$digit;
        $total = $payload['valor_total'];
        $date = date('c');
        $xml = '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe'.$key.'" versao="4.00"><ide><cUF>35</cUF><cNF>12345678</cNF><natOp>SIMULACAO TESTE</natOp><mod>65</mod><serie>'.$payload['serie'].'</serie><nNF>'.$payload['numero'].'</nNF><dhEmi>'.$date.'</dhEmi><tpNF>1</tpNF><idDest>1</idDest><cMunFG>3550308</cMunFG><tpImp>4</tpImp><tpEmis>1</tpEmis><cDV>'.$digit.'</cDV><tpAmb>2</tpAmb><finNFe>1</finNFe><indFinal>1</indFinal><indPres>1</indPres><procEmi>0</procEmi><verProc>TEST</verProc></ide><emit><CNPJ>'.$payload['cnpj_emitente'].'</CNPJ><xNome>SIMULACAO NAO AUTORIZADA SEFAZ</xNome><xFant>TESTE</xFant><enderEmit><xLgr>RUA DE TESTE</xLgr><nro>1</nro><xBairro>TESTE</xBairro><cMun>3550308</cMun><xMun>SAO PAULO</xMun><UF>SP</UF><CEP>01001000</CEP><cPais>1058</cPais><xPais>BRASIL</xPais></enderEmit><IE>111111111111</IE><CRT>1</CRT></emit>';
        foreach ($payload['items'] as $i => $item) {
            $xml .= '<det nItem="'.($i + 1).'"><prod><cProd>TEST</cProd><cEAN>SEM GTIN</cEAN><xProd>SIMULACAO SEM VALOR FISCAL</xProd><NCM>84713012</NCM><CFOP>5102</CFOP><uCom>UN</uCom><qCom>'.$item['quantidade_comercial'].'</qCom><vUnCom>'.$item['valor_unitario_comercial'].'</vUnCom><vProd>'.$item['valor_bruto'].'</vProd><cEANTrib>SEM GTIN</cEANTrib><uTrib>UN</uTrib><qTrib>'.$item['quantidade_tributavel'].'</qTrib><vUnTrib>'.$item['valor_unitario_tributavel'].'</vUnTrib><indTot>1</indTot></prod><imposto><vTotTrib>0.00</vTotTrib><ICMS><ICMSSN102><orig>0</orig><CSOSN>102</CSOSN></ICMSSN102></ICMS><PIS><PISNT><CST>07</CST></PISNT></PIS><COFINS><COFINSNT><CST>07</CST></COFINSNT></COFINS></imposto></det>';
        }
        $xml .= '<total><ICMSTot><vBC>0.00</vBC><vICMS>0.00</vICMS><vICMSDeson>0.00</vICMSDeson><vFCP>0.00</vFCP><vBCST>0.00</vBCST><vST>0.00</vST><vFCPST>0.00</vFCPST><vFCPSTRet>0.00</vFCPSTRet><vProd>'.$total.'</vProd><vFrete>0.00</vFrete><vSeg>0.00</vSeg><vDesc>0.00</vDesc><vII>0.00</vII><vIPI>0.00</vIPI><vIPIDevol>0.00</vIPIDevol><vPIS>0.00</vPIS><vCOFINS>0.00</vCOFINS><vOutro>0.00</vOutro><vNF>'.$total.'</vNF><vTotTrib>0.00</vTotTrib></ICMSTot></total><transp><modFrete>9</modFrete></transp><pag><detPag><tPag>17</tPag><vPag>'.$total.'</vPag></detPag></pag></infNFe><infNFeSupl><qrCode><![CDATA[https://example.test/SIMULACAO-SEM-VALOR-FISCAL]]></qrCode><urlChave>https://example.test</urlChave></infNFeSupl></NFe><protNFe versao="4.00"><infProt><tpAmb>2</tpAmb><verAplic>TEST</verAplic><chNFe>'.$key.'</chNFe><dhRecbto>'.$date.'</dhRecbto><nProt>135260000000001</nProt><digVal>TEST</digVal><cStat>100</cStat><xMotivo>SIMULACAO DE TESTE NAO AUTORIZADA</xMotivo></infProt></protNFe></nfeProc>';
        $certificate = Certificate::readPfx($fixture['pfx'], $fixture['password']);
        $xml = str_replace('https://example.test/SIMULACAO-SEM-VALOR-FISCAL', 'https://example.test/SIMULACAO?p='.$key.'|3|2', $xml);
        $xml = str_replace('<urlChave>https://example.test</urlChave>', '<urlChave>https://example.test/SIMULACAO</urlChave>', $xml);
        $xml = Signer::sign($certificate, $xml, 'infNFe', 'Id', OPENSSL_ALGO_SHA1, Signer::CANONICAL, 'NFe');
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $dom->getElementsByTagName('digVal')->item(0)->nodeValue = $dom->getElementsByTagName('DigestValue')->item(0)->nodeValue;
        $xml = $dom->saveXML();

        return ['xml' => $xml, 'key' => $key, 'date' => $date];
    }
}
