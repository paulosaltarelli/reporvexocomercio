# Precificação, tipos de item e estoque

## Situação da entrega

Regras lidas no código de `PricingService`, `Product`, `Inventory`, `PurchaseService`, `Checkout` e controllers de cadastro/preços. **Implementado aguardando QA final**: política automático/alerta, catálogo unificado, custo de referência e identidade da empresa. A evidência anterior de 42 testes/660 verificações, incluindo caixa/demo, não comprova esses incrementos. A suíte de 49 testes está preparada e pendente de confirmação pelo QA.

## Um cadastro, três situações operacionais

| Cadastro | Controla saldo? | Custo utilizado para sugestão e custo da venda |
|---|---|---|
| Produto físico com controle | Sim | Custo médio ponderado do estoque (`average_cost`). Entrada recalcula a média; saída preserva a média. |
| Produto físico sem controle | Não; permanece em zero | Custo de referência (`reference_cost`), informado no cadastro ou atualizado pela compra. Não se calcula média de um estoque que não existe. |
| Serviço | Não; o tipo força controle desativado e saldo zero | Custo de referência, por exemplo material/mão de obra alocados à unidade do serviço. Não representa apuração automática de horas/despesas. |

A compra permite produto físico, controlado ou não. No controlado gera movimento/custo médio; no não controlado atualiza o custo de referência, sem saldo fictício. Serviço é vendido pelo catálogo unificado e não recebe movimentação manual de estoque.

## Margem e preço sugerido

A margem é sobre a receita de venda, não acréscimo sobre custo:

`preço sugerido = custo base ÷ (1 − margem/100)`

Custo R$ 60 e margem 40% sugerem R$ 100, não R$ 84. Margem válida: 0 a 99,99%. O produto pode herdar a margem do grupo (`null`) ou ter margem própria; 0% é uma margem própria válida, não significa herdar.

A sugestão usa decimais com arredondamento para centavos; custo base possui quatro casas. Não inclui automaticamente impostos, taxas de cartão, frete, despesas fixas ou comissão. A composição do custo efetivo é responsabilidade do operador nesta versão.

## Política de atualização independente da margem

O grupo define `alert` ou `automatic`, com padrão inicial de alerta. O produto pode herdar a política do grupo ou escolher outra. A herança de política e a herança de margem são independentes: um produto pode herdar a margem e usar automático próprio, ou ter margem própria e herdar alerta do grupo.

| Política efetiva | Quando custo/margem/configuração muda |
|---|---|
| Alerta | Mantém o preço de venda existente. Atualiza a revisão e marca atenção quando a sugestão, arredondada em centavos, difere do preço em **qualquer direção**. Não é somente aviso de preço abaixo do custo. |
| Automático | Com custo positivo e cálculo dentro do limite, grava a sugestão como novo preço, registrando antes/depois e origem. Pode elevar ou reduzir o preço; não é uma regra “só aumentar”. |

Eventos observados: salvar cadastro, alterar grupo, movimentar estoque, receber compra, atualizar serviço legado e conferência administrativa. Não há sincronização por alteração direta de SQL fora desses caminhos de domínio.

### Sem custo e valores fora de limite

- Custo zero produz estado `no_cost`, sem sugestão. Automático **não zera** o preço já registrado; a aplicação manual da sugestão é rejeitada.
- Preço calculado acima do limite monetário produz `overflow`. No modo alerta, fica pendente; no automático, a operação é rejeitada e sua transação deve reverter integralmente.
- O preço de venda continua explícito. No modo alerta, o operador administrador pode mantê-lo diferente da sugestão; o alerta continua representando essa divergência.
- A sugestão não garante margem líquida real: custos não incluídos na base não passam a ser cobertos automaticamente.

## Revisão e rastreabilidade

“Preços e alertas” é área administrativa. Aplicar uma sugestão exige chave de operação e `expected_price`: o servidor recalcula com o estado atual e recusa se a sugestão mudou desde a revisão. O administrador precisa atualizar a página e conferir o novo valor; não deve aplicar uma sugestão antiga silenciosamente.

A aplicação registra usuário, preço anterior/posterior, custo e fonte de custo, margem, política e origem. Repetir a mesma operação não deve criar outro reajuste. Alterar o preço atual **não reescreve** preço/custo dos itens de venda, compras ou documentos históricos.

## Troca de tipo e controle

- Trocar tipo ou desativar controle exige saldo zero; o saldo deve ser zerado por movimentações identificadas, sem apagar o histórico.
- Serviço nunca mantém saldo. Ativar/desativar o cadastro não transforma um serviço em mercadoria estocada.
- Cancelamento olha se a venda original gerou movimento de estoque. Um item vendido sem controle não recebe entrada fictícia no cancelamento.
- Se uma venda originalmente baixou saldo e o controle foi desativado depois, o sistema exige reativá-lo antes de devolver esse estoque. Não deve substituir o fato histórico pelo tipo atual do cadastro.
- A migração importa serviços antigos para o catálogo, mantém vínculo `legacy_service_id` e preserva `SaleItems` antigos. Os serviços importados ficam em alerta explícito; a migração não liga reajuste automático sem decisão do administrador.
- A unificação não oferece rollback destrutivo; atualização corretiva ou restauração verificada deve preservar os itens já vendidos.

## Critérios finais de QA

1. Custo 60/margem40 sugere100; margem própria0 prevalece sobre grupo; heranças são independentes.
2. Alerta detecta sugestão maior ou menor sem alterar preço; automático aplica os dois sentidos e mantém histórico.
3. Custo zero não apaga preço; overflow automático reverte a operação inteira, inclusive compra/estoque relacionados.
4. Aplicação é exclusiva do admin, detecta `expected_price` desatualizado e não duplica em replay/concor­rência.
5. Produto controlado usa média; produto sem controle e serviço usam referência. Nenhuma venda sem controle baixa saldo.
6. Troca com saldo não zero é rejeitada; cancelamento respeita movimento histórico e não cria retorno de serviço.
7. Importação de serviço legado preserva descrições, valores e referências antigas. Novo catálogo não reescreve `SaleItems`.
8. HTML de operador não contém custos, margens, resultado financeiro ou controles de reajuste; chamadas administrativas também devem ser bloqueadas no servidor.

Não há novo módulo proposto nesta documentação. Após confirmar esses critérios e os testes de integração, registrar a evidência executada e disponibilizar o acesso ao proprietário.
