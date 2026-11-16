# Operação por empresa e implantação

Primeira etapa da evolução SaaS, em branch própria baseada em main pós-PR #9. A landing não é modificada.

## Implementado nesta etapa

- Administração → Operação e implantação (`/app/{company}/setup`), somente administrador.
- Cinco passos retomáveis: empresa/logo, operação, fiscal, equipe e revisão. Empresa, certificado e usuários aproveitam as telas existentes, sem outro armazenamento de segredos.
- Tipos: produtos, serviços ou ambos; estoque, comissões, descontos, orçamentos, OS, NF-e, NFC-e e NFS-e independentes, com validação das combinações.
- Configuração versionada; formulário antigo não sobrescreve uma edição concorrente. Auditoria privada registra ator, versão, data e configuração anterior/nova, sem certificados ou senhas.
- Habilitar/desabilitar é uma mudança imediata na configuração, não um rascunho. A conferência final registra que a implantação foi revisada; não controla acesso a operações nem certifica autorização fiscal.
- Configuração nova preserva o comportamento legado até uma escolha explícita. Desabilitar comissões/estoque não altera vendas anteriores. Usuário vendedor continua existindo e sendo atribuído mesmo quando não há comissão.
- As escolhas são aplicadas no domínio de venda, orçamento/OS, estoque e preparação fiscal, além das telas. HTTP direto ou chamada de serviço não deve contornar essas regras.
- Vendas novas congelam `commission_basis`: caixa ou competência. As antigas recebem caixa, mantendo o comportamento anterior. O valor de comissão por item continua congelado na venda.
- Relatório: competência torna a comissão integral elegível na venda concluída; caixa usa a proporção acumulada recebida. **O filtro continua sendo a data da venda**, com recebimentos acumulados até a consulta. Não é apuração de recebimentos do período, folha ou pagamento ao vendedor.
- Sem estoque, compras/vendas não geram movimentos e os custos de referência são usados na precificação/custo da venda. Mudar o controle exige saldo conciliado/zerado. Estornos continuam seguindo a participação histórica na movimentação.
- Tipo restrito exige desativar itens incompatíveis e resolver documentos pendentes. Desativar OS/orçamento também verifica pendências. Desativar descontos não desfaz um preço aprovado anteriormente num documento.
- NF-e/NFC-e seguem exclusivamente em homologação. NFS-e continua em preparação local, sem transmissão. Desabilitar emissão impede solicitações novas; processamento já enfileirado, consultas e cancelamentos continuam disponíveis.
- Conclusão verifica empresa/CNPJ, campos fiscais aplicáveis e certificados cadastrados válidos. Não verifica credenciamento externo; a própria emissão revalida configuração e certificado. Alterar operação ou dados da empresa invalida a conferência anterior.

## Banco único: ainda não ativado

O runtime atual **continua com banco central e bancos operacionais separados por cliente**. Não basta apontar as empresas para o mesmo banco: isso causaria acesso cruzado. Esta etapa NÃO muda conexões nem copia dados de produção.

A próxima etapa deve entregar, antes de ativar o modo compartilhado:

1. Inventário integral de tabelas, jobs, raw SQL, arquivos, caches, sessões e validações `exists`/`unique`. Há tabelas fiscais que já usam `tenant_id` com significado próprio; não renomear/reutilizar automaticamente.
2. Contexto da empresa resolvido pela sessão/autorização, nunca por `tenant_id` recebido do formulário. Sem contexto, consultas operacionais falham fechadas. Contexto limpo no fim de cada request/job e em falhas de troca.
3. `tenant_id` obrigatório em todas as entidades operacionais, índices compostos e relações que não aceitem IDs de outra empresa. Transformar singletons locais (`id=1`, inclusive `company_operations`, perfil e configurações) em unicidade por empresa. IDs de tabelas diferentes hoje podem coincidir; importação precisa de mapas de IDs por tabela e empresa.
4. Escopo obrigatório no ORM e em todas as operações de Query Builder/SQL, relatórios/exportações, validações, sequências fiscais, idempotência, autenticação e comandos. Global scope sozinho não protege SQL direto ou chaves estrangeiras.
5. Unicidades por empresa: e-mail de usuário, SKU/código de barras, chaves de requisição, numerações fiscais e demais restrições auditadas. Certificados e artefatos privados mantêm namespace de empresa e autorização no download.
6. Testes com pelo menos duas empresas **na mesma base**, IDs tentados por URL/formulário, relacionamento cruzado, leitura/escrita, sessão, arquivos, exportação e jobs. A suíte atual de bases separadas não comprova esse isolamento novo.
7. Importador com dry-run e ensaio em cópias, mapas de IDs e reconciliação de quantidades, custos, títulos/baixas, fechamentos e hashes dos artefatos fiscais. Documentos assinados não devem ser reescritos. Plano de corte com manutenção limitada, backup e recuperação sem duas bases aceitando gravação simultaneamente.
8. Cadastro central de novos clientes e administradores da Vexo, com autorização própria, provisionamento idempotente e auditoria. Um administrador do cliente não pode acessar esse painel central.

## Aplicação desta etapa

Não usar um deploy fiscal antigo ou um `git reset` no checkout operacional. Há revisão de experiência já publicada fora do main: reconciliar os arquivos que tiverem drift (especialmente layout e controllers), preservando alterações do servidor.

Requer a migração tenant `2026_11_16_000001_create_company_operations.php`. Ela cria somente tabelas novas de configuração/auditoria e adiciona `sales.commission_basis` com default `cash`. Não executar `migrate:fresh`, seed ou testes na base operacional.

Antes de publicar: suíte em checkout e bancos exclusivos de teste; conferir baseline/allowlist, adquirir `/run/lock/vendra-fiscal-deploy.lock`, drenar worker naturalmente e pausar só Vendra/timer se necessário. Backup privado validado antes da migração. Aplicar apenas a migração nova por empresa e o overlay revisado, compilar views e verificar HTTPS/fluxos em stage. Retomar os estados anteriores dos serviços. Rollback de código não pode deixar runtime que dependa de tabela ausente; preferir manter as colunas aditivas no retorno da versão anterior e preservar auditorias/dados.

Não publicar a mudança para banco compartilhado junto desta primeira entrega. A aprovação de uma etapa não comprova a segurança da seguinte.
