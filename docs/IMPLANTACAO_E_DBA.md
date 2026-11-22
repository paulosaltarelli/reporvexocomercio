# Implantação guiada e acesso global Vexo

A central mostra a URL de login de cada loja e, no cadastro detalhado, também a URL do wizard. Abrir um link não autentica o cliente.

## Implantação

Clientes novos em armazenamento compartilhado entram na implantação após o primeiro acesso e a troca obrigatória de senha. A raiz da loja também encaminha o administrador para o wizard enquanto a implantação inicial não foi concluída. Lojas demonstrativas e empresas antigas em armazenamento isolado preservam a navegação existente.

As cinco etapas contêm formulários reais: empresa e logotipo; operação e módulos; configuração fiscal; usuários e responsável pelo caixa; revisão e conclusão. Os mesmos cadastros e validações das telas definitivas são utilizados. O progresso é salvo por empresa e permite retomar a etapa. A conclusão abre o sistema sem criar produtos, clientes, vendas ou movimentos. Um vendedor/responsável ativo é necessário; o administrador inicial já existe desde o provisionamento.

A etapa fiscal permite salvar os parâmetros e enviar o certificado sem sair do wizard. Módulos fiscais selecionados continuam sujeitos às verificações existentes de cadastro e certificado; módulos não utilizados podem permanecer desabilitados. Nenhuma etapa habilita produção fiscal. NFS-e oferece rascunho parcial sem certificado ou reserva de sequência; preparação de DPS e transmissão são capacidades distintas, e a transmissão nacional permanece indisponível.

Metadados de progresso, primeira conclusão, rascunho NFS-e, perfis e suporte usam o JSON existente de `company_operations`; gravações de módulos preservam essas chaves. As alterações têm bloqueio transacional, versão e auditoria. Não há nova migração ou mudança no manifesto SQL compartilhado.

## Administradores globais

Em Central Vexo → Administradores, administradores ativos podem criar, atualizar, redefinir senhas e desativar outros administradores centrais. Todos possuem acesso global DBA pelo aplicativo. O último administrador ativo é protegido. Atualizações exigem a versão vigente e incrementam `auth_version`, revogando sessões anteriores. Senhas e hashes não são incluídos nos registros de auditoria.

Para entrar numa loja ativa, use Gerenciar empresa → Acessar loja como DBA e informe o motivo. A ação requer POST com CSRF e autenticação central vigente. Ela cria/reutiliza uma conta técnica identificada por administrador e loja, com senha aleatória não exibida. Essa conta não pode entrar pelo login da loja nem ser alterada no cadastro normal de usuários. Ela fica oculta da equipe comercial e não conta como substituta do último administrador cliente.

Cada requisição de suporte verifica a conta central, sua versão, a loja, o vínculo técnico e o prazo de duas horas. A loja preserva sua conexão e isolamento SQL; não há credenciais de banco no navegador. O acesso é completo às funções do aplicativo, sem acesso de superusuário ao MySQL. A entrada, saída e as solicitações de alteração são auditadas na central; as operações mantêm também seus atores nos registros locais. A auditoria central de solicitação identifica uma tentativa, não garante que a operação foi concluída.

O banner identifica quem está atendendo e permite encerrar o suporte. Trocar de loja exige outra entrada explícita pela central. Desativar ou redefinir o administrador central revoga seu acesso nas lojas na próxima requisição.

## Publicação

Usar lock compartilhado, backup consistente validado e overlay restrito. Conferir leitura efetiva como `vendra-web` e renderizar wizard, cadastro da empresa e telas centrais antes de reabrir. Após disponibilizar suporte global, preservar o controle novo em caso de falha: não reverter cegamente a autenticação anterior, que não conhece a restrição das contas técnicas.

Documentos enviados por clientes, certificados e dados cadastrais reais não pertencem ao Git. Um preenchimento autorizado da demonstração deve ter backup, diário privado e verificação de que contagens operacionais e sequências não foram alteradas. Séries e números de documentos de outros ambientes não devem ser presumidos.

## Autenticação multifator da Central

A Central usa e-mail e senha por padrão. A variável de implantação `PLATFORM_MFA_REQUIRED=false` também dispensa o código ao iniciar suporte DBA e ao gerenciar convites de implantação. A auditoria, a expiração do suporte e a revogação de sessão continuam ativas.

Para exigir o segundo fator em toda a Central, cadastre primeiro o autenticador dos administradores que precisam acessar o painel. Em terminal administrativo privado, execute `php artisan platform:mfa email-do-administrador` para cada titular. Entregue os códigos individuais de recuperação por um canal privado. Com os cadastros confirmados, defina `PLATFORM_MFA_REQUIRED=true` e atualize o cache de configuração da aplicação. A ativação exige novo login das sessões que entraram somente com senha.

Com MFA habilitado, o login exige TOTP de seis dígitos ou um código de recuperação de uso único. Suporte DBA e gestão de convites pedem nova confirmação cinco minutos após a última verificação. Para substituir um autenticador perdido, confirme a identidade do titular e use `php artisan platform:mfa email-do-administrador --replace`. A substituição invalida as sessões e os códigos antigos. Desativar a exigência global preserva os fatores e códigos cadastrados.


## Segmento das lojas na Central

Em **Empresas**, a lista agrupa lojas pelo segmento de operação e permite combinar segmento, situação e busca por nome. Cada contagem considera também as outras páginas do filtro. Solicitações já vinculadas a uma loja exibem seu segmento atual. As demais aparecem em **Segmento a definir**.

O wizard atualiza o índice central ao salvar a operação. Para a primeira publicação, aplique a migração central `2026_11_22_000001_index_company_operation_segments` e execute `php artisan platform:sync-company-segments` para carregar os cadastros de lojas ativas que já existem. O comando pode ser repetido para uma loja com `--company=slug`. A listagem da Central consulta o índice no banco central.
