<?php

use NFePHP\Common\Signer;
use Tests\Support\SimplesResaleXmlFixture;

require dirname(__DIR__, 3).'/vendor/autoload.php';

if (PHP_SAPI !== 'cli' || ! in_array(array_slice($argv, 1), [[], ['--xml']], true)) {
    exit(1);
}
$xml = SimplesResaleXmlFixture::build();
if (! $xml->schemaValidate(SimplesResaleXmlFixture::SCHEMA)
    || ! Signer::isSigned($xml->saveXML(), 'infNFe')
    || SimplesResaleXmlFixture::totalViolations($xml) !== []) {
    throw new RuntimeException('Falhou a verificação local da NFC-e sintética.');
}
if (($argv[1] ?? '') === '--xml') {
    echo $xml->saveXML();
} else {
    $total = $xml->getElementsByTagName('IBSCBSTot')->item(0);
    printf("Ensaio local de NFC-e. Modelo 65, tpAmb 2, CRT 1, CFOP 5102, %d itens.\n", $xml->getElementsByTagName('det')->length);
    printf("IBS do Simples R$ %s. CBS do Simples R$ %s. Venda R$ %s.\n",
        $total->getElementsByTagName('vIBSSN')->item(0)->textContent,
        $total->getElementsByTagName('vCBSSN')->item(0)->textContent,
        $xml->getElementsByTagName('vNFTot')->item(0)->textContent);
    echo "PL_010h_v1.03, soma dos itens e assinatura efêmera conferidos. Sem protocolo SEFAZ.\n";
}
