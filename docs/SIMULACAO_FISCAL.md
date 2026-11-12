# Simulação fiscal interna e configuração em rascunho

Esta etapa acrescenta exercícios fiscais internos e permite guardar uma configuração ainda incompleta. Não exige certificado para simular. Não equivale a emissão, homologação oficial, autorização, cancelamento real ou aprovação tributária.

## Separação do fluxo real

- Exercícios ficam exclusivamente em `fiscal_simulations` e `fiscal_simulation_events`. Snapshot e eventos são preservados; a simulação tem referência interna, não chave de acesso ou protocolo fiscal inventados.
- Os cenários locais de aceitação, rejeição e incerteza servem para exercitar a interface. Consultar ou cancelar um exercício altera apenas seu histórico interno.
- Recibo/representação de simulação deve permanecer marcado **SIMULAÇÃO INTERNA — SEM VALOR FISCAL**. Não é DANFE/NFC-e e não deve ser entregue como documento fiscal.
- Não assina XML, reserva série/número, cria emitente/credencial, preenche CSC, consulta SEFAZ nem coloca itens na `fiscal_outbox` real.
- Não altera a venda, preço, atribuição de vendedor, comissão, caixa, estoque, contas ou custo médio. É uma leitura com retrato do estado comercial, seguida de escrita em tabelas próprias.
- Campos desconhecidos da empresa podem ser salvos em `fiscal_settings.configuration_draft`. Isso não promove uma configuração incompleta a pronta, não aprova contador e não libera produção. Cadastro operacional e revisão da configuração efetiva continuam sendo passos distintos.
- Dados A1/PFX e CSC continuam sendo pré-requisitos do fluxo oficial quando aplicáveis; esta etapa não os cria nem os solicita para executar o exercício local.

## Qualidade necessária

Antes de publicar: QA do isolamento por empresa, permissões, imutabilidade do snapshot/eventos, reutilização de idempotência, cenários locais, rascunho parcial e bloqueios do transporte oficial. A suíte deve demonstrar que simular/consultar/cancelar não muda tabelas comerciais, documentos/outbox reais ou numeração. Conferir os pontos compartilhados e preservar os hashes dos arquivos de PDV, vendedores, preços e caixa. Nesta etapa, os testes de efeitos protegidos comparam os dados comerciais antes/depois de cada cenário.

A evidência de QA precisa identificar versão/manifesto e ambiente de testes. Um teste mockado ou recibo interno não pode ser descrito como autorização oficial. Esta documentação não declara os resultados de QA que ainda estão em execução.

## Procedimento de deploy incremental

Ferramenta: `ops/deploy-fiscal-simulation.sh`. Base esperada: `main` em `fc1c5fb`, sem mudanças no PDV. Stage isolado conhecido: `/srv/vendra-fiscal-sim-qa.qjaVNG`. A ferramenta é independente do deploy fiscal inicial; não usar o script antigo que aplica migrações000001–000004.

No modo padrão ela somente imprime o plano:

```sh
bash ops/deploy-fiscal-simulation.sh --plan
bash -n ops/deploy-fiscal-simulation.sh
```

### Manifestos e revisão

Fornecer três manifestos no formato `SHA256`, dois espaços, caminho relativo, sem comentários, duplicatas, symlinks ou caminhos fora da raiz:

- `overlay.sha256`: somente arquivos finais aprovados de simulação/rascunho; a mesma versão deve estar no stage e ter passado em QA. Testes, segredos, dados, `vendor` e Composer não entram no overlay.
- `baseline.sha256`: conteúdo anterior dos arquivos já existentes do overlay, correspondente ao `main` efetivamente publicado. Comparar ao código revisado da base; se houver diferença de formatação na VPS, revisar essa diferença em vez de aceitar automaticamente um hash novo.
- `pdv.sha256`: reutilizar a relação protegida já conferida em `/root/vendra-fiscal-review/pdv.sha256` (101 arquivos). O script exige que tanto stage quanto produção permaneçam iguais a essa relação e recusa caminho protegido dentro do overlay.

O digest do próprio `overlay.sha256` precisa ser conferido pelo revisor e informado à ferramenta. Qualquer modificação depois de QA requer atualizar o manifesto e revalidar o trecho afetado. A comparação de arquivos não substitui regressões comportamentais nos pontos compartilhados, como rotas e `BusinessController`.

`composer.json` e `composer.lock` devem ser idênticos no stage e em produção. Não há dependência nova nesta etapa: o script não executa `composer install`, não altera PHP/ini/SO nem toca unidades ou configurações Apache. Se dependências mudarem, ele para e exige outro plano.

Exemplo de execução, somente após aprovação real do responsável e QA:

```sh
VENDRA_FISCAL_SIMULATION_DEPLOY_APPROVED=1 VENDRA_FISCAL_SIMULATION_QA_PASSED=1 \
bash /srv/vendra-fiscal-sim-qa.qjaVNG/ops/deploy-fiscal-simulation.sh --apply \
  --stage /srv/vendra-fiscal-sim-qa.qjaVNG \
  --manifest /root/vendra-fiscal-simulation/overlay.sha256 \
  --baseline /root/vendra-fiscal-simulation/baseline.sha256 \
  --pdv /root/vendra-fiscal-review/pdv.sha256 \
  --manifest-sha256 DIGEST_REVISADO_DE_64_HEXADECIMAIS
```

Essas variáveis registram uma decisão do operador; não substituem revisão, autorização ou evidência de QA.

### Ordem de operação e serviços

