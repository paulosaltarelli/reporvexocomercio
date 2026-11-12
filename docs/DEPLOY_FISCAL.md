# Plano de implantação fiscal — revisão direta

Este documento e `ops/deploy-fiscal.sh` definem o procedimento de implantação. A execução efetiva é registrada no journal privado. Base de código revisada: `main` em `81232f2`, com balcão/PDV e atribuição de vendedores preservados. Não publicar código com testes falhos nem tratar uma simulação como homologação oficial.

## Escopo e isolamento

O overlay fiscal toca somente os caminhos explicitamente revisados no manifesto. Não copiar a árvore de QA inteira para a aplicação. Não restaurar `main` antiga sobre mudanças comerciais, não rodar seed, importação anual, demos, alteração de preços ou migrações comerciais. Nenhuma credencial, certificado A1/PFX, CSC, série, número ou perfil tributário é provisionado por esse deploy.

O serviço Vendra permanece na porta8445, com seu Apache próprio. Não recarregar/parar o Apache que atende80/443/8443/8444, não parar o MySQL compartilhado, não instalar SOAP em `/etc/php` nem alterar módulos PHP globais.

## Preparação e QA antes da janela

- Finalizar correções na revisão fiscal, executar a suíte fiscal e a suíte comercial/PDV na árvore isolada de QA. O QA deve usar os bancos dedicados, nunca os bancos ativos.
- Stage conhecido: `/srv/vendra-fiscal-qa.wmtFsq`; se houver outro, o script só aceita `/srv/vendra-fiscal-qa.*`.
- Dependências instaladas devem corresponder ao `composer.lock` revisado. O pacote SOAP foi extraído/verificado em `/opt/reporvexo-tools/php/extracted/usr/lib/php/20230831/soap.so`; não baixar nem substituir outro binário apenas para passar testes.
- CLI fiscal usa `/usr/bin/php -c /opt/reporvexo-tools/php/cli.ini`. O script usa esse ini para Composer, lint e migrações e verifica SOAP também como `vendra-web`.
- `ops/apache.conf` deve conter `PHPIniDir /opt/reporvexo-tools/php/apache2`, **após carregar php_module**. O novo ini é uma cópia do ini Apache atual com a extensão SOAP explícita. Só o Apache Vendra recebe essa configuração.
- Revisar semanticamente `Checkout`, `CommerceController`, layouts, rotas e a tela da venda: são arquivos compartilhados que recebem pontos fiscais. Um hash de arquivos protegidos não prova, sozinho, ausência de regressão comportamental; testes de PDV/vendas/financeiro continuam obrigatórios.

## Manifestos

São três arquivos no formato literal de `sha256sum`: `digest`, dois espaços, caminho relativo. Sem comentários, sem caminhos absolutos, espaços, `..`, duplicatas, symlinks ou arquivos secretos. Guardar fora do checkout da aplicação e transferir por canal administrativo autenticado.

1. `overlay.sha256`: hashes do **conteúdo final revisado e testado** a copiar do stage. Inclui código fiscal, integrações compartilhadas necessárias, cinco migrações fiscais, Composer e ops fiscais. Não inclui testes, dados, `.env`, `storage`, `vendor`, credenciais ou arquivos PDV protegidos.
2. `baseline.sha256`: hashes exatos da versão publicada para cada arquivo do overlay que já existia na base. Nesta revisão, diferenças de formatação foram comparadas com uma cópia da main processada pelo mesmo formatador; os 114 arquivos comparados coincidiram, sem diferença funcional. O script compara com a VPS antes de qualquer parada. Arquivo novo que já existir na VPS bloqueia; não adotar nem sobrescrever mudanças desconhecidas.
3. `pdv.sha256`: hashes da base para arquivos comerciais que não podem mudar. Recomendação mínima: `DeskController`, `SellerController`, `CommissionController`, `SellerAssignment`, `SellerFormContext`, `CashService`, `FinancialService`, `CommercialRules`, models de vendedor, `desk.blade.php`, componentes `sales/entry-items` e `sales/seller-field`, formulários de venda, telas de caixa/comissão/vendedores e `public/ui.js`/`public/app.css`. Verificar nomes existentes na base. O script confere tanto a VPS quanto o stage e recusa que algum deles esteja no overlay.

Para obter a lista candidata use `git diff --name-status 81232f2` e `git ls-files --others --exclude-standard`; selecione os caminhos fiscais intencionalmente. Arquivos excluídos/renomeados precisam de plano específico e são recusados neste overlay. Calcule o hash de cada arquivo final, transfira a mesma versão ao stage e faça QA **antes** de fixar o manifesto. Qualquer mudança posterior exige novo manifesto e revalidação correspondente.

O digest do próprio `overlay.sha256` precisa ser conferido e fornecido pelo revisor independente. Não gerar automaticamente um digest “aprovado” dentro do script nem marcar QA como aprovado por conveniência.

## Execução revisada

O modo sem `--apply` só imprime o plano; não realiza chamadas de rede, migrações ou escrita na VPS:

```sh
bash ops/deploy-fiscal.sh --plan
bash -n ops/deploy-fiscal.sh
```

Depois de QA aprovado e revisão dos manifestos, o administrador pode executar, com caminhos ajustados:

