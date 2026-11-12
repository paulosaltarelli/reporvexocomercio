# Caixa diário

Cada empresa possui um único caixa diário, em reais. Não há gavetas, turnos ou caixas separados por operador nesta versão.

## Cálculo

O resumo usa exclusivamente `Payment.paid_on`, incluindo pagamentos e estornos pelas suas direções `in` e `out`.

- Dinheiro físico esperado = abertura + entradas em `cash` − saídas em `cash`.
- Pix, cartões, transferência e demais meios aparecem separados; não aumentam o numerário físico.
- Diferença = valor contado − valor esperado. Pode ser negativa.
- Saldos legados sem evento de pagamento e data comprovada não são inventados como movimento de caixa.
- A primeira abertura é informada pelo administrador. As seguintes devem coincidir com o valor contado no fechamento anterior, inclusive quando houve diferença de contagem.

## Consolidação

Fechamentos seguem ordem cronológica e não podem ter data futura. É permitido pular dias sem movimentos; qualquer dia anterior com `Payment` precisa ser fechado primeiro. Após fechar uma data, novas baixas em datas iguais ou anteriores ao último fechamento ficam bloqueadas, inclusive lacunas entre fechamentos.

O fechamento salva valores, resumo por meio de pagamento, usuário, horário e observações. O snapshot não é recalculado ao consultar o histórico. Não há reabertura nem exclusão de fechamento; a correção deve ser identificada em uma data ainda aberta.

Repetir a mesma chave de fechamento com os mesmos dados devolve o registro existente. Reutilizar a chave para outra operação é rejeitado. Uma baixa já registrada pode ser consultada novamente por sua chave após fechar o dia; isso não cria um novo pagamento.

Uma venda ou compra pendente pode existir sem movimentação de caixa. A tentativa de concluir uma operação com baixa imediata em dia consolidado é rejeitada e a transação inteira é desfeita.

Estornos financeiros são lançados na data atual e exigem que ela esteja aberta. O cancelamento sem recebimentos não cria pagamento e não precisa de dia financeiro aberto. Nesta versão o estorno usa o mesmo meio do recebimento original; a confirmação pressupõe reembolso externo realizado por esse mesmo meio. Não há integração automática de reembolso bancário.

## Concorrência e importações

A escrita de `Payment` passa por `FinancialService`, na mesma transação do documento e do estoque. A ordem é: documento/títulos → mutex compartilhado do calendário → bloqueio do dia → registro financeiro. Fechamento usa mutex exclusivo → dia → leitura atual do ledger. Assim, uma baixa concorrente pertence ao snapshot fechado ou é rejeitada após o fechamento.

`cash_closure_locks(id=1)` é um registro técnico obrigatório criado pela migração. Não o apague em manutenção ou em fixtures.

As proteções valem para os serviços da aplicação. SQL direto, importações ou seeds que escrevam `Payment` sem o serviço contornam o protocolo e não devem operar depois de consolidar datas. O modelo `CashClosure` também rejeita alterações e exclusões via Eloquent; isso não é um bloqueio contra um administrador MySQL com privilégios de escrita direta.
