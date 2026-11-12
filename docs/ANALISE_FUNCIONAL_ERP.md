# Análise funcional de ERP — Vendra por Vexo

Data: 12/11/2026. Revisão estática do código e da documentação; não executei testes nem alterei a VPS. A evidência executada disponível na leitura foi a de `VALIDACAO.md` (19 testes, 79 verificações). A análise foi atualizada após leitura do pacote ampliado (compras, financeiro, contas de usuário, relatórios e cadastro da empresa). Esses recursos estão **implementados em validação**; seus testes finais e o deploy devem ser confirmados pelo QA/integrador. Não transfiro a evidência antiga de 19 testes para os recursos novos.

## Parecer e limite da primeira versão

O pacote ampliado permite homologar o fluxo de cadastrar grupos/produtos, receber compras com custo e títulos, calcular média, vender produtos/serviços, parcelar e baixar parcialmente, cancelar com retorno de estoque e registro de reembolso manual confirmado, e converter orçamentos/OS em vendas. Há também troca de senha, gestão de usuários, relatórios/CSV e dados preparatórios da empresa. O código desses incrementos foi lido; a liberação depende do QA final. Isso não equivale a todos os recursos de um ERP maduro nem à equivalência com os concorrentes pesquisados.

Preservar a decisão estrutural: uma aplicação e uma sequência de migrações compartilhadas, banco separado por empresa, diferenças por configuração e módulos. Equipamentos, veículos, grades e processos de cada segmento devem evoluir no produto comum; não criar versões ou tabelas particulares por cliente.

A homologação pode prosseguir sem emissão fiscal, integração bancária ou marketplace. Antes de uso real, o cliente precisará manter seu processo fiscal externo quando aplicável; campos preparatórios não autorizam tratar o Vendra como emissor fiscal.

## Mapa funcional observado

“Implementado” significa caminho encontrado no código; somente o relatório do QA demonstra o que foi efetivamente executado. “Parcial” exige operação manual ou cobre apenas parte do processo. “Ausente” não deve aparecer em proposta comercial como recurso entregue.

