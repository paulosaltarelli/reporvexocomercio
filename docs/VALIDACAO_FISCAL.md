# Validação fiscal — 12/11/2026

Base comercial integrada: `25eea153b9101bcfb83ded95c120ce724fb49e53`. Checkout/branch isolados, sem alteração da VPS ou de seus outros sistemas.

## Evidência executada

- Suíte consolidada Laravel/PHPUnit: **98 testes, 1.188 verificações, nenhum skip/falha**, exit 0, 369,035 segundos. PHP 8.3.36, MySQL 8.0, PHPUnit 11.5.57, bcmath/pdo_mysql/pcntl/gd/soap em Docker isolado. Inclui regressões comerciais atuais: catálogo, estoque, financeiro, fechamento, descontos/comissões e isolamento.
- Após ajustar o cancelamento para repetir o mesmo pedido somente após nova consulta confirmando que a nota continua autorizada, reexecução dirigida: **3 testes, 40 verificações, nenhum skip/falha**, exit 0, 40,592 segundos. Casos: autorização com XML assinado, timeout de emissão sem segundo POST e cancelamento com perda da primeira resposta, preservação da venda e autorização tardia sem ressuscitar nota cancelada. Os números desta execução não foram somados artificialmente à suíte consolidada.
- Chromium/Playwright em servidor Laravel real: login; preferências em Minha empresa; upload de PFX de teste com senha; campos secretos vazios após salvar e ausentes do HTML; cadastro de regra por produto; conferência de venda mista. Nenhum erro JavaScript; desktop 1365px e móvel 390px sem overflow horizontal da página. Bases de preview separadas das três bases dos testes.
- `git diff --check` e revisão de sintaxe/estilo nos arquivos PHP alterados.

Testes MySQL usam exclusivamente `vendra_test_central`, `vendra_t_test_a`, `vendra_t_test_b`, com usuário restrito e confirmação de banco/usuário antes de limpeza. Não houve migração/limpeza em banco operacional. Um runner por vez, conforme TESTES.md; os processos concorrentes internos fazem parte do caso controlado.

## Cobertura fiscal específica

Certificado/PFX incorreto, CNPJ divergente, segredo cifrado/oculto; CPF/CNPJ numérico e alfanumérico; contexto e permissões administrativas; produção/demonstração bloqueadas; payload congelado e preços históricos; serviços legados/unificados fora da NFC-e; gross/desconto/líquido separados e comissão sem acréscimo à nota; ausência de regra sem consumir número; configuração alterada bloqueando transmissão.

Outbox sem HTTP em transação; IDs/tenant e limpeza em finally; idempotência da venda; dois processos MySQL alocando números distintos e repetição concorrente da mesma venda sem outro documento; autorização somente com resposta e XML correspondentes, XSD e assinatura; HTTP 200 sem prova; rejeição oficial; timeout seguido de consulta sem reemitir/renumerar; reenvio explícito após dois resultados de referência não encontrada; cancelamento fiscal sem estorno comercial e resposta tardia; inutilização reservando faixa de forma idempotente e preservando XML/protocolo; arquivos cifrados com hash e downloads privados; bloqueio de primeiro envio se a venda foi cancelada comercialmente antes dele.

## Limites da evidência

**Os XMLs/protocolos acima são simulações de teste, não retornos oficiais da SEFAZ.** Certificados são autogerados em runtime, não certificados do proprietário nem prova de confiança ICP-Brasil. QR das fixtures aponta a example.test e nunca foi apresentado como cupom fiscal real. Nenhuma chamada Focus/SEFAZ real foi feita: os testes de transporte usam Http::fake e bloqueiam chamadas não simuladas.

Não foram executados: credenciamento estadual, configuração de certificado real, contratação de provedor, homologação oficial, instalação do timer/serviço na VPS, restauração externa, virada de produção ou envio automático a clientes.

Para homologação oficial: configurar dados reais do emitente no formulário seguro, conferir UF/operacão e regras com contador, cadastrar A1/CSC/tokens de homologação, sincronizar com a Focus com consentimento, executar emissão/consulta/cancelamento e guardar XML/protocolos oficiais e hash. Rejeições devem ser registradas como tal. Empresa de demonstração continua bloqueada para produção. Produção só será avaliada depois da evidência concreta de homologação e autorização explícita do proprietário.
