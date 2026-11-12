# Testes

Última execução consolidada em 12/11/2026: **60 testes passaram, 956 verificações, nenhum teste pulado ou falha, saída 0, duração de 138,42 segundos**, em checkout QA isolado.

`php artisan test` executa cálculos decimais e, quando configurado, integração MySQL.
A suíte de integração recria EXCLUSIVAMENTE `vendra_test_central`, `vendra_t_test_a`, `vendra_t_test_b`.
Crie um usuário `vendra_test` com privilégios apenas nesses três bancos.
Configure `VENDRA_TEST_PASSWORD` em `.env.testing`, fora do Git. Nunca aponte os testes para bancos operacionais.
Sem essa variável, testes MySQL são explicitamente pulados e não validam isolamento nem estoque.
PHP precisa de bcmath, mbstring, xml, curl, pdo_mysql, fileinfo e zip.

Casos: margem sobre venda, arredondamento, média ponderada, frações, saída sem recalcular média, insuficiência com rollback, venda mista, estorno e repetição, bancos separados, sessão de outra empresa, login, telas e acesso de operador.

## Regressões da revisão especializada

Revisão DBA/UX/DevOps executada em 12/11/2026: **31 testes passaram, 166 verificações, nenhum teste pulado ou falha**, saída 0, duração de 237,85 segundos. As duas disputas concorrentes com `pcntl` executaram e passaram.
Cobrem:

- Estorno depois de nova compra conserva custo histórico e recompõe média ponderada.
- Venda com segundo item sem saldo desfaz também a baixa do primeiro item.
- Conexão de empresa inválida remove o contexto anterior antes de falhar.
- Reutilização de chave de estoque com outra quantidade é rejeitada.
- Escala excessiva, números negativos, notação exponencial e totais fora do schema são rejeitados no domínio sem gravação parcial.
- Custo fracionário da venda é arredondado ao final, sem truncamento por linha.
- Conversão de orçamento não colide com token de venda avulsa.
- Usuários com o mesmo ID em bancos distintos não compartilham sessão.
- Logout, usuário inativo e perfil operador respeitam barreiras de acesso.
- Validação HTTP exige margem válida e vencimento da venda a prazo.
- Dois processos/duas conexões MySQL disputam a mesma chave simultaneamente: só uma venda ou documento é criado. Esses casos exigem `pcntl`; ausência dessa extensão é reportada como teste pulado, não aprovado.

A suíte de HTTP do Laravel desabilita CSRF por padrão; portanto, aprovação nela não comprova a proteção CSRF real. Validar também uma requisição POST sem token no navegador/cliente real, esperando 419 e nenhuma gravação.

## Verificação HTTP real

Após confirmar GET do login com HTTP 200, um único POST sem token CSRF retornou HTTP 419. Foram usados dados fictícios, sem recuperar senha real. A conexão validou cadeia pública TLS e identidade do IP. Essa verificação é separada da suíte Laravel, que não ativa CSRF por padrão.

## Ampliação em preparação

Compras, parcelas, pagamentos parciais, histórico de reembolso, primeira senha e relatórios CSV foram validados após integração do novo código. Resultado: 38 testes passaram, 271 verificações, sem skips/falhas, saída 0, em 41,07 segundos. A aprovação anterior de 31 testes não era usada como comprovação desses recursos novos.

## Fixture MySQL e desempenho

A suíte faz `migrate:fresh` uma vez por processo nos três bancos dedicados. Entre casos, remove os dados com `DELETE`, preservando a tabela de migrações, o singleton de infraestrutura `cash_closure_locks` e os contadores de IDs. Antes de limpar, confere o nome configurado, `SELECT DATABASE()` e usuário `vendra_test` contra a lista fixa de três bases. `FOREIGN_KEY_CHECKS` é desativado somente nessa conexão de teste e restaurado em `finally`.

Não existe transação envolvendo o teste todo: os workers concorrentes precisam enxergar commits reais. Cada caso recria empresas, usuários e catálogo; IDs são obtidos dinamicamente. O caso de colisão de usuário atribui explicitamente o mesmo ID em bancos diferentes. As conexões de teste central/tenant são fechadas no encerramento do caso.

A otimização foi validada na suíte ampliada: 38 testes passaram, 271 verificações, nenhum teste pulado ou falha, saída 0, duração de 41,07 segundos. O baseline anterior de 31 testes levava 237,85 segundos. As corridas de idempotência e os casos de isolamento continuaram executando e passaram.