| Área | Estado atual | Evidência e limite |
|---|---|---|
| Empresas e isolamento | Implementado no núcleo; administração SaaS parcial | `ResolveTenant`, `TenantAuthenticated`, `TenantConnection`; bancos e usuários por empresa. Cadastro/suspensão de assinantes, planos e cobrança não têm painel web. |
| Usuários | Implementado em validação | `AccountController` permite senha própria, edição/desativação, redefinição por admin e proteção do último admin ativo; `auth_version` revoga sessões e `must_change_password` exige troca. Recuperação autônoma por e-mail não foi implementada. |
| Clientes e fornecedores | Implementado básico | Cadastro/edição de nome, documento, contatos e endereço livre. Não há validação de dígitos de CPF/CNPJ, histórico consolidado, limite de crédito ou endereço fiscal estruturado. |
| Grupos e margens | Implementado | Margem padrão do grupo, margem opcional do produto, sugestão baseada no custo médio. Margem é sobre venda, não markup; preço efetivo é editável e não é atualizado automaticamente ao mudar a margem. |
| Produtos | Implementado básico | SKU e código de barras únicos por empresa, unidade, grupo, preço, estoque mínimo e inativação. Sem matriz tamanho/cor, marca, fotos, kits, conversão de unidade ou múltiplas tabelas de preço. |
| Custo médio e saldo | Implementado | `Inventory::move`; média ponderada móvel nas entradas, saída pelo custo médio, saldo não negativo e histórico com usuário. Quantidade em 3 casas, custo médio em 4; não é controle de custo por lote/série. |
| Inventário | Parcial | Ajuste por diferença via entrada/saída manual com motivo. Não há sessão de contagem, comparação de saldo congelado, aprovação e fechamento de inventário. |
| Compras | Implementado em validação | `PurchaseService::receive` confirma fornecedor/itens, gera entrada e custo médio e cria parcelas a pagar na mesma transação. Custo unitário efetivo é informado pelo operador já com custos alocados; sem rateio automático, recebimento parcial, devolução ou edição após confirmar. A movimentação referencia compra/item no motivo e na chave; não há FK específica de compra no estoque. |
| Vendas | Implementado básico; ampliação financeira em validação | Venda mista, cliente opcional, preço por item e baixa transacional; `Checkout` gera parcelas e pagamentos imediatos quando declarado recebido. Sem PDV offline, TEF/POS, comissão ou emissão fiscal. |
| Descontos e política comercial | Parcial | Alterar preço do item permite desconto implícito. Não existe desconto identificado, motivo, teto por usuário, aprovação abaixo de custo ou comissão de vendedor. |
| Cancelamento e devolução | Cancelamento integral ampliado em validação; devolução parcial ausente | `Checkout::cancel` chama `FinancialService::cancelSale`: cancela títulos e exige confirmação de reembolso se houve recebimento; guarda reversão vinculada aos pagamentos e retorna produtos pelo custo histórico. O dinheiro não é transferido pelo sistema. Não há troca, devolução parcial ou crédito de cliente. |
| Orçamentos | Implementado básico | Documento sem movimentar estoque; aprovação manual; conversão em venda. Não há revisão/edição de documento salvo, versões, reserva de estoque, aprovação pelo cliente ou trava de validade expirada. |
| Ordens de serviço | Implementado básico | Etapas aberto → aprovado → execução → concluído → venda; campo livre para equipamento/veículo, técnico e diagnóstico. Sem histórico de etapas/atores, anexos, checklists, garantia, horas ou cadastro estruturado do bem. |
| Contas e parcelas | Implementado em validação | `FinancialService` cria 1–24 parcelas, preserva valor e vencimentos, registra baixas parciais com data/autor/método e impede exceder saldo. Compras e vendas possuem títulos vinculados. Sem conciliação bancária, contas bancárias completas, renegociação ou juros/multas automáticos. |
| Recebimento de vendas | Implementado em validação | O booleano de quitação passa a refletir títulos e baixas, que preservam valor/data/usuário/método. Migração marca saldos antigos como legados sem inventar datas de pagamento; cancelamento de legado inconsistente é bloqueado para reconciliação. |
| Indicadores e exportações | Implementado em validação | `ReportController` filtra vendas por data de venda, pagamentos por data financeira e títulos por vencimento; exporta estoque atual, movimentações, vendas, baixas e títulos com proteção contra fórmula de planilha. Estoque exportado é posição atual, não reconstrução histórica. Não é DRE ou relatório contábil completo. |
| Permissões | Parcial | Administrador gerencia cadastros, estoque e financeiro; operador consulta e vende. Custos, margens e resultado bruto não são renderizados ao operador no catálogo/estoque/dashboard; compras, financeiro e relatórios exigem administrador. Aprovar/cancelar documentos está disponível a qualquer usuário autenticado. |
| Fiscal | Dados preparatórios ampliados em validação; emissão ausente | `BusinessProfile` guarda emitente/endereço/regime/inscrições e código municipal; produto tem NCM/origem e serviço código/ISS. Não há validação fiscal completa, cálculo tributário, contrato com emissor ou ciclo de emissão/cancelamento de NF-e/NFC-e/NFS-e. |
| Operação do SaaS | Parcial | Serviço isolado e ferramentas operacionais propostas. Backup externo, restauração ensaiada, observabilidade, suporte e atualização controlada por empresa ainda precisam de evidência operacional. |

## Regras de aceitação do núcleo

Estes cenários devem compor a homologação; não são declaração de que cada um já foi executado.

