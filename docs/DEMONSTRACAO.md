# Demonstração de informática

Comando: `php artisan vendra:demo vexo --confirm-demo`.

Carga aditiva e idempotente na empresa indicada. Não muda senhas nem apaga cadastros. A primeira execução guarda a data-âncora em demo_runs. Repetir uma carga concluída retorna o resumo sem acrescentar registros. Se a empresa já contém vendas, compras, contas, pagamentos ou fechamentos, a primeira carga é recusada para evitar misturar operação real com demonstração. Cadastros simples preexistentes são preservados.

Inclui produtos/SKUs DEMO, grupos e margens, serviços, clientes/fornecedores fictícios, compras com custo médio e contas, vendas mistas/parceladas, baixas parciais, contas operacionais, cancelamento integral com reembolso, orçamentos/OS e nove fechamentos históricos. Os e-mails de clientes usam example.test; não há documentos fiscais ou contatos reais. O dia atual permanece aberto.

Os eventos de estoque são processados em ordem cronológica. Datas históricas são sintéticas para demonstrar relatórios; valores não representam a empresa Vexo. Primeiro caixa histórico inicia com R$500; há uma diferença de R$10 registrada em uma contagem, transportada à abertura seguinte.

Fluxo financeiro usa datas de baixa e todas as formas de pagamento. Dinheiro físico do caixa usa somente method cash. Previsão usa saldos de títulos ainda abertos; vencidos são exibidos no primeiro dia projetado, com aviso explícito. Não há conciliação ou movimentação bancária automática.
