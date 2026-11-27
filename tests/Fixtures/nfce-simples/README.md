# Ensaio local da NFC-e do Simples Nacional

Revenda presencial interna em Araguari/MG para consumidor final, com CFOP 5102, CSOSN 102 e CRT 1. A loja fictícia recolhe IBS e CBS no DAS. O exemplo usa duas toalhas de algodão adquiridas de terceiros, tributação integral, somente mercadorias e pagamento em dinheiro. A data simulada é **01/03/2027**. As fontes foram conferidas em **27/11/2026**.

## Reproduzir

Na raiz do projeto, com as dependências do `composer.lock` instaladas inclusive as de desenvolvimento, execute

```sh
(cd tests/Fixtures/nfce-simples/PL_010h_v1.03 && sha256sum -c SHA256SUMS)
php tests/Fixtures/nfce-simples/ensaio.php
php vendor/bin/phpunit tests/Unit/SimplesResaleXmlTest.php
```

Para examinar o XML completo, acrescente `--xml` ao comando do ensaio. O script reaproveita o cálculo comercial do Vendra, o montador NFePHP e as rotinas de XMLDSig e QR Code v3. Gera uma chave temporária em memória por execução. O CNPJ e a IE contêm somente zeros, o QR Code aponta para um domínio reservado e o ambiente indicado no XML é homologação. A verificação ocorre localmente, sem chamada à SEFAZ, fila ou protocolo. As chaves temporárias mudam a assinatura entre execuções.

## Venda e valores esperados

O estabelecimento sintético enquadra-se na primeira faixa do Anexo I no período da operação. Considera-se RBT12 de R$ 120.000,00, sem dedução na faixa, alíquota efetiva do Simples de 4%, repartição de 0,17% para IBS e 15,33% para CBS. Dessa hipótese resultam `pIBSSN=0.0068` e `pCBSSN=0.6132`, em percentual. Fora do ensaio, os dados da empresa e da operação determinam as alíquotas efetivas. [Resolução CGSN 196/2026, arts. 2º e 3º e Anexo I](https://www.in.gov.br/web/dou/-/resolucao-cgsn-n-196-de-16-de-novembro-de-2026-745832719) · [LC 214/2025, Anexo XVIII](https://legis.senado.leg.br/norma/40180341/publicacao/40181079)

| Item | Bruto | Desconto | Líquido | IBS do Simples | CBS do Simples |
| --- | ---: | ---: | ---: | ---: | ---: |
| 1 | R$ 80,00 | R$ 0,10 | R$ 79,90 | R$ 0,01 | R$ 0,49 |
| 2 | R$ 82,90 | R$ 0,00 | R$ 82,90 | R$ 0,01 | R$ 0,51 |
| Total | **R$ 162,90** | **R$ 0,10** | **R$ 162,80** | **R$ 0,02** | **R$ 1,00** |

O item contém `prod/cAtivSN=01` e os dois subgrupos de `imposto/IBSCBS/gTribSN`. A tabela do §2.3 prevê a ausência de `emit/tpRegimeIBSCBS` e de `imposto/IBSCBS/gIBSCBS` neste regime. A nota soma os centavos já apurados nos itens em `total/IBSCBSTot/gTribSN`. Calcular o IBS sobre R$ 162,80 de uma só vez daria R$ 0,01. O total devido pelos dois itens é R$ 0,02. O valor de `vBCIBSCBS` é 0,00 conforme W35-10, que soma apenas as bases do grupo regular. Os R$ 162,80 em `vNFTot` correspondem à soma de `vItem`. [NT 2026.005 v1.00, §§2–4](https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=3&nomeArquivo=NT_2026.005_v1.00_RTC_SimplesNacional.pdf) · [NT 2025.002 v1.53, VB01, W35 e W60](https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=3&nomeArquivo=NT_2025.002_v1.53_RTC_NF-e_IBS_CBS_IS.pdf)

## Vigência e limites da validação

A NT 2026.005 programa a implantação do leiaute em testes até 26/10/2026 e em produção em 16/11/2026. A reforma passa a produzir efeitos para a apuração do Simples em 01/01/2027. Na obrigação documental de IBS/CBS, o modelo 65 figura no art. 1º, II, do Ato Conjunto 4/2026. O novo §1º, II, dado pelo Ato 7/2026, fixa **01/03/2027** para essa hipótese do Simples. A regra UB12-10 da NT 2025.002 v1.53, por sua vez, identifica CRT 3 na homologação de 2026. [Receita Federal sobre a Resolução CGSN 196/2026](https://www.gov.br/receitafederal/pt-br/assuntos/noticias/2026/novembro/comite-gestor-do-simples-nacional-publica-novas-regras-para-o-simples-nacional-e-para-a-transicao-da-reforma-tributaria) · [Ato Conjunto 4/2026, art. 1º](https://www.cte.fazenda.gov.br/portal/informe.aspx?informe=176) · [Ato Conjunto 7/2026, art. 1º](https://cgibs.gov.br/upload/arquivos/202611/13061456-ato-conjunto-7-rfb-cgibs-alteracao-ato-4.pdf)

O ensaio verifica o documento completo no XSD oficial, a assinatura e as quatro regras de totalização do §4 da NT 2026.005. Os testes cobrem os dois grupos presentes nos itens, as alíquotas, a atividade e a ausência de grupos do regime regular. Reproduzem também W59h-10 e W59h-20 (presença dos totais), W59i-10 e W59j-10 (somas), código de atividade desconhecido e alteração posterior à assinatura.

**Pendência para autorização com a classificação da amostra.** Na tabela do IT 2025.002 v1.71, a classe 000001 aparece vinculada ao CST 000, admite NFC-e e indica receita bruta interna do Simples. O CST tem o indicador que exige `gIBSCBS`. UB13-30 da NT 2025.002 v1.53 prevê rejeição 1022 se esse grupo faltar. A tabela específica do §2.3 da NT 2026.005 v1.00 orienta omitir o grupo no CRT 1 que permanece no regime único. A combinação precisa de esclarecimento oficial ou de verificação pelo autorizador de MG antes de liberar uma venda com esta classificação. [Tabela oficial SVRS](https://dfe-portal.svrs.rs.gov.br/Cff/ClassificacaoTributaria) · [Informe Técnico v1.71 e notas técnicas](https://dfe-portal.svrs.rs.gov.br/Nfe/Documentos)

Na revisão de origem, o emissor utiliza NFePHP v5.2.9 com `PL_010_V1.30`. A geração direta pela loja requer um perfil versionado com vigência, regime do IBS/CBS, atividade por item e parâmetros aprovados para a competência. Também requer validação na assinatura e no protocolo com o pacote congelado da venda. Este ensaio é privado, usa uma identidade sintética e confirma as propriedades listadas acima.

## Origem dos XSD

O diretório `PL_010h_v1.03` contém os cinco arquivos da pasta `NFe/` necessários para validar `nfe_v4.00.xsd` e suas dependências, copiados byte a byte do pacote de 13/11/2026. O manifesto `SHA256SUMS` registra cada arquivo. O SHA-256 do ZIP original é `bcbf6b277f79a6983d69016dfe0430f09b8a191997a4706a24938acddc18dc81`. [Download oficial do pacote](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=YhWikFoTxUo%3D)
