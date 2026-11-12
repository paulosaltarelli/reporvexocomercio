# Operação do Vendra

Runbook da homologação em `https://2.25.146.67:8445`, código em `/srv/reporvexo`.
Os exemplos abaixo são para o terminal administrativo da VPS. Não são executados automaticamente por esta documentação.

## Fronteiras da implantação

- Um pacote de aplicação, um serviço `vendra.service`, usuário `vendra-web`, porta 8445.
- Apache existente atende 80/443/8443/8444: não editar seus sites, não reiniciá-lo e não parar o MySQL compartilhado.
- Somente `storage` e `bootstrap/cache` são graváveis pelo processo web. Código, `.env`, arquivos de operação e testes não são graváveis por ele.
- Banco central, banco por assinante e contas MySQL restritas ao respectivo banco. Root MySQL via socket é usado apenas pelo provisionamento/backup administrativo.
- Nunca executar `migrate:fresh`, suítes ou seeders em bancos de clientes. Testes usam os três bancos dedicados descritos em TESTES.md.
- A camada web não publica `.env`, logs, `vendor` ou `ops`. O DocumentRoot é exclusivamente `public`.
- Não enviar chaves SSH, `.env`, backups ou arquivos `/root/vendra-*` ao Git, chat ou serviços de terceiros. Não imprimir configuração ou journal para diagnosticar.

## Acesso administrativo

Na nuvem, o acesso TCP é feito pelo proxy VPN/Tailscale autorizado; o executor não tem rota SSH direta ao IP público. Chaves e `known_hosts` são arquivos privados fora do checkout, não dependências do Git. A chave pública e a identidade do servidor devem ser conferidas antes de cadastrar uma nova conexão. Não desabilitar `StrictHostKeyChecking`.

Senha inicial: arquivo privado `/root/vendra-primeiro-acesso.txt`, visível apenas pelo administrador no console da VPS. Troca por entrada oculta:

```sh
cd /srv/reporvexo
php artisan vendra:password vexo admin@vexo.local
```

## Provisionamento inicial

`php ops/provision.php --fresh-instance` é exclusivamente para uma instalação nova, revisada, sem `.env`, `.env.testing`, recursos MySQL reservados ou arquivos de recuperação preexistentes. Não é um comando de atualização ou de criação de novo assinante. A recusa é intencional: não apagar bancos/arquivos para fazê-lo passar.

O script exige root, usa trava de processo, recusa bancos/usuários existentes e grava um journal privado `/root/vendra-provisioning.json` **antes** do primeiro DDL. APP_KEY e credenciais são necessários para recuperação. Uma falha de DDL não é transacional; o script para e preserva material de recuperação. Não repeti-lo cegamente nem remover recursos automaticamente após falha parcial. A instância atual já está provisionada e não precisa desse comando.

Criar mais empresas ainda requer procedimento administrativo específico; não duplicar pacote, serviço, domínio de código ou alterar o schema de um cliente isoladamente.

## Atualização com janela de manutenção

Antes de atualizar: suíte verde, pacote de commit conhecido, hash verificado e backup recuperável. Como a implantação atual usa caminho fixo, parar **somente Vendra** durante extração e migrações evita pedidos atendidos por um pacote parcialmente atualizado. Releases atômicos ainda não foram implantados.

1. Pause provisionamentos e comandos de escrita Vendra; registre o commit da versão atual e o arquivo de configuração.
2. Pare `vendra.service`. Os demais serviços permanecem ativos.
3. Faça backup e confira os hashes (seção seguinte).
4. Gere o pacote de uma revisão conhecida com `git archive`; não inclua `.env`, credenciais, `vendor`, caches, sessões ou logs. Verifique o hash e a lista do arquivo antes da extração. Extraia somente sob `/srv/reporvexo`, sem sobrescrever segredos.
5. Instale o lockfile, execute migrações e normalize permissões:

```sh
cd /srv/reporvexo
COMPOSER_ALLOW_SUPERUSER=1 php /opt/reporvexo-tools/composer.phar install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan migrate --database=central --force
php artisan vendra:migrate-tenants --force
php artisan view:cache
sh ops/permissions.sh
apache2 -t -f /srv/reporvexo/ops/apache.conf
systemctl start vendra.service
curl --resolve 2.25.146.67:8445:127.0.0.1 --fail --silent --show-error --output /dev/null --write-out 'Login HTTP %{http_code}\n' https://2.25.146.67:8445/app/vexo/login
```

`ops/permissions.sh` corrige inclusive o modo do diretório raiz: tar pode restaurar 0700 e causar 403. Ele recusa symlinks; revise qualquer symlink antes de rodá-lo, sem apagar arquivos automaticamente. O script deixa executáveis PHP com leitura apenas; invoque `php artisan` e `php vendor/bin/...`.

Não continue após um comando malsucedido. Se a migração falhar em uma empresa, mantenha a manutenção e investigue; bancos MySQL não têm rollback automático de DDL. A restauração do pacote anterior não garante compatibilidade com schema novo.