| ID | Critério verificável |
|---|---|
| RF-01 Isolamento | Usuário com ID 1 na empresa A não acessa o ID 1 da empresa B por troca de URL/sessão. Empresa inexistente/inativa não reutiliza conexão anterior. Todas as consultas operacionais ficam no banco resolvido no servidor. |
| RF-02 Saldo inicial | Novo produto inicia com saldo/custo zero; alterar cadastro não modifica esses valores. Primeira entrada de 10 unidades a R$ 10 gera saldo 10 e média 10,0000. |
| RF-03 Média ponderada | Após RF-02, entrada de 20 a R$ 25 gera saldo 30 e média 20,0000. Saída de 5 preserva média e deixa saldo 25. |
| RF-04 Fração e arredondamento | Entrada 0,500 a R$ 10 mais 1,000 a R$ 20 resulta em 1,500 e média 16,6667. Valores negativos, escala excessiva e overflow são rejeitados sem saldo parcial. |
| RF-05 Precificação | Custo 60/margem 40% sugere 100. Campo de margem próprio vazio herda o grupo; margem própria 0% prevalece sobre o grupo. Alterar margem não altera venda histórica nem preço já gravado automaticamente. |
| RF-06 Venda | Venda de 2 unidades a 100, com custo histórico 60, gera total 200 e custo 120; estoque baixa 2. Serviço pode coexistir sem gerar movimento de estoque. |
| RF-07 Atomicidade | Se um item não tem saldo, nenhum item da venda deve baixar estoque e nenhum total/venda parcial deve permanecer. Duas requisições concorrentes com a mesma chave não podem duplicar a operação. |
| RF-08 Estorno | Cancelar RF-06 uma vez retorna 2 unidades pelo custo da venda; repetir não retorna novamente. Compra ocorrida entre venda e estorno entra na nova ponderação. Se há pagamentos, cancelamento exige confirmação manual de reembolso, preserva pagamentos/reversões e cancela o saldo; nenhuma transferência bancária é executada. |
| RF-09 Documento | Orçamento/OS não baixa estoque na criação/aprovação. Orçamento só gera venda após aprovação; OS só após conclusão. Conversão repetida referencia a mesma venda e não baixa duas vezes. |
| RF-10 Financeiro básico | Venda/compra pendente exige vencimento e gera títulos cuja soma é o total. Baixa parcial reduz só o saldo, não quita antecipadamente; repetição não duplica. Não somar venda recebida novamente como “outra receita”. Testar 100/3 = 33,33 + 33,33 + 33,34 e vencimentos 31/01 → 28/02 → 31/03. |
| RF-11 Acesso | Operador não altera produtos, grupos, estoque manual, usuários ou contas financeiras por chamada direta, mesmo com botão escondido. A edição/desativação/troca de senha deve revogar sessões anteriores; o último admin ativo não pode ser removido. O padrão atual restringe custos ao administrador; permissões configuráveis por função e aprovação de documentos continuam como evolução comercial. |
| RF-12 Transparência | Telas e comprovantes não chamam receita de caixa, resultado bruto de lucro líquido ou comprovante de nota fiscal. Não anunciar cobrança automática, conciliação ou emissão onde o registro é manual. |

## Riscos e prioridades

P0 significa requisito de liberação no contexto indicado; não significa adicionar todos os módulos agora. P1 é a próxima entrega comercial, P2 é evolução condicionada ao público contratado.

### P0-H — para encerrar esta homologação

- Consolidar e executar regressões das correções DBA/UX/DevOps, inclusive concorrência real, isolamento de sessão, limites decimais e renderização/JS de linhas de orçamento/OS. Tratar um teste preparado como pendente até execução.
- Exibir claramente que compras, ajustes, baixas financeiras e aprovações são manuais e que cancelamento não reembolsa dinheiro. Essas limitações já aparecem em parte das telas; conferir o fluxo completo.
- Homologar com empresa de teste e dados fictícios. Nenhuma das integrações deliberadamente adiadas bloqueia esse ciclo.

Não encontrei, por leitura estática, razão para impedir a homologação do núcleo simples se essas verificações passarem. Isso não é autorização para migração de uma operação real sem conferência de saldos.

### P0-C — antes de comercializar para terceiros

As lacunas de recebimento, reembolso e lifecycle identificadas na primeira leitura foram endereçadas em código pelo pacote ampliado. Elas deixam de ser “ausentes” e passam a **portas de qualidade da homologação**: teste de histórico financeiro, atomicidade, baixa parcial, confirmação de reembolso, revogação de sessão e último administrador. Não exigem outro módulo nesta rodada.

| Risco comercial restante | Resultado esperado antes de clientes pagos |
|---|---|
| Recuperação de dados ainda não comprovada | Backup externo protegido, retenção, alertas e restauração em ambiente separado, conciliando saldos, documentos, APP_KEY e credenciais. Script local de dump não comprova recuperação. |
| Processo de operação SaaS ainda assistido | Provisionar, suspender, migrar e recuperar uma empresa por procedimento ensaiado sem afetar as demais. Um painel automatizado não é requisito para piloto assistido; procedimento recuperável e responsável definido são. |
| Matriz de permissões pouco granular | Aprovar explicitamente quem vê custo/resultado, concede descontos e aprova documentos. A versão tem dois perfis; não pressupor que isso atende qualquer equipe de loja. |
| Registros legados sem evidência financeira | Conferir saldos marcados como legados; não fabricar data de caixa para baixa antiga. Cancelamento bloqueado até reconciliação é comportamento seguro, não indisponibilidade a ser contornada. |
| Escopo vendido maior que o entregue | Proposta/onboarding devem distinguir compras confirmadas simples, pagamentos manuais e homologação de integração bancária/fiscal, devolução parcial ou PDV offline. |

### P1 — evolução posterior, sem ampliar esta rodada

Ficam para planejamento após a homologação e feedback real: devolução/troca parcial, política de preço mínimo/desconto e aprovação, revisão/versionamento de orçamento/OS, recebimento parcial/devolução de compra e conciliação financeira. São melhorias relevantes, mas não devem impedir a entrega do acesso inicial ao proprietário quando o QA do pacote atual passar.