Casos da ampliação executada (38 testes totais): compra com atualização atômica do custo médio e contas a pagar; compra repetida/rollback/pagamento; parcelas com resíduo de centavos na última e preservação do dia original; pagamentos parciais, rejeição de excesso e data inválida; quitação da venda apenas após todas as parcelas; reembolso confirmado e histórico preservado; bloqueio de recebimento legado sem reconciliação; troca obrigatória de senha e invalidação de sessões; último administrador; CSV com datas, streaming, título e proteção contra fórmulas. Passaram na execução consolidada de 12/11/2026.

Execute apenas um runner por vez. `--parallel` e múltiplas suítes simultâneas não são suportados com esses nomes fixos de banco de teste. Os dois workers internos dos casos de idempotência compartilham somente a fixture do próprio caso, de forma controlada.

## Verificação complementar de operador

Depois da execução consolidada, foram acrescentados ao caso de permissões existente asserts de ausência de colunas/valores de custo e margem no HTML de operador. Após envio das views finais, o filtro `test_operator_cannot_manage_finance_cancel_or_receive_sales` passou: **1 teste, 18 verificações, nenhum skip/falha, saída 0, duração de 20,51 segundos**. A evidência é complementar; não foi somada artificialmente às 271 verificações da suíte anterior.

## Caixa diário e loja de demonstração — validado

Quatro casos novos validaram: (1) resumo em dinheiro separado dos demais meios, continuidade pelo valor contado, imutabilidade, replay e futuro; (2) cronologia, baixa retroativa, estorno e venda recebida em dia fechado com rollback; (3) corrida entre pagamento e fechamento com snapshot coerente; (4) carga demonstrativa aditiva/idempotente com reconciliação entre movimentos, saldos, títulos, baixas e fechamentos. A carga foi exercitada somente no tenant de teste sem operações prévias.

O runner preserva `cash_closure_locks`, singleton técnico criado pela migração. A limpeza dos dados de cada caso continua removendo `cash_days`, `cash_closures` e `demo_runs`, sem transação global. Asserções de permissões/renderização cobrem caixa, previsão e contatos da landing.

Resultado final consolidado de 12/11/2026: **42 testes passaram, 660 verificações, nenhum skip ou falha, saída 0, duração de 30,32 segundos**. Os três cenários concorrentes com `pcntl` executaram.

Diagnósticos resolvidos antes desse resultado:

- O primeiro teste de replay do demo comparava ordem de chaves de um objeto JSON. MySQL pode reorganizar essas chaves; o teste agora normaliza somente a ordenação e continua comparando valores e tipos estritamente. O filtro do demo passou com 319 verificações.
- Uma manutenção operacional concorrente (`artisan down`) causou HTTP 503 transitório em três casos. O responsável confirmou a causa; sem alterar regra de domínio, os três filtros passaram (45 verificações) após a manutenção. A suíte completa acima foi executada em seguida com estabilidade.

Não execute manutenção global, backup que ative `artisan down`, deploy ou nova suíte paralela durante o runner: os bancos de teste são isolados, mas o marcador de manutenção do checkout é compartilhado.

## Precificação, catálogo unificado e logo — revisão especializada

Modos automático/alerta, tipos e controle de estoque e logo privada foram acrescentados após a evidência de 42 testes. A validação desses recursos está registrada abaixo, separada do baseline anterior.

Foram acrescentados sete casos (49 testes totais na suíte):

- Alerta padrão preserva o preço quando o custo médio muda.
- Modo automático segue custo ponderado e margem, não zera preço sem custo e desfaz entrada que causaria overflow.
- Margem e política podem herdar/ser sobrescritas independentemente sem reescrever vendas históricas.
- Aplicação manual rejeita sugestão desatualizada, mantém idempotência e bloqueia operador.
- Produto sem controle e serviço usam custo de referência; não geram movimento e compra aceita apenas produto.
- Mudança de tipo/controle respeita o saldo persistido, e o estorno considera a participação histórica no estoque.
- Logo rejeita SVG, reencoda PNG, ignora caminho fornecido pelo navegador, mantém armazenamento privado, não serve caminho de outra empresa e aparece no relatório da empresa correta. O teste usa `Storage::fake('local')`, sem arquivos de clientes reais.

O parser auxiliar dos testes CSV localiza o cabeçalho da tabela após as colunas de identificação da empresa. Isso preserva a verificação de período/IDs e acrescenta a presença da identidade da empresa, em vez de assumir um índice fixo anterior ao branding.

A landing também verifica os contatos comerciais finais informados: WhatsApp `5535999193304`, número visível `99919-3304` e e-mail `ivancasvilela@gmail.com`. Isso não altera usuários, credenciais ou remetentes de autenticação.

## Resultado da ampliação para 49 casos

