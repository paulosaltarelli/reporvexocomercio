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

## Autenticação multifator da central

A central exige senha e TOTP de seis dígitos ou um dos oito códigos individuais de recuperação. O login com sucesso confirma o fator por cinco minutos para iniciar suporte. Depois desse prazo, o formulário de entrada na loja exige um código atual. O acesso de suporte já iniciado mantém a duração e as verificações de revogação descritas acima.

Na implantação, prepare o novo pacote em diretório de release privado e mantenha o painel antigo fechado para manutenção. Aplique somente a migração central `2026_11_20_000001_add_platform_mfa.php` usando a configuração da instalação. As colunas aceitam valores nulos para preservar os cadastros existentes. A partir do novo pacote, em terminal root privado com a chave da aplicação, execute `php artisan platform:mfa email-do-administrador` para cada titular ativo. Registre o segredo no autenticador, confirme o primeiro código e entregue os códigos de recuperação pelo canal privado acordado com o titular. Desative gravação da sessão de terminal. Sincronize o relógio do servidor e do dispositivo. O autenticador utiliza SHA-1 e intervalo de 30 segundos.

Abra a nova versão após confirmar o cadastro de pelo menos um administrador responsável e planejar o atendimento dos demais titulares. Uma conta antiga ou nova sem fator cadastrado permanece ativa no banco e depende do cadastro no console para entrar. As sessões antigas de painel e suporte exigem novo login. Nunca reabra uma versão anterior que aceite somente senha. Em caso de perda do dispositivo, um código de recuperação permite uma entrada e é consumido também quando usado na confirmação de suporte. Para recuperar permanentemente, verifique a identidade do titular fora da sessão web e execute `php artisan platform:mfa email-do-administrador --replace`. A substituição invalida sessões, o fator anterior e os códigos antigos. Rotacionar a senha mantém o fator.