```sh
VENDRA_FISCAL_DEPLOY_APPROVED=1 VENDRA_FISCAL_QA_PASSED=1 \
bash /srv/vendra-fiscal-qa.wmtFsq/ops/deploy-fiscal.sh --apply \
  --stage /srv/vendra-fiscal-qa.wmtFsq \
  --manifest /root/vendra-fiscal-review/overlay.sha256 \
  --baseline /root/vendra-fiscal-review/baseline.sha256 \
  --pdv /root/vendra-fiscal-review/pdv.sha256 \
  --manifest-sha256 DIGEST_REVISADO_DE_64_HEXADECIMAIS
```

As variáveis registram decisão operacional do administrador; não substituem aprovação ou credenciais e não são uma interface web.

### O que o script faz

1. Verifica root, revisão/QA explícitos, caminhos, manifests, base/PDV, ausência de symlinks e extensões. Linta PHP do overlay antes de interromper a aplicação.
2. Recusa um processador/timer fiscal já ativo: primeiro é preciso drenar a operação e conferir intenções/estados incertos. Não matar um envio em andamento e presumir que não ocorreu.
3. Para somente `vendra.service`, impede deploys concorrentes e faz backup local com o script existente; guarda journal privado em `/var/lib/vendra/fiscal-deploy` e cópias da configuração de execução. Backups contêm APP_KEY/credenciais e não devem sair por chat/Git.
4. Normaliza apenas a árvore PHP privada do Vendra (`/opt/reporvexo-tools/php/...`) com diretórios750 e ini/SO640 `root:vendra-web`; verifica leitura/carga SOAP como usuário do worker. Não modifica `/etc/php` nem o Apache de outros sites.
5. Copia somente arquivos do overlay, instala dependências do lockfile, limpa caches de configuração/rotas/views para carregar o novo disco fiscal e aplica **somente**:
   - `database/migrations/2026_11_12_000001_add_fiscal_demonstration_guard.php` no central;
   - `database/tenant-migrations/2026_11_12_000001_create_fiscal_foundation.php`;
   - `database/tenant-migrations/2026_11_12_000002_create_fiscal_preparations.php`;
   - `database/tenant-migrations/2026_11_12_000003_enable_fiscal_transport.php`;
   - `database/tenant-migrations/2026_11_12_000004_freeze_direct_sefaz_xml.php` em cada base retida.
6. Inclui bases de empresas inativas por um clone de contexto de **manutenção em memória**, sem alterar `active` no central nem habilitar login. Credenciais e nomes continuam vindo do cadastro central validado; são descarregados após cada empresa. Não usa `migrate-tenants` genérico para aplicar pendências comerciais.
7. Compila Blade e linta cada PHP compilado: `view:cache` sozinho não assegura validade sintática. Normaliza permissões e reimpõe diretórios700/arquivos600 no disco fiscal privado obtido da configuração. Atualmente é `storage/app/fiscal-private`; um caminho inesperado bloqueia o deploy.
8. Instala unidades fiscais, mas deixa o timer desabilitado. Verifica Apache próprio, confirma novamente hashes do PDV, inicia só Vendra e faz GET HTTPS de login com validação TLS. Não executa worker, seed, PFX, criação de emitente, sincronização ou chamada SEFAZ.

A flag de demonstração central nasce bloqueada por padrão e a configuração fiscal desabilitada/homologação. Não limpar essas barreiras nem inventar certificado/CSC como parte do deploy.

## Pós-deploy e ativação do timer

O responsável precisa conferir pelo navegador (login autenticado): navegação fiscal, validação/pendências, ausência de certificado fictício, formulário de empresa/produto e venda mista, além de uma navegação de PDV/vendas/caixa. Verificar root fora de `public` e bloqueio web de `.env`, `storage/app/fiscal-private` e logs; não publicar phpinfo ou PFX para comprovar a extensão.

Antes de ativar o timer, verificar que não há operações inesperadas ou incertas na outbox e que credenciais/dados da empresa não foram inseridos por fixtures. O nome de um fixture, método fake ou teste passado não prova ausência de outbox operacional: consultar o estado real de cada banco somente por leitura.

Após essa conferência, o administrador pode tornar o processamento solicitado operacional:

```sh
systemctl enable --now vendra-fiscal.timer
systemctl list-timers vendra-fiscal.timer --no-pager
systemctl status vendra-fiscal.service --no-pager
```

O timer pode disparar logo ao ser habilitado. Com uma intenção real enfileirada e pré-requisitos válidos ele poderá fazer a transmissão oficial de homologação: isso deve ser uma ação deliberada do fluxo solicitado, não um teste automático do deploy. A instalação do worker não configura certificado/CSC nem libera produção; sem os requisitos, operações ficam bloqueadas com motivo. Não chamar `vendra:fiscal-work` como “smoke” de infraestrutura.

## Falha e recuperação

MySQL DDL não é rollback transacional e algumas migrações fiscais recusam reversão destrutiva. Após uma falha, o script deixa Vendra parado e o timer sem ativação; não reexecutar automaticamente, não renumerar, não alterar estados fiscais e não importar backup sobre outras bases.

Consultar o journal privado e restaurar somente após revisar quais passos ocorreram. O backup local requer cópia externa protegida e ensaio de recuperação; não foi criado nem restaurado por esta revisão. Código anterior pode não aceitar schema parcialmente evoluído. Preservar arquivos fiscais, XML assinado, hashes, protocolo e intenções incertas, caso existam, antes de qualquer intervenção.

## Evidência operacional

Este documento descreve o procedimento, não comprova sua execução. Cada implantação efetiva registra manifests, resultado do backup e estado no journal privado. Os resultados dos testes de software são documentados separadamente em VALIDACAO_FISCAL.md; não representam emissão oficial. Não publique o journal ou o backup, pois contêm dados operacionais privados.
