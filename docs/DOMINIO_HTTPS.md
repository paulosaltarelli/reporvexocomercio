# Domínio e HTTPS do Vendra

## Estado verificado em 12/11/2026

- GitHub acessível com permissões de leitura e escrita. Branch de infraestrutura: `infra/vendra-domain-https`, criada de `main` em `81232f2fa75a2f795fc8d3fbea75a604b83bde05`.
- PR #1 aberto, origem `feature/fiscal-nfce`; não mesclar nem publicar. Não implantar `main` ou pacotes da aplicação para uma mudança de domínio.
- Ausentes neste executor: `/workspace/vps-access/hostinger_ed25519`, `/workspace/vps-access/known_hosts`, `/workspace/vps-access/connect.py`. SSH não executado; estado real da VPS não inspecionado.
- Política do ambiente indica VPN configurada e acesso Tailscale automático. Isso não comprova conectividade SSH nem saúde da VPN.
- HTTPS público na porta 8445 falhou por identidade do certificado no caminho pelo proxy. Não desabilitar a validação TLS; não atribuir o resultado ao certificado instalado na VPS sem inspeção direta.
- Configuração versionada indica serviço Apache isolado `vendra.service`, DocumentRoot `/srv/reporvexo/public`, porta 8445 e certificado compartilhado de IP. Outros serviços ocupam 80/443/8443/8444 segundo `OPERACAO.md`. Confirmar tudo na VPS.

## Escolha e registro

Recomendação sujeita à aprovação: `vexogestao.com.br`, produto em `vendra.vexogestao.com.br`. Alternativa: `vendragestao.com.br` com produto em `app.vendragestao.com.br`.

Consulta ao endpoint oficial `https://registro.br/v2/ajax/avail/raw/<dominio>`:

| Domínio | Resultado |
| --- | --- |
| vexogestao.com.br | status 0, disponível na consulta |
| vendragestao.com.br | status 0, disponível na consulta |
| vexo.com.br | status 2, registrado |
| vendra.com.br | status 2, registrado |
| usevendra.com.br | status 2, registrado |
| vexosistemas.com.br | status 6, processo de liberação de 11/11 até 18/11/2026 às 15h de Brasília |

Registro.br anuncia R$ 40 por ano. Referências: https://registro.br/dominio/ e https://registro.br/ajuda/pagamento-de-dominio/. Orçamento para um ano: registro R$ 40 e renovação anual R$ 40 pela tabela atual; reconfirmar ambos na contratação e antes de renovar. Disponibilidade não é reserva nem garantia de direito à marca.

Compra depende de aprovação expressa do nome, registrador e preço. Cadastro do titular, CPF/CNPJ, autenticação, eventual MFA e pagamento devem ocorrer diretamente na conta do proprietário, fora do chat. Nenhum domínio foi comprado.

## Plano condicionado à inspeção da VPS e ao registro

1. Recuperar os três arquivos de acesso por mecanismo seguro do ambiente. Usar SSH com BatchMode e StrictHostKeyChecking habilitados e known_hosts fornecido. Não obter chaves privadas pelo chat.
2. Inspecionar somente metadados necessários: listeners, virtual hosts, módulos, unidades, configuração de renovação, validade/SAN dos certificados, firewall e revisão implantada. Não imprimir `.env`, chaves, journals ou logs de clientes. Confirmar se 443 permite adicionar um virtual host dedicado sem afetar os existentes.
3. Identificar arquivos exatos a modificar e criar cópias privadas com data e permissões preservadas. Registrar caminhos, hashes e comandos de restauração antes de alterar. Não copiar segredos ao Git.
4. Criar apenas o registro DNS A `vendra` apontando a `2.25.146.67`, inicialmente com TTL 300 se o provedor permitir. Não publicar o IP Tailscale. Não criar AAAA sem IPv6 validado. Preservar MX, TXT, CAA e demais registros; conferir se CAA permite a autoridade certificadora escolhida.
5. Para HTTPS padrão, avaliar um novo virtual host específico em 443 no Apache existente, sem substituir sua configuração global. Caso necessário alterar um site existente, revisar a necessidade com o proprietário antes de agir, conforme a restrição de `OPERACAO.md`. Não iniciar outro processo disputando 443.
6. Preferir emissão ACME por DNS-01 se isso evitar alterações em 80. DNS-01 manual permite emissão, mas não assegura renovação automática: definir integração segura com DNS ou outro desafio renovável antes de concluir. Reaproveitar a instalação e os mecanismos existentes quando compatíveis, sem modificar o certificado de IP nem seu renovador.
7. Integrar o novo virtual host diretamente ao DocumentRoot já implantado, preservando usuário, tratamento PHP, restrições de arquivos e limites atuais, ou avaliar proxy com validação TLS do backend. Não publicar checkout, migrations, PDV ou alterações fiscais. Não mudar APP_URL/sessões antes de verificar necessidade e impactos; manter cookies restritos ao host.
8. Validar a configuração de cada instância Apache afetada e só então executar recarga controlada da unidade correspondente. Não reiniciar VPS nem usar reinício global.
9. Validar DNS autoritativo/público, cadeia TLS, SAN, expiração, HTTPS em 443, redirecionamentos e rota de login `/app/vexo/login`. Não executar transações de negócio para testar domínio. Confirmar que o acesso original por IP:8445 e os outros sites permanecem funcionais. Verificar renovação com o mecanismo adequado à instalação real.

## Reversão a concretizar na VPS

Antes da implantação, preencher inventário dos backups e comandos exatos para os caminhos observados. Se houver falha, desabilitar apenas o novo site, restaurar somente os arquivos modificados, validar sintaxe e recarregar a unidade correspondente. Reverter o registro DNS criado se necessário, considerando caches e TTL. Preservar certificado de IP, serviço Vendra, bancos e código implantado. Nunca restaurar o checkout inteiro ou configurações globais para desfazer uma mudança de domínio.

Este documento é um plano revisável, não um registro de implantação. Não houve alteração na VPS nem no DNS.
