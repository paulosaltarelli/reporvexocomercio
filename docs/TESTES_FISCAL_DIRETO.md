# Evidências de QA — NFC-e direta

Validação em 12/11/2026, exclusivamente no checkout isolado `/srv/vendra-fiscal-qa.wmtFsq` e nos bancos dedicados `vendra_test_central`, `vendra_t_test_a` e `vendra_t_test_b`, com usuário SQL restrito. Nenhum dado operacional foi alterado pelo QA.

## Limite da evidência

Certificados e respostas SOAP são sintéticos, explicitamente sem valor fiscal. A geração, assinatura RSA, verificação de assinatura, comparação do conteúdo e XSD NFePHP são reais. Somente o transporte de autorização/consulta/cancelamento é substituído nos testes. Não houve envio nem autorização real da SEFAZ, credenciamento, validação de CSC real ou liberação de produção.

Os testes deixaram de simular URLs ou JSON da Focus. Configuração e certificado permanecem locais; a validação local não transmite nota. Inutilização direta está bloqueada antes de reservar números ou enfileirar pedidos.

## Execuções

- O primeiro filtro de geração encontrou uma falha real: `det/prod/vDesc=0.00` não atende ao XSD do campo opcional positivo. O builder passou a omitir esse campo quando zero, preservando o total de descontos.
- Após a correção, filtro geração/autorização: **2 testes passaram, 29 verificações, 60,64 s**.
- Suíte completa inicial: **112 passaram e 2 falharam, 1.280 verificações, 326,53 s**. Todos os 62 casos Commerce e 24 Unit passaram. As duas falhas fiscais eram da fixture de evento: o argumento de raiz de `Signer::sign` estava como `ID`, em vez de `evento`.
- Após corrigir a fixture e acrescentar a negativa de cancelamento, FiscalTest completo: **28 passaram e 1 falhou, 253 verificações, 125,80 s**. A única falha era uma assertion que esperava um identificador interno na mensagem; o backend fornecia corretamente a mensagem em português com o código 501. Não foi necessário alterar a regra para corrigir esse teste.
- Último filtro, após corrigir a assertion e ampliar a verificação de rotação de A1: **2 testes passaram, 40 verificações, 65,00 s**, saída 0, nenhum skip/falha. Executou integralmente os casos de evidência de cancelamento e negativa correlacionada.
- Último lint: **137 arquivos PHP e 62 views Blade compiladas passaram**. `view:clear` e `view:cache` também concluíram com saída 0.

Há 29 casos fiscais na fonte. Os resultados acima são execuções distintas e suas verificações não são somadas nem apresentadas como uma nova suíte completa inexistente.

## Garantias verificadas

- Isolamento entre empresas, barreiras de operador, segredos cifrados/ocultos e arquivos privados.
- Reserva e repetição sem duplicar documentos/números; consultas após timeout sem segunda transmissão automática.
- XML assinado congelado antes de transmitir, com hash e criptografia, sem mudança no replay.
- Autorização exige protocolo e XML válidos, assinatura e correspondência integral do `infNFe` com o original. Alterações de SKU, NCM e origem, mantendo o total e uma assinatura válida, são rejeitadas.
- Respostas 103/104/105/106 incompletas, 217 e XML malformado permanecem em reconciliação; sucesso de transporte não significa autorização.
- Cancelamento exige pedido assinado e correlação de chave, emitente, ambiente, evento, sequência e protocolo. Não altera estoque, venda ou financeiro e não é revertido por autorização tardia.
- Eventos podem ser verificados com o certificado atual cadastrado ou o certificado original da nota. Um terceiro certificado não cadastrado, mesmo com o mesmo CNPJ e assinatura válida, é rejeitado. Autorização mantém o pin estrito do certificado original.
- Negativa 501 correlacionada encerra somente o pedido de cancelamento e mantém a nota autorizada, permitindo nova solicitação. Duplicidade 573 e resposta com chave forjada não encerram a reconciliação.
- Checkbox enviado pelo navegador como string `1` é aceito pelo endpoint e apenas enfileira a emissão; nenhum transporte ocorre no POST.

A fixture usa migrações uma vez por processo e limpeza entre casos, com allowlist de bancos, usuário e banco efetivo conferidos. IDs não são reiniciados; os singletons usam ID explícito. Corridas com processos mantêm commits reais e foram executadas, sem transação envolvendo o teste inteiro.
