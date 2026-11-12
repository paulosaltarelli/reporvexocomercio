# Vendra · por Vexo

SaaS de gestão para comércio e serviços em PHP 8.3 / Laravel 12 / MySQL.
Um pacote compartilhado, banco separado por empresa. Consulte [a definição do produto](docs/PRODUTO.md).

## Desenvolvimento

Use o checkout existente; cada tarefa cloud já é isolada, sem necessidade de criar worktrees.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure o banco local em `.env` antes de executar migrações. Nunca use credenciais ou bases dos sistemas existentes.

```bash
php artisan migrate
php artisan test
```

A página inicial não depende de build de frontend. A homologação inclui estoque e custo médio, compras integradas, vendas mistas, orçamentos, ordens de serviço, parcelas, baixas parciais, histórico financeiro, usuários e relatórios/CSV. Consulte as limitações em docs/PRODUTO.md.

## VPS

Diretório reservado: `/srv/reporvexo`. Composer exclusivo em `/opt/reporvexo-tools/composer.phar`.
Homologação em serviço próprio `vendra.service`, usuário `vendra-web` e porta HTTPS 8445. Configuração em `ops/apache.conf`; não depende de recarregar o Apache dos outros sistemas. HTTPS usa o certificado público Let’s Encrypt já existente para o IP. A renovação existente foi preservada; `vendra-certificate.timer` verifica mudanças a cada hora e recarrega somente o Vendra.
Não alterar os sistemas que usam 80/443/8443/8444. A porta 8445 está reservada ao Vendra nesta VPS; em outra máquina, revalidar sua disponibilidade.
Credenciais e material SSH ficam fora deste repositório e não devem ser versionados.

## Operação

- `php artisan vendra:migrate-tenants --force`: aplica a mesma estrutura a todas as empresas ativas e interrompe na primeira falha. Fazer backup antes; migração de produção não é rollback automático.
- `php artisan vendra:password vexo admin@vexo.local`: troca a senha por entrada oculta no console.
- `ops/provision.php`: provisionamento inicial único da instância, exige root e recusa adotar bancos/usuários existentes. Não é uma API de cadastro público.
- `.env`, `.env.testing`, credenciais e bancos não pertencem ao Git. O APP_KEY é necessário para decifrar as credenciais de empresas; protegê-lo e incluí-lo no plano de recuperação.
- Backup/restauração automatizados, monitoramento e cadastro de novas empresas ainda precisam ser operacionalizados antes de comercialização.

Runbook de implantação, permissões, certificado e recuperação: [docs/OPERACAO.md](docs/OPERACAO.md).

## Marketing e demonstração

A raiz `/` contém a landing do Vendra com links de WhatsApp e e-mail fornecidos pelo proprietário. O acesso permanece em `/app/vexo/login`.

`php artisan vendra:demo vexo --confirm-demo` adiciona a demonstração de informática uma única vez. Não muda usuários/senhas nem apaga cadastros. A primeira carga recusa empresas que já possuem operação financeira, para evitar mistura de dados. Consulte docs/DEMONSTRACAO.md.

`/app/vexo/cashflow` mostra fluxo registrado e previsão de saldos por vencimento; `/app/vexo/cash` permite conferência e fechamento diário. Datas consolidadas bloqueiam novas baixas e estornos retroativos. Consulte docs/CAIXA.md.

O histórico de informática de 2026 pode ser ampliado com `php artisan vendra:historico-anual vexo 2026 --plan`. A execução exige backup e manutenção exclusiva do Vendra; o procedimento está em [docs/IMPORTACAO_ANUAL.md](docs/IMPORTACAO_ANUAL.md). A carga preserva as operações anteriores e os fechamentos existentes. Meses futuros recebem previsões, sem vendas ou pagamentos futuros realizados.

Os rótulos visíveis da carga inicial podem ser normalizados sem alterar seus valores, datas ou vínculos; consulte [docs/COMPUTER_STORE_LABELS.md](docs/COMPUTER_STORE_LABELS.md). A identificação interna da origem sintética permanece para impedir que a integração fiscal trate esses dados como operações reais.

## Regras comerciais e comissões

Em Cadastros → Grupos ou no produto, configure descontos permitidos, limite percentual e comissão sobre a venda líquida. O produto pode herdar cada regra do grupo ou sobrescrevê-la. Reduzir o preço unitário também consome o limite de desconto. As regras novas não recalculam vendas antigas.

Relatórios → Comissões apresenta apuração por vendedor e a parcela proporcional aos recebimentos acumulados, sem gerar pagamento automático. Consulte [docs/COMISSOES_E_DESCONTOS.md](docs/COMISSOES_E_DESCONTOS.md).

O dashboard apresenta janeiro a dezembro com seletor de ano e tabela de valores mensais. O ano completo mais recente com dados é a seleção inicial; na base de informática, 2025 contém 528 vendas distribuídas pelos 12 meses.
