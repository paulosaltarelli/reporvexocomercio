# Testes

`php artisan test` executa cálculos decimais e, quando configurado, integração MySQL.
A suíte de integração recria EXCLUSIVAMENTE `vendra_test_central`, `vendra_t_test_a`, `vendra_t_test_b`.
Crie um usuário `vendra_test` com privilégios apenas nesses três bancos.
Configure `VENDRA_TEST_PASSWORD` em `.env.testing`, fora do Git. Nunca aponte os testes para bancos operacionais.
Sem essa variável, testes MySQL são explicitamente pulados e não validam isolamento nem estoque.
PHP precisa de bcmath, mbstring, xml, curl, pdo_mysql, fileinfo e zip.

Casos: margem sobre venda, arredondamento, média ponderada, frações, saída sem recalcular média, insuficiência com rollback, venda mista, estorno e repetição, bancos separados, sessão de outra empresa, login, telas e acesso de operador.
