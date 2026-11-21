# Expansão operacional compartilhada

A revisão de restaurante, supermercado e delivery acrescenta quatro migrações tenant e treze tabelas. O escopo exato está em `ops/operational-schema-release.json`. O histórico, os usuários, as senhas, as configurações das lojas e o DDL das tabelas anteriores permanecem preservados. A política de isolamento não muda.

| Domínio | Migração | Tabelas novas |
|---|---|---|
| Pagamentos do restaurante | `2026_11_21_000201` | Divisões, recebimentos e itens pagos |
| Catálogo do restaurante | `2026_11_21_000301` | Configurações versionadas, grupos de adicionais e adicionais |
| Supervisão do supermercado | `2026_11_21_000401` | Aprovações e importações de rascunhos |
| Delivery | `2026_11_21_000501` | Integrações, mapeamentos, pedidos, itens e eventos inbox/outbox |

Não se executa `migrate` nas views de uma loja. O modo `--apply-operational-expansion` do operador monta um template administrativo descartável com todas as migrações e certifica a diferença contra o manifesto instalado. A expansão aceita somente os quatro arquivos e as treze tabelas revisados, rejeita alterações das migrações antigas e verifica que o DDL anterior continua idêntico.

O construtor cria as novas tabelas físicas com escopo, as FKs compostas, as views filtradas e os triggers de proteção. Os logins existentes recebem privilégios apenas nas views correspondentes, incluindo os principals de importação registrados. O usuário definer continua bloqueado, sem rotação de credenciais. A identidade de cada runtime passa a apresentar o novo certificado. Novas implantações usam esse mesmo manifesto e recebem as novas views automaticamente.

O operador exige evidência da suíte integral, baseline e arquivos protegidos. A aplicação fica pausada durante backup, expansão, atualização atômica do hash em `.env` e reconstrução dos caches. O mesmo lock administrativo é respeitado pelo provisionador e pelos demais comandos de estrutura. O helper privilegiado usa caches PHP próprios, em diretório root `0700` do journal; ele não carrega código dos caches graváveis pelo processo web.

O journal privado conserva o manifesto anterior, o hash novo, o nome do template por tentativa e os diagnósticos em arquivos `0600`. Uma falha estrutural remove somente os objetos novos ainda vazios, restaura o certificado e o hash anterior e permite restaurar o overlay de código. Nunca se restaura o banco de dados automaticamente. Se alguma tabela nova já contiver dados, a reversão estrutural é recusada: mantenha o ambiente fechado e reconcilie os dados antes de decidir a correção.

A verificação `SharedSchemaExtensionTest` parte do schema anterior da produção e cobre falha após DDL, ausência de acesso às tabelas físicas, FKs entre empresas, índices únicos por namespace, runtime futuro e reversão estrutural. Os focos de domínio usam os bancos descartáveis `a/b/central` em janelas sequenciais; o teste estrutural usa bancos aleatórios próprios.

A ampliação de schema não habilita conectores externos, emissões fiscais ou cobranças C6. Essas integrações permanecem sujeitas às habilitações e ao escopo próprio de cada módulo.
