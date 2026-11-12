# Importação anual controlada

Esta carga é **sintética**, destinada à base de informática preparada para avaliação do produto. A proveniência fica em `demo_runs.summary` (`synthetic`, versão, janela, IDs adicionados, data da importação e digest dos fechamentos preservados). Os textos operacionais são neutros; não representam documentos fiscais reais.

## Execução

```bash
php artisan vendra:historico-anual vexo 2026 --plan
```

Antes de executar: backup verificado, aplicação Vendra em manutenção, nenhuma gravação concorrente, teste em bancos exclusivos concluído. Não reiniciar nem interromper os outros sistemas da VPS.

```bash
php artisan down
php artisan vendra:historico-anual vexo 2026 --confirm-history --maintenance-confirm
php artisan up
```

O comando não executa manutenção, backup ou reinicialização por conta própria. O serviço exige console, administrador ativo e manutenção; em `APP_ENV=testing` dispensa o arquivo global de manutenção para testes isolados.

## Limites de segurança

- Exige `computer-store-v1` concluído. Não aceita uma empresa arbitrária sem a base previamente preparada.
- Importa de 1º de janeiro até o dia imediatamente anterior ao **primeiro** fechamento existente, limitado ao ano e ao dia atual.
- Rejeita se houver qualquer `Payment` no intervalo reservado. Não reescreve, apaga ou desloca pagamentos, fechamentos, vendas, compras ou estoque anteriores.
- Usa transação única, bloqueio da carga original e mutex exclusivo do calendário financeiro. Nunca muda o comportamento de `CashService` ou dos endpoints normais.
- A inclusão de pagamentos/fechamentos históricos ocorre somente no importador privado, com valores, datas, saldos e relações validados. Não existe opção pública para ignorar caixa fechado.
- Confere digest integral dos fechamentos existentes antes/depois. Ao falhar qualquer invariante, desfaz a carga inteira.
- Chaves determinísticas por ano e execução concluída tornam a repetição idempotente. Execução parcial previamente registrada exige diagnóstico, sem limpeza automática.

## Estoque e financeiro

A carga cria cinco SKUs próprios e dois serviços, identificados internamente por `VXH-ANO-*`. Nunca lança movimentos retroativos em SKUs operacionais existentes. Cada mês recebe compras suficientes para duas vendas por dia útil; as quantidades adquiridas são exatamente vendidas no mesmo ciclo. O saldo final de cada SKU histórico é zero. Esses itens ficam inativos ao final para não poluir a operação e os alertas de reposição.

Compras, vendas e custos usam os serviços normais em ordem cronológica por SKU. A importação registra a posteriori os recebimentos/pagamentos comprovados pelo roteiro sintético **somente** no intervalo anterior ao primeiro fechamento. Parcelas posteriores ficam abertas, inclusive as que venceram em períodos já fechados: nenhuma baixa é inventada nesses dias.

Os caixas históricos são diários. Sangrias semanais identificadas como `Transferência interna` geram saída em dinheiro e entrada bancária equivalente. Não há ajuste oculto de receita/despesa; o efeito financeiro líquido da transferência é zero. O saldo contado final liga exatamente à abertura do primeiro fechamento que já existia. Os snapshots desse período anterior permanecem imutáveis.

Do restante do ano são criadas apenas contas explicitamente descritas como previsões, com vencimento futuro e sem `Payment`. Não são criadas vendas futuras concluídas. Previsões manuais devem ser revisadas quando substituídas por movimentos efetivos; não constituem faturamento fiscal ou recebimento realizado.

## Após a carga

Conferir totais das vendas/itens/custos, parcelas versus ledger, todas as aberturas/contagens, caixa por meio e datas, saldo/custo do último movimento de cada SKU e ausência de pagamentos futuros. O normalizador de rótulos da carga inicial pode ser executado separadamente; não deve alterar valores nem fechamentos.
