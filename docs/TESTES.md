# Testes

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

A suíte faz `migrate:fresh` uma vez por processo nos três bancos dedicados. Entre casos, remove os dados com `DELETE`, preservando a tabela de migrações e os contadores de IDs. Antes de limpar, confere o nome configurado, `SELECT DATABASE()` e usuário `vendra_test` contra a lista fixa de três bases. `FOREIGN_KEY_CHECKS` é desativado somente nessa conexão de teste e restaurado em `finally`.

Não existe transação envolvendo o teste todo: os workers concorrentes precisam enxergar commits reais. Cada caso recria empresas, usuários e catálogo; IDs são obtidos dinamicamente. O caso de colisão de usuário atribui explicitamente o mesmo ID em bancos diferentes. As conexões de teste central/tenant são fechadas no encerramento do caso.

A otimização foi validada na suíte ampliada: 38 testes passaram, 271 verificações, nenhum teste pulado ou falha, saída 0, duração de 41,07 segundos. O baseline anterior de 31 testes levava 237,85 segundos. As corridas de idempotência e os casos de isolamento continuaram executando e passaram.

Casos da ampliação executada (38 testes totais): compra com atualização atômica do custo médio e contas a pagar; compra repetida/rollback/pagamento; parcelas com resíduo de centavos na última e preservação do dia original; pagamentos parciais, rejeição de excesso e data inválida; quitação da venda apenas após todas as parcelas; reembolso confirmado e histórico preservado; bloqueio de recebimento legado sem reconciliação; troca obrigatória de senha e invalidação de sessões; último administrador; CSV com datas, streaming, título e proteção contra fórmulas. Passaram na execução consolidada de 12/11/2026.

Execute apenas um runner por vez. `--parallel` e múltiplas suítes simultâneas não são suportados com esses nomes fixos de banco de teste. Os dois workers internos dos casos de idempotência compartilham somente a fixture do próprio caso, de forma controlada.

## Verificação complementar de operador

Depois da execução consolidada, foram acrescentados ao caso de permissões existente asserts de ausência de colunas/valores de custo e margem no HTML de operador. Após envio das views finais, o filtro `test_operator_cannot_manage_finance_cancel_or_receive_sales` passou: **1 teste, 18 verificações, nenhum skip/falha, saída 0, duração de 20,51 segundos**. A evidência é complementar; não foi somada artificialmente às 271 verificações da suíte anterior.