### P2 — conforme segmento e estratégia

- Grade tamanho/cor, matriz de variações e troca de roupas; cada variação como SKU do mesmo produto lógico.
- Série, garantia e histórico de equipamentos em informática; ordens recorrentes e anexos.
- Veículo, placa, quilometragem, checklist, peças e horas no centro automotivo.
- Depósitos/filiais, transferências, inventário formal, lotes/validade e kits, quando houver demanda contratada.
- Integrações bancárias, contábeis, marketplaces e automação de cobrança; cada uma com requisitos e ciclo de falha/reprocessamento próprios.
- Fiscal em etapa separada: especificar emitente/tomador/prestador, regras por município/provedor e contrato da integração antes de implementar NFS-e. NF-e/NFC-e exigem escopo próprio. A prioridade pode mudar para P0-C de um cliente que dependa dessas emissões dentro do Vendra; hoje elas foram expressamente adiadas.

## Jornadas de homologação por segmento

São nove roteiros propostos, três para cada segmento. “Atual” indica o caminho disponível; “lacuna” deve ser demonstrada sem simular o recurso ausente.

### Loja de informática

1. **Comprar e revender um periférico.** Cadastrar fornecedor e grupo → produto com SKU → entrada de duas compras a custos distintos → conferir média → aplicar margem → vender a consumidor final. Atual em validação: compra gera entrada/custo e conta a pagar vinculada. Conferir saldo, média, custo histórico e parcelas; custo efetivo deve ser informado sem duplicar despesas.
2. **Vender peça com instalação.** Cadastrar cliente, produto e serviço → venda mista → verificar baixa apenas da peça → confirmar recebimento ou baixa parcial em título. Atual ampliado em validação; lacuna: serial e garantia não acompanham a peça. Conferir que serviço não gera saldo e seu custo entra no resultado bruto.
3. **Receber equipamento para reparo.** Criar OS com descrição/série no campo livre → registrar aprovação → executar → concluir → gerar venda → imprimir comprovante interno. Atual: etapas básicas; lacuna: termo de recebimento, anexos, checklist, garantia e histórico estruturado do equipamento.

### Loja de roupas

1. **Precificar coleção.** Criar grupo com margem → cadastrar tamanhos/cores como SKUs independentes → entradas por SKU → conferir média e sugestão → alterar margem própria em um item. Atual: funciona manualmente; lacuna: não existe grade/matriz nem herança de produto-pai. Verificar que trocar margem não reprifica estoque ou vendas sozinho.
2. **Vender duas peças.** Buscar SKU/código de barras → inserir itens e quantidades → informar preço final → concluir venda → conferir estoque. Atual: venda online básica; lacuna: leitor com fluxo de PDV rápido, desconto explícito e fechamento de caixa. Parcelamento passa a fazer parte do pacote em validação. Não chamar o processo de PDV offline ou integrado a maquininha.
3. **Trocar tamanho após recebimento.** Demonstrar cancelamento integral, confirmação manual do reembolso e retorno de saldo em ambiente fictício; não chamar isso de troca parcial. Lacuna: troca/devolução parcial e crédito de cliente continuam posteriores. Critério futuro: retornar só a peça devolvida, vender o novo tamanho e liquidar apenas a diferença, com vínculos de origem.

### Centro automotivo

1. **Orçar peças e mão de obra.** Cadastrar cliente, peças e serviços → orçamento misto → placa/modelo no campo livre → aprovação manual → gerar venda. Atual: orçamento converte em venda; lacuna: cadastro de veículo e aprovação do cliente por canal externo. Conferir que orçamento não reserva nem baixa peças.
2. **Executar manutenção.** Criar OS com placa, técnico e diagnóstico → aprovar → executar → concluir → gerar venda. Atual: peças baixam na venda; lacuna: não há requisição de peça durante execução, horas/checklist/quilometragem estruturados ou reserva. Antes de piloto, explicar esse momento de baixa à equipe da oficina.
3. **Receber a prazo e tratar retorno.** Gerar venda parcelada com vencimentos → registrar baixa parcial → completar recebimento → consultar OS/venda e histórico. Atual ampliado em validação: parcelas/baixas e reembolso manual vinculado; lacuna: retorno em garantia e reexecução. Critério futuro: preservar o histórico original, distinguir retrabalho sem cobrança de novo serviço e vincular eventual devolução de peça.

## Encaminhamento aos revisores

