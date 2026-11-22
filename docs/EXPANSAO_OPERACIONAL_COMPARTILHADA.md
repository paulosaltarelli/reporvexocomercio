# Expansão operacional compartilhada

A revisão de turnos de supermercado sucede a expansão de restaurante, supermercado e delivery. O arquivo `ops/operational-schema-release.json` identifica a migração tenant e as duas tabelas que esta revisão acrescenta ao schema já instalado.

| Domínio | Migração | Tabelas novas |
|---|---|---|
| Pagamentos do restaurante | `2026_11_21_000201` | Divisões, recebimentos e itens pagos |
| Catálogo do restaurante | `2026_11_21_000301` | Configurações versionadas, grupos de adicionais e adicionais |
| Supervisão do supermercado | `2026_11_21_000401` | Aprovações e importações de rascunhos |
| Delivery | `2026_11_21_000501` | Integrações, mapeamentos, pedidos, itens e eventos inbox/outbox |
| Turnos do supermercado | `2026_11_22_000001` | `supermarket_shifts` e `supermarket_shift_carts` |

Não se executa `migrate` nas views de uma loja. O modo `--apply-operational-expansion` do operador monta um template administrativo descartável com todas as migrações e certifica a diferença contra o manifesto instalado. A expansão confere a lista de migrações e tabelas com o perfil da versão e verifica os hashes das migrações anteriores. Para publicar esta revisão, o pacote inclui a migração dos turnos, o manifesto e os adaptadores `ops/extend-operational-schema.php` e `app/Tenancy/OperationalSchemaRelease.php`.

O construtor cria as novas tabelas físicas com escopo, as FKs compostas, as views filtradas e os triggers de proteção. Os logins existentes recebem privilégios apenas nas views correspondentes, incluindo os principals de importação registrados. O usuário definer continua bloqueado, sem rotação de credenciais. A identidade de cada runtime passa a apresentar o novo certificado. Novas implantações usam esse mesmo manifesto e recebem as novas views automaticamente.

O operador exige evidência da suíte integral, baseline e arquivos protegidos. A aplicação fica pausada durante backup, expansão, atualização atômica do hash em `.env` e reconstrução dos caches. O mesmo lock administrativo é respeitado pelo provisionador e pelos demais comandos de estrutura. O helper privilegiado usa caches PHP próprios, em diretório root `0700` do journal; ele não carrega código dos caches graváveis pelo processo web.

O journal privado conserva o manifesto anterior, o hash novo, o nome do template por tentativa e os diagnósticos em arquivos `0600`. Uma falha estrutural remove somente os objetos novos ainda vazios, restaura o certificado e o hash anterior e permite restaurar o overlay de código. Nunca se restaura o banco de dados automaticamente. Se alguma tabela nova já contiver dados, a reversão estrutural é recusada: mantenha o ambiente fechado e reconcilie os dados antes de decidir a correção.

A verificação `SharedSchemaExtensionTest` parte do schema anterior da produção e cobre falha após DDL, ausência de acesso às tabelas físicas, FKs entre empresas, índices únicos por namespace, runtime futuro e reversão estrutural. Os focos de domínio usam os bancos descartáveis `a/b/central` em janelas sequenciais; o teste estrutural usa bancos aleatórios próprios.

A ampliação de schema não habilita conectores externos, emissões fiscais ou cobranças C6. Essas integrações permanecem sujeitas às habilitações e ao escopo próprio de cada módulo.

Quando a mesma publicação incluir a listagem central por segmento, o pacote também contém a migração central `2026_11_22_000001_index_company_operation_segments` e o comando `platform:sync-company-segments`. O publicador aplica a migração, sincroniza as empresas ativas depois de atualizar o hash de isolamento e registra o resultado em `segments.log`. A versão anterior da aplicação tolera as duas colunas centrais adicionais em uma reversão do código.

Uma publicação de código que atualize a projeção `CompanySegment` também executa a sincronização. Para lojas antigas, a Central usa o segmento que o wizard exibe na etapa Operação. A sincronização atualiza apenas o índice na Central e preserva a configuração e a data de conclusão da implantação na loja.
