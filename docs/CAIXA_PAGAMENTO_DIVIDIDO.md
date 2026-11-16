# Caixa, pagamento dividido e recepção de OS

## Operação

A OS pode ser aberta sem produto ou serviço. Registre equipamento, relato, cliente e responsável; edite a OS aberta para adicionar diagnóstico, serviços e peças. Aprovação exige itens, e faturamento exige conclusão. Não há estoque, reserva, conta ou comissão na recepção vazia.

No caixa, “Receber orçamento aprovado ou OS concluída” localiza documentos por número ou lista recente. O botão abre o documento na composição do pagamento. Confirmação usa os itens, preços e vendedor salvos, gera uma venda e baixa estoque uma única vez. Reenvios retornam a venda já vinculada.

Pagamento único continua disponível. Em “Dividir pagamento / entrada e saldo”, ative a composição e informe de uma a dez partes. Cada parte tem forma cadastrada, valor e recebimento imediato ou futuro. Nas futuras, informe primeiro vencimento e parcelas mensais. Limite de 24 títulos no conjunto; os centavos residuais ficam na última parcela de cada parte. A soma deve ser exatamente o total comercial. Dinheiro entregue e troco referem-se somente à parte em dinheiro; o ledger registra o valor aplicado, não o numerário bruto entregue.

A forma é resolvida no servidor; nome, tipo e condições ficam em snapshot imutável. Cada título é vinculado à venda. Quitação só ocorre quando todos os títulos estiverem pagos. Comissão por caixa considera apenas baixas reais, proporcionalmente. Cancelamento reembolsa por cada meio original, preservando histórico. O comprovante mostra condições na venda; recebimentos posteriores ficam no financeiro. O painel distribui cada parte no meio correspondente sem duplicar o valor total vendido.

O recebimento integral de saldo composto exige escolher o meio efetivamente recebido; não assume a primeira forma da venda. O financeiro permite baixas parciais por título.

## Conferência

Permissão cash.manage libera abertura, sangria e suprimento; cash.close continua independente. A abertura do dia é única, identifica o usuário e preserva a continuidade pelo valor contado anterior. A conferência é diária por loja, com detalhamento das baixas por responsável; não representa múltiplas gavetas/sessões simultâneas por operador.

Sangrias e suprimentos exigem abertura, valor e motivo. Usam contas e pagamentos financeiros, UUIDs e bloqueio do dia. Sangria não ultrapassa dinheiro disponível. Se a gravação falhar, conta e pagamento revertem juntos. Fechamento continua imutável e cronológico. Além do dinheiro físico, podem ser informados valores líquidos conferidos em Pix, cartão, transferência e outros, com diferenças preservadas no snapshot. Campo em branco significa não conferido; não equivale a zero.

Não há integração com banco ou maquininha, conciliação automática ou confirmação externa. Na emissão fiscal suportada, pagamentos compostos comprovados em dinheiro/Pix/transferência geram grupos distintos com valores reconciliados. Cartões e outras condições fiscais continuam sujeitos aos fluxos e dados de integração próprios já existentes. Nenhuma emissão real é usada em QA.

## Publicação

Não requer migrations nem mudanças nas views SQL compartilhadas. Planos e aberturas usam registros tipados da tabela tenant existente company_operation_audits. Títulos e baixas usam financial_entries e payments. Alterações são publicadas com lock fiscal compartilhado, pausa e drenagem do serviço, backup consistente verificado, overlay restrito e leitura efetiva como vendra-web. QA usa exclusivamente bases dedicadas; não criar vendas ou movimentos financeiros reais para testar em produção.