- **QA:** priorizar RF-01 a RF-12 no escopo atual; rastrear especificamente documento/venda/estoque e cancelamento após compra posterior. Separar cobertura executada de casos propostos.
- **DBA:** preservar custo histórico e trilha imutável; novos módulos financeiros/devoluções precisarão de lançamentos e vínculos próprios, sem reutilizar apenas booleanos de venda.
- **UX/UI:** indicar em que momento ocorre baixa, diferenciar margem de markup, separar recebido de faturado e evidenciar que documento não é nota fiscal. Não antecipar funcionalidades nas telas.
- **DevOps:** demonstrar restauração por empresa e pacote comum antes do primeiro cliente pago; manter dados de homologação separados dos reais.

Fontes de contexto: `PRODUTO.md`, `CONCORRENTES.md`, `VALIDACAO.md`; código observado em `CommerceController`, `OperationsController`, `Inventory`, `Checkout`, `Documents`, middlewares de empresa/acesso, migrations e views. A pesquisa competitiva é referência de necessidades; não comprova que todos os fornecedores incluam cada recurso em todo plano nem que o Vendra os tenha entregue.

## Pacote ampliado efetivamente lido — aguardando QA final

| Entrega | Fonte lida | Situação após revisão estática |
|---|---|---|
| Primeiro acesso e lifecycle | `AccountController`, `TenantAuthenticated`, migration `000004`, tela de senha | Implementado em validação: senha própria, edição/desativação, proteção do último admin, sessão versionada e troca obrigatória. O integrador deve testar a credencial inicial e orientar a troca; a senha não pertence a esta documentação. |
| Compra integrada | `PurchaseService`, `PurchaseController`, `Purchase/PurchaseItem`, migration `000003` e views de compra | Implementado em validação: recebimento integral, estoque/custo e títulos na mesma transação; custo unitário inclui alocações manuais; confirmação repetida não deve duplicar. |
| Títulos e baixas | `FinancialService`, `FinancialEntry`, `Payment`, migration `000003` e views de financeiro/vendas | Implementado em validação: parcelas exatas, datas mensais sem deriva, baixas parciais, ator/data/método, validação de saldo e plano vinculado a origem. |
| Cancelamento pago | `FinancialService::cancelSale`, `Checkout::cancel` | Implementado em validação: reembolso **manual confirmado**, reversão vinculada, cancelamento de pendências e retorno do estoque; legados inconsistentes são bloqueados. Não é automação bancária. |
| Relatórios e CSV | `ReportController`, rotas e `reports.blade.php` | Implementado em validação: período limitado, bases de data distintas e exportações protegidas contra fórmulas. A posição de estoque é atual mesmo quando o período limita as demais exportações. |
| Cadastro preparatório da empresa | `BusinessController`, `BusinessProfile`, migration `000005`, `business.blade.php` | Implementado em validação: emitente/endereço/regime/inscrições. Não equivale a validação fiscal ou emissão. |

### Portas finais de homologação do pacote

1. Confirmar que falha em qualquer item/título da compra ou venda reverte toda a operação; repetir confirmação não duplica estoque nem financeiro.
2. Conferir 100,00 em três parcelas e datas de mês curto, baixa parcial e concorrente, excesso de saldo e reuso de chave com dados divergentes.
3. Cancelar venda com recebimentos exige confirmação do reembolso e preserva pagamentos/reversões; cancelar novamente não duplica estoque ou reversão. Legado sem histórico verificável não deve ser cancelado silenciosamente.
4. Testar usuário inativo, alteração de perfil/senha e versão de sessão; impedir perda do último admin ativo; a primeira credencial precisa chegar ao proprietário e permitir troca segura.
5. Abrir relatório/CSV na empresa correta, conferir totais e bases de data, neutralização de fórmulas e conexão válida durante o streaming; conferir UI móvel e linhas dinâmicas de compra/venda/OS.
6. Registrar resultados executados e tratar falhas remanescentes. Não substituir isso por “código escrito” nem exigir nova expansão de módulos nesta rodada.

Se essas portas passarem, a entrega de homologação pode ser encerrada com acesso ao usuário, limitações claras e plano operacional separado para comercialização. Não recomendo abrir novos módulos agora. Grades completas, depósitos, TEF/POS, offline, integrações e emissão fiscal permanecem fora do pacote.

## Evidência após integração

A suíte integrada passou com 38 testes e 271 verificações; o navegador verificou primeiro acesso, troca de senha, 19 rotas, itens dinâmicos e menu móvel. Consulte VALIDACAO.md e TESTES.md para alcance e verificações adicionais. Isso valida o corte entregue nos casos executados, sem transformar lacunas comerciais em recursos disponíveis.