Execução consolidada em 12/11/2026: **48 testes passaram e 1 falhou, 813 verificações, nenhum teste pulado, duração de 36,44 segundos**. Precificação, tipos/estoque, contatos e gráficos passaram. O teste da logo identificou uma falha real de privacidade de cabeçalho: `BinaryFileResponse` sobrescrevia o `private` fornecido ao construtor com `public`. O backend foi corrigido aplicando `setPrivate()` e `no-store` à resposta já construída, tanto para PNG quanto para a marca padrão.

Após essa correção, o teste avançou e revelou uma chamada inadequada do próprio teste: `status()` não existe na resposta binária Symfony; foi substituída por `getStatusCode()`, sem remover ou flexibilizar assertions.

Filtro final da logo: **1 teste passou, 28 verificações, nenhum skip/falha, saída 0, em 16,48 segundos**. Confirmou PNG normalizado sem payload anexado, autenticação, cache privado/no-store/nosniff, identificação HTML/CSV, isolamento entre tenants com mesmo ID de usuário e caminho estrangeiro, e remoção segura.

Após o filtro da logo, foi executada uma única suíte completa na fonte corrigida: **49 testes passaram, 826 verificações, nenhum skip ou falha, saída 0, duração de 30,10 segundos**. Esse é o resultado final consolidado, sem somar artificialmente verificações de execuções anteriores. Os três cenários concorrentes com `pcntl`, a carga demo, os modos de preço, tipos/controle de estoque, logo privada, contatos e gráficos executaram.

## Normalização de rótulos da loja

Após o resultado consolidado de 49 casos, foram acrescentados dois casos focados. O filtro `test_store_labels_` passou em 12/11/2026: **2 testes, 17 verificações, nenhum skip/falha, saída 0, duração de 29,68 segundos**.

A validação cria uma 17ª venda e um orçamento adicional com descrição original da coorte, descrição personalizada e produto externo com texto semelhante. Somente a descrição original de IDs comprovados é normalizada; customizações, metadados do documento e IDs externos são preservados. A segunda execução não muda os dados. Valores de vendas/itens/compras/títulos, pagamentos, movimentos e snapshots de caixa permanecem iguais; usuários e produtos externos não são alterados.

Esses filtros foram executados exclusivamente nos bancos de teste. A validação anual posterior está registrada a seguir.


## Histórico anual e consolidação final

O filtro inicial `test_annual_history_` passou: **2 testes, 57 verificações, saída 0, duração de 52,38 segundos**. Após acrescentar reconciliação agregada, uma execução completa passou: **53 testes, 901 verificações, nenhum skip/falha, saída 0, duração de 62,86 segundos**. Não foram somadas verificações entre execuções.

Os casos fixam a data em 12/11/2026, preparam a loja e uma 17ª venda antes da importação. Verificam que o histórico novo só ocupa o intervalo anterior ao primeiro caixa existente, preserva os registros financeiros e movimentos anteriores, mantém custo/saldo/preço dos produtos existentes e encerra os novos produtos históricos com saldo zero. Há vendas realizadas de janeiro a novembro, previsão pendente em dezembro e nenhuma venda realizada, baixa ou fechamento no futuro.

A reconciliação compara todos os fechamentos com os pagamentos do respectivo dia (dinheiro recebido/pago e quantidade de pagamentos), saldo esperado e continuidade entre abertura e valor contado anterior. Também compara totais de vendas com itens e títulos, além do valor baixado de cada título com pagamentos menos reembolsos. A ponte com o primeiro caixa original conserva a abertura; os snapshots originais permanecem iguais.

A repetição mantém o resumo e as contagens. Sem a carga base concluída, a importação é rejeitada. Um pagamento histórico preexistente, inserido apenas como fixture de teste para representar dados restaurados, impede sobreposição sem gravação parcial. A API financeira normal continua rejeitando baixa retroativa em período fechado; a importação não relaxa essa regra operacional.

Todos esses cenários rodaram somente nos três bancos de teste autorizados, sem manutenção global ou alteração dos dados da empresa operacional. A aplicação posterior da carga na empresa real e sua verificação independente são responsabilidade da operação, não integram esta evidência automatizada.


## Saldos pendentes exibidos no dashboard e financeiro

A inspeção visual encontrou os indicadores zerados apesar de títulos com saldo. O accessor `balance` do modelo interceptava o alias SQL com o mesmo nome. Os controllers passaram a consultar um alias distinto, `outstanding_total`.

Uma regressão HTTP cria recebível de 150,25 com baixa de 40,10 e conta a pagar de 200,50 com baixa de 50,20, além de títulos cancelados em ambas as direções. Exige recebível de **110,15** no dashboard e financeiro e pagável de **150,30** no financeiro, excluindo os cancelados. O teste verifica o resultado público da view sem depender do alias da implementação.

