# Comissões, fechamentos e formas de pagamento

## Fluxo do gestor

Em Comissões da equipe, escolha o período e o vendedor, confira e confirme Fechar e gerar conta a pagar. O fechamento preserva as linhas e cria uma única obrigação em Contas a pagar, categoria Comissões de vendas. Criar a conta não significa pagar. Registre a baixa parcial ou integral somente depois de pagar; ela também pode ser feita pelo financeiro e será refletida no extrato do vendedor.

O período padrão é o mês atual, com atalhos para mês anterior e últimos 30 dias. A apuração por data da venda continua disponível. O fechamento usa o corte final e inclui diferenças ainda não fechadas de vendas anteriores. Uma venda de outubro recebida em novembro pode liberar comissão em novembro sem refazer outubro.

- Caixa: comissão proporcional aos pagamentos com data comprovada até o corte; cálculo acumulado reconcilia centavos em recebimentos parciais.
- Competência: comissão integral registrada na venda.
- Valores reconhecidos em fechamentos anteriores são descontados. Períodos sobrepostos não reconhecem novamente a mesma comissão.
- Cancelamentos observados até o corte aparecem como ajuste negativo na próxima apuração, sem reescrever o fechamento anterior.
- Sem saldo líquido positivo, não é criada obrigação negativa. O ajuste continua pendente para compensação com novas comissões.
- Baixas legadas sem data comprovada exigem reconciliação. Não é inventada a data de recebimento.
- Não são aceitos cortes futuros ou anteriores ao último corte do vendedor.
- Pagamento não ultrapassa o saldo; dias financeiros fechados seguem o bloqueio do caixa.

Uma prévia desatualizada exige nova conferência. UUIDs tornam confirmações repetidas idempotentes. Fechamentos concorrentes usam mutex por empresa e leituras atuais de vendas, contas, pagamentos e registros consolidados, sem depender de snapshot antigo de REPEATABLE READ. O ledger é lido depois dos bloqueios das vendas.

## Acesso do vendedor

Vincule o login existente ao cadastro de Vendedores. Usuário ativo com esse vínculo recebe Minhas comissões, inclusive para histórico de vendedor inativo. Não é criado outro login; vendedores sem acesso não ganham autenticação.

Consulta e detalhes são filtrados no servidor pelo vendedor vinculado ao usuário. IDs enviados por URL não ampliam o escopo. O vendedor consulta e imprime, sem fechar ou pagar. Gestão da equipe exige commissions.view; fechamento exige commissions.close; pagamento exige commissions.pay e dependências financeiras. Não conceda permissões gerenciais ao perfil que deve consultar apenas o próprio extrato.

## Formas de pagamento

Administradores mantêm nome, tipo e disponibilidade para novas operações. Pode-se cadastrar Pix Sicoob e Pix de outra conta, ambos do tipo Pix. Os seis tipos internos permanecem dinheiro, Pix, crédito, débito, transferência e boleto/a prazo, preservando classificação fiscal e caixa.

O servidor resolve a opção; não confia no tipo enviado pelo navegador. Nome e tipo usados ficam em snapshots imutáveis. Renomear/desativar não reclassifica o histórico. Desativar bloqueia novas operações, sem excluir contas ou pagamentos.

O painel mostra vendas concluídas por forma escolhida e origem (PDV, orçamento aprovado, OS) no ano selecionado. Vendas a prazo não significam recebimento. O cadastro não integra banco/maquininha nem calcula taxas e prazos de liquidação automaticamente.

## Persistência e operação

Sem migrações ou alteração do manifesto SQL compartilhado. Opções usam a chave reservada _payment_methods em company_operations, preservada ao editar módulos e perfis. Classificações e fechamentos usam registros tipados append-only na tabela tenant existente company_operation_audits. Fechamento guarda vendedor, corte, composição e referência da obrigação; pagamento permanece no ledger financeiro existente. Não há rota de edição ou exclusão do fechamento.

A versão protege edições concorrentes do cadastro. Eventos financeiros não alteram módulos ou implantação. A tela de implantação filtra eventos de configuração.

Publicar com lock compartilhado, backup consistente, overlay restrito e leitura efetiva como vendra-web. Renderizar novas subpastas de views antes de reabrir. Não criar fechamentos ou pagamentos reais na demo para testar. Após reabrir, não reverter cegamente a autenticação; preservar o controle de acesso e reparar a falha.
