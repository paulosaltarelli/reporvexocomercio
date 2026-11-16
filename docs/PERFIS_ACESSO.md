# Perfis e permissões

Vendra permite ao administrador criar perfis por empresa, marcar funções e vincular cada operador em **Configurações → Usuários**. Em **Perfis e permissões**, a prévia lista menus, funções liberadas, funções bloqueadas e usuários vinculados. Não é uma sessão de impersonação.

Administradores mantêm acesso completo. Somente administradores gerenciam usuários, perfis e configuração geral da empresa. A proteção do último administrador ativo continua vigente. Contas existentes continuam no perfil Operador padrão, com as permissões anteriores, até alteração explícita.

As regras protegem rotas GET e POST, menus, botões e serviços sensíveis. Custos, recebimentos, cancelamentos, estoque, financeiro, relatórios, comissões e fiscal têm liberações distintas. Fiscal é uma liberação conjunta de consulta, configuração, emissão e cancelamento, mantendo as restrições fiscais já existentes. A configuração fiscal delegada fica em `/app/{empresa}/fiscal/company`; não permite alterar dados gerais da empresa.

O editor indica e seleciona dependências necessárias. Desmarcar uma consulta também desmarca ações dependentes; o servidor valida e expande a mesma matriz. Funções auxiliares não concedem uma tela por si só. Consultas de vendas e documentos abrangem a empresa: este recurso não cria restrição por vendedor.

Alterações de funções valem na próxima requisição. Mudar o perfil de uma conta invalida suas sessões existentes. Perfis em uso não podem ser excluídos, e o perfil padrão não pode ser removido. Salvar com uma versão antiga exige recarregar para evitar sobrescrever alterações simultâneas.

## Armazenamento e operação

A configuração usa a chave reservada `_access_profiles` no JSON de `company_operations`, com versionamento, bloqueio transacional e registros em `company_operation_audits`. A gravação das opções operacionais preserva essa chave. Cada empresa usa sua conexão e isolamento SQL existentes. Não há migração, alteração de credenciais ou promoção automática de operadores para administradores.

A publicação exige lock compartilhado, backup consistente validado, aplicação apenas dos arquivos previstos, verificação efetiva de leitura como `vendra-web`, compilação das views e renderização nativa antes de reabrir o serviço. Após reabertura, uma falha deve preservar o novo controle de acesso e pausar o serviço para reparo; reverter cegamente ao código anterior poderia reabrir funções revogadas.

Os testes cobrem compatibilidade do operador padrão, bloqueio de acesso direto, delegação sem promoção, revogação, dependências, auditoria, edição concorrente, isolamento entre empresas, vínculos e mapeamento de todas as rotas da empresa. A inspeção com Chromium cobre o editor e telas de consulta em quatro larguras.