Execução consolidada posterior à correção: **54 testes passaram, 906 verificações, nenhum skip/falha, saída 0, em 67,42 segundos**. Os testes permanecem restritos aos bancos de teste; nenhum título operacional foi criado ou alterado pelo QA.

## Ano encerrado com volume mensal equilibrado

A evolução do importador para anos históricos completos foi validada em 12/11/2026 pelo filtro `test_annual_history_`: **3 testes passaram, 70 verificações, nenhum skip/falha, saída 0, em 72,32 segundos**. É uma execução focada posterior à suíte de 54 casos, não uma nova execução consolidada de toda a suíte.

O caso novo exige janeiro a dezembro com exatamente 44 vendas por mês (528 no ano), faturamento máximo/mínimo inferior a 1,15, 365 fechamentos, saldo de ligação com o histórico anterior, preservação das operações existentes e replay sem duplicação. Os dois casos anteriores continuam verificando o ano corrente parcial, sobreposição financeira, reconciliação e proteção da base preparada.


## Comissões e políticas de desconto

A validação foi executada ANTES do deploy comercial, no checkout isolado `/srv/vendra-commercial-qa.uGFdQ8`, com vendor copiado, configuração privada de teste e storage/cache próprios. Não executou migração nem testes no checkout operacional. A suíte completa passou: **60 testes, 956 verificações, nenhum skip/falha, saída 0, duração de 138,42 segundos**. O teste do ano completo de 2025 permaneceu na suíte.

Cinco novos casos cobrem:

- Desconto negado por padrão; herança e sobrescrita independente por produto; soma da redução implícita do preço com o percentual explícito. O limite de 10% aceita 100→90 e rejeita 90 com 1% adicional. Tentativas inválidas não criam venda nem movimentam estoque.
- Arredondamento em centavos e comissão sobre líquido: quantidade 0,333, preço 10,00 e desconto de 10% geram líquido 3,00 e comissão de 7,50% igual a 0,23. Snapshot e nome do vendedor permanecem após alteração cadastral; repetição não duplica. Operador não pode atribuir outro vendedor, e vendedor inativo é rejeitado.
- Preço de catálogo visto desatualizado é rejeitado; flags públicas que alegam dados históricos ou autorização não liberam desconto.
- Relatório considera data da venda e recebimento acumulado: venda de 100,00, comissão de 10,00 e baixa parcial de 25,00 apresentam comissão proporcional de 2,50 e restante 7,50. Replay não duplica a baixa, canceladas são excluídas e operador recebe 403.
- Orçamento novo aprovado de 90,00 e documento legado de 60,00 mantêm preços após catálogo mudar para 200,00 e desconto ser desabilitado. A conversão usa comissão atual de 20% (18,00 e 12,00), conserva legado sem desconto artificial e não aceita reivindicar a exceção histórica por payload público.

Esta evidência valida a fonte consolidada em ambiente QA; não afirma que a migração ou o deploy operacional já ocorreram.


## Ampliação do gráfico para os 12 meses

O baseline comercial de 60 casos continua separado desta alteração posterior. O primeiro filtro de três casos encontrou uma falha real no componente Blade mensal: **2 falharam e 1 passou, 31 verificações, duração de 51,03 segundos**. O dashboard de administrador retornou 500 por `ParseError` no PHP compilado de `components/charts/sales-trend.blade.php`; operador passou porque não renderiza o gráfico. A falha foi comunicada antes de qualquer deploy operacional. `view:cache` sozinho não confirma a sintaxe PHP compilada; é necessário também executar o lint dos arquivos resultantes.


Após corrigir as diretivas Blade coladas ao texto e agrupar o PHP local, o lint de todos os PHP compilados passou. A repetição dos mesmos três filtros no checkout isolado passou: **3 testes, 66 verificações, nenhum skip/falha, saída 0, em 40,18 segundos**.

O novo caso fixa novembro de 2026 e verifica janeiro/dezembro de 2025 com vendas de 100,00/200,00 e uma venda cancelada de 999,00, além de fevereiro de 2026 com 500,00. O ano padrão é 2025, com total 300,00 e duas vendas, 12 rótulos e dez meses intermediários zerados. Selecionar 2026 produz 500,00 e uma venda; selecionar 2027 gera erro de validação. Os outros filtros confirmam renderização das telas e ausência de gráfico/indicador agregado para operador.

A evidência final é **baseline completo de 60 testes / 956 verificações mais estes três filtros de 66 verificações após o patch mensal**. Existem 61 casos na fonte, mas não foi executada uma suíte completa de 61 nesta etapa; as contagens de execuções distintas não são somadas.