Se arquivos de unidade foram atualizados, somente após revisão:

```sh
install -m 644 ops/vendra.service ops/vendra-certificate.service ops/vendra-certificate.timer /etc/systemd/system/
systemctl daemon-reload
```

Mudanças na unidade precisam de reinício do **Vendra** para aplicar sandbox/UMask. Alterações de configuração Apache são verificadas com `apache2 -t -f ...` antes de recarregar apenas `vendra.service`.

## Backup e recuperação

`ops/backup.sh` cria um pacote **local** protegido em `/var/backups/vendra`: dump consistente InnoDB dos bancos declarados pelo central, manifesto, pacote da aplicação incluindo `.env`/APP_KEY e checksums SHA-256. Ele não inclui bancos dos outros sistemas nem os bancos de teste; não imprime credenciais.

Use numa janela sem DDL/provisionamento ou escritas Vendra; por exemplo, depois de parar só `vendra.service` e suspender comandos de escrita:

```sh
VENDRA_BACKUP_ACK_QUIESCED=1 sh /srv/reporvexo/ops/backup.sh
```

Confirme exit zero, leia apenas nomes/estado do pacote, e execute `sha256sum -c SHA256SUMS` dentro do diretório criado. Diretórios `.incomplete.*` indicam falha e não são backups válidos. Não removê-los sem investigar. O script não implementa retenção nem cópia externa; monitore o espaço antes de agendar.

**Ainda necessário antes de comercializar:** destino externo sob controle do proprietário, cifragem com chave protegida fora da VPS, retenção, alertas de falha/espaço e ensaio de restauração. Snapshot da VPS ou cópia na mesma máquina não substitui backup externo. Nenhum agendamento de backup está instalado por esta revisão.

Ensaio seguro de recuperação:

1. Use uma VPS/instância MySQL isolada, sem clientes nem bancos atuais. Nunca importe um dump com `CREATE DATABASE`/`USE` no servidor compartilhado como “teste”.
2. Verifique SHA-256 antes de extrair; inspecione nomes, tamanhos e caminhos para evitar sobrescrever arquivos fora do destino.
3. Restaure código/dependências, `.env` e o **mesmo APP_KEY** do ponto escolhido em local protegido. Não gere outra chave: ela tornaria as credenciais cifradas dos assinantes ilegíveis.
4. Restaure os bancos e recrie as contas e grants por banco. O dump não inclui `mysql.user`; as credenciais centrais estão no `.env` e as dos assinantes precisam ser decifradas com o APP_KEY original, por um procedimento administrativo revisado. Não abrir os grants para todos os bancos como atalho.
5. Confira a correspondência entre cadastro central e bancos, login por empresa, saldos, custo médio, vendas, financeiro e arquivos. Valide ausência de cruzamento entre empresas e TLS antes de liberar acesso.
6. Só declarar recuperação testada após esse ensaio. Esta revisão não executou restauração.

## Certificado público e renovação

O certificado já existente é de Let's Encrypt, emitido para o IP 2.25.146.67 e renovado pelo procedimento `minasul-certbot.timer`. Não instalar outro renovador nem substituir esse certificado. O Vendra só lê o mesmo par de certificado/chave, para a mesma identidade IP.

`vendra-certificate.timer` roda aproximadamente a cada hora, compara o hash do certificado e recarrega apenas Vendra quando muda. O script para sem iniciar serviços durante manutenção, verifica validade/IP e sintaxe Apache antes da recarga. A renovação real e futura depende do renovador existente: observar o agendamento não prova que uma emissão futura terá sucesso. Monitorar a expiração é necessário, pois o certificado de IP tem validade curta.

```sh
systemctl list-timers vendra-certificate.timer minasul-certbot.timer --no-pager
openssl x509 -in /etc/letsencrypt/live/2.25.146.67/fullchain.pem -noout -dates
systemctl status vendra-certificate.service --no-pager
```

## Logs e observabilidade

Acesso/erros Apache: `/var/log/vendra/`; erros de aplicação: `storage/logs/`, fora do webroot. Logs podem conter dados de clientes: acesso administrativo, retenção limitada e compartilhamento só com autorização. Nunca registrar senhas, tokens ou corpos completos das requisições.

`ops/logrotate.conf` propõe rotação diária de logs Apache (14 arquivos, compressão e recarga apenas de Vendra). Para aplicar após revisão:

```sh
install -m 644 ops/logrotate.conf /etc/logrotate.d/vendra
logrotate --debug /etc/logrotate.d/vendra
```

No `.env` privado, `LOG_CHANNEL=daily` e `LOG_DAILY_DAYS=14` limitam os logs Laravel. A proposta de provisionamento passa a sugerir isso; `.env` da instância atual não é modificado por esta revisão.

Verificações úteis: `systemctl is-active vendra.service`, teste HTTPS autenticado, espaço/inodes, crescimento de logs, expiração do certificado, falha de backup e saúde MySQL. Ainda não há monitoramento/alertas externos, teste de carga ou objetivo de recuperação formal (RPO/RTO).