1. Conferir origem, hash do manifesto, conteúdo do overlay, baseline, arquivos PDV protegidos e identidade do PHP utilizado pelo worker. Lintar PHP novo sem executar a aplicação no stage.
2. Registrar se `vendra-fiscal.timer` estava ativo e qual era o estado do serviço oneshot. Parar apenas o timer para impedir novos lotes.
3. Esperar o worker já iniciado ficar inativo naturalmente por até60 segundos. Não executar `systemctl stop vendra-fiscal.service`, não matar SOAP em voo nem repetir o lote para “restaurar” seu estado. Se não drenar, abortar antes de copiar qualquer código.
4. Parar somente `vendra.service`, mantendo Apache80/443/8443/8444 e MySQL ativos. Não executar outros comandos de escrita/provisionamento Vendra durante a janela.
5. Com HTTP e worker pausados, conferir por leitura a `fiscal_outbox` real de cada base retida. Qualquer operação incompleta, inclusive futura/incerta, bloqueia a atualização. Não apagar nem concluir filas para passar esse teste.
6. Fazer backup local da aplicação, APP_KEY e bases pelo procedimento existente, com journal privado em `/var/lib/vendra/fiscal-simulation-deploy`. Não enviar esse material ao Git/chat. Cópia local não substitui retenção externa cifrada ou ensaio de restauração.
7. Instalar apenas os arquivos do overlay como `root:vendra-web`/640 e seus diretórios como750. Não normalizar recursivamente toda a aplicação/vendor/storage; preservar modos dos arquivos privados fiscais e os demais sistemas.
8. Limpar cache de configuração/rotas/views e aplicar **somente**:
   - `2026_11_12_000005_create_internal_fiscal_simulations.php`;
   - `2026_11_12_000006_add_fiscal_configuration_draft.php`.
9. Não executar migração central, migrações anteriores ou `vendra:migrate-tenants` genérico. Bases retidas inativas também recebem os dois acréscimos por contexto de manutenção em memória, sem modificar `active` nem outros campos da empresa no central.
10. As operações da aplicação usam `vendra-web` e o ini CLI próprio, sem executar app/cache PHP como root. Compilar Blade e lintar os PHP gerados; `view:cache` sozinho não garante sintaxe válida. Normalizar somente os caches gerados.
11. Conferir novamente os hashes PDV e a sintaxe do Apache Vendra. Iniciar só Vendra e aguardar login HTTPS200 com repetição limitada (até10 tentativas, validação TLS mantida). Isso evita tratar o primeiro instante de startup como falha definitiva.
12. Só depois de HTTPS pronto restaurar o estado ativo anterior do timer. Não alterar seu estado enabled/disabled persistente e não chamar o worker manualmente. Um lote já drenado terminou; “restaurar” não significa repeti-lo. Se o timer antes estava parado, ele permanece parado.

A retomada do timer restaura o agendamento real já existente. Quando existir no futuro uma intenção oficial deliberadamente enfileirada com requisitos válidos, esse agendamento continuará podendo processá-la; a simulação não cria tal intenção. A ferramenta de deploy não faz emissão, seed, upload PFX, atualização de empresa ou teste oficial de rede.

### Falha e recuperação

- Antes de iniciar o overlay: o script tenta devolver Vendra e o timer aos estados anteriores; se Vendra precisou reiniciar, aguarda HTTPS antes de retomar o timer. Conferir o estado efetivo após qualquer erro.
- Depois de iniciar a cópia ou as migrações: mantém somente Vendra e seu timer pausados. Não tenta rollback automático, não apaga tabelas/eventos, não renumera e não sobrescreve a base com backup sem revisão.
- MySQL DDL não é transacional; as novas migrações recusam reversão destrutiva. Uma falha parcial exige conferir journal, backup e quais bancos foram atualizados antes de corrigir/retomar.
- A política de recuperação precisa preservar dados fiscais reais existentes, XML/protocolo/outbox e APP_KEY, mesmo que esta etapa só crie exercícios internos.

## Validação após publicação

Conferir usuário administrador real e usuário sem permissão: lista de exercícios, criação a partir de venda concluída, consulta/cancelamento internos, recibo marcado, rascunho incompleto e mensagens de bloqueio oficial. Comparar contagens/valores comerciais e fila/numeração reais antes/depois. Não usar uma nota fictícia, XML assinado de fixture, protocolo inventado ou certificado autogerado na empresa operacional.

`systemctl is-active vendra.service`, HTTPS200 e timer ativo provam disponibilidade de infraestrutura, não integridade funcional completa nem emissão fiscal. Registrar separadamente regressões, dados de teste e qualquer pendência verdadeira.

## Evidência de validação

- Unitários: 24 casos e 50 verificações aprovados.
- FiscalTest ampliado: 33 casos executados; 32 passaram e um encontrou metadados obrigatórios ausentes em uma fixture de credencial. A fixture foi corrigida sem alterar as regras do produto; o reteste direcionado do rascunho passou com 1 caso e 16 verificações.
- Os novos cenários verificam aceite/rejeição/timeout, consulta e cancelamento, histórico e integridade, UUID/replay, isolamento, permissões, JSON/cupom, ausência de transporte e preservação de venda/estoque/financeiro/caixa/documentos/outbox/sequências reais.
- Bash/Python embutido e modo de plano da ferramenta revisados. A execução efetiva de implantação registra hashes, backup e estados no journal privado.

As contagens acima correspondem a execuções distintas. Simulação interna não é homologação oficial: nenhum certificado ou protocolo fictício foi cadastrado na empresa para passar nos testes e nenhum teste dessa etapa transmitiu dados à SEFAZ.
