# Homologação — 12/11/2026

- Laravel 12 / PHP 8.3 / MySQL 8 na VPS.
- Revisão final: 54 testes, 906 verificações, todos passaram; nenhum teste de integração pulado, duração de 67,42 segundos. Histórico das execuções em TESTES.md.
- Testes usaram bancos exclusivos `vendra_test_central`, `vendra_t_test_a`, `vendra_t_test_b`; a empresa Vexo não recebeu os dados de teste.
- Navegador Chromium: primeiro acesso e troca obrigatória de senha, 19 rotas operacionais, adição dinâmica de itens em compra/OS, menu móvel recolhível, desktop/mobile e ausência de erros JavaScript.
- Suíte MySQL cobre compra/estoque/AP atômicos e repetíveis, parcelas com centavos residuais/dia original, baixa parcial e excesso, quitação, reembolso vinculado, primeiro acesso, último administrador, revogação de sessões e CSV após desconexão do middleware. Corridas reais verificadas com pcntl.
- Teste complementar de operador: 1 teste e 18 verificações aprovados, confirmando ocultação de custos/margens e bloqueio das ações administrativas. Contagem separada da suíte de 38 testes.
- CSRF real: login GET200 e POST sem token419.
- Backup local protegido criado antes das novas migrações; cópia externa e ensaio de restauração continuam pendentes.
- Arquivos `.env`, `.git/config`, `composer.json` e logs não acessíveis via web (403/404).
- Certificado público Let’s Encrypt verificado para 2.25.146.67; HTTPS da porta 8445 respondeu 200 com validação de cadeia e identidade habilitada.
- Serviço próprio `vendra.service`, usuário próprio `vendra-web`, acesso MySQL por banco. Apache existente e portas 80/443/8443/8444 continuaram ativos.
- Renovação pública existente preservada. Timer Vendra acompanha o certificado e recarrega apenas seu processo. Uma futura renovação real ainda não foi observada por este teste.

Isso demonstra os fluxos testados em homologação; não equivale a teste de carga, auditoria independente, ensaio completo de recuperação ou certificação fiscal. Consulte as limitações em PRODUTO.md.

## Histórico anual e conferência final

Em 12/11/2026 foi aplicada a carga anual de informática: 436 vendas, 11 compras, 723 baixas, 751 títulos (incluindo quatro previsões) e 306 fechamentos novos. A base passou a ter 453 vendas, 14 compras, 745 baixas, 795 títulos e 315 fechamentos. Há vendas concluídas em todos os meses de janeiro a novembro; o restante do ano recebe previsões sem pagamentos ou vendas concluídas no futuro.

Snapshot independente confirmou a preservação dos valores, custos, quantidades, preços, vínculos, datas das 17 vendas anteriores, compras, pagamentos e nove fechamentos. A reconciliação percorreu todas as vendas/itens, títulos/ledger, caixas diários/snapshots, continuidade das aberturas e último saldo/custo de estoque. A repetição do importador não duplicou registros. Os textos visíveis originais Demo foram normalizados; a proveniência sintética interna permanece.

Chromium conferiu 11 rotas após a carga, gráficos reais, ausência dos rótulos, mobile sem overflow, PDF com identificação da empresa e logo privada. A inspeção visual encontrou um problema anterior no agregado de recebíveis/pagáveis: o alias SQL `balance` colidia com o accessor do modelo. A correção usa alias próprio e foi validada em HTTP com parcelas parcialmente pagas e títulos cancelados. Os cartões publicados foram comparados com uma soma independente dos títulos.

As contas temporárias de QA foram removidas, sem alterar credenciais do proprietário. O backup anterior à carga teve os checksums verificados; os demais sistemas da VPS permaneceram ativos.

## Doze meses completos com volumes semelhantes

Em 12/11/2026 foi acrescentado o ano de 2025 inteiro, mantendo o ano corrente e suas previsões: 528 vendas (44 em cada mês), 12 compras, 873 baixas e 365 fechamentos novos. O faturamento mensal varia de R$31.881 a R$34.147, com o mesmo mix mensal de produtos/serviços e alterações graduais de preço/custo. O total da base passou a 981 vendas, 26 compras, 1.618 baixas, 1.695 títulos e 680 fechamentos.

O filtro dos três cenários anuais passou com 70 verificações. Após aplicar, a assinatura dos dados anteriores confirmou a preservação das 453 vendas e dos 315 fechamentos, assim como estoque, preços, custos, compras e pagamentos. A repetição não duplicou a carga. Backup com checksums conferidos e login HTTPS 200; Vendra e Apache dos outros sistemas permaneceram ativos após a janela de manutenção.

## Descontos, comissões e gráfico anual

Publicados em 12/11/2026 após testes em cópia isolada: baseline comercial de 60 testes/956 verificações, seguido de três filtros do gráfico anual/66 verificações. A fonte tem 61 casos; não foi alegada execução consolidada de 61. A compilação PHP de todos os templates foi validada após corrigir uma diretiva Blade adjacente ao texto no componente anual.

Migração 000012 aplicada à empresa Vexo. Snapshot independente confirmou a preservação das 981 vendas, 26 compras, 1.618 baixas, 1.695 títulos e 680 fechamentos anteriores, assim como quantidades, preços e custos. Novas colunas históricas foram preenchidas de forma neutra; comissões permanecem zero enquanto não configuradas para novas operações. Backup anterior com checksums conferidos.

Chromium no endereço HTTPS real conferiu 12 barras/meses de 2025, total R$396.168,00 e 528 vendas, seletor para 2026 com mês futuro zerado, mobile390 sem overflow, campos de herança no grupo/produto, manutenção do checkbox de estoque com saldo, bloqueio de desconto implícito, prévia de orçamento, relatório com logo e comissão interna oculta na impressão. Nenhuma venda de teste foi gravada na empresa operacional; credenciais do proprietário não foram alteradas.

## Vendedor independente e painel de operação

Publicados em 12/11/2026 com migração 000013, após suíte completa em cópia isolada: **65 testes, 1.011 verificações, nenhum skip/falha**, em 138,12 segundos. Os casos cobrem vendedor sem login, vendedor creditado separado do executor, reatribuição com justificativa/auditoria, preservação da migração e isolamento do painel.

A comparação dos campos anteriores confirmou preservação das 981 vendas, 26 compras, 1.618 pagamentos, 1.695 títulos e 680 fechamentos, além dos documentos, itens, custos, preços e quantidades. A migração vinculou apenas vendas com vendedor explicitamente registrado, sem presumir vendedor pelo autor de documentos antigos. Backup anterior verificado por checksum.

Chromium na URL HTTPS operacional confirmou criação de vendedor temporário sem login pelo formulário, escolha desse vendedor no painel, busca/seleção de cliente e pré-preenchimento de orçamento/venda. Verificou entrada do operador diretamente em `/desk`, bloqueio do cadastro administrativo e do painel de outro vendedor, ausência de overflow móvel e manutenção do gráfico anual. Documento antigo sem vínculo solicita atribuição em vez de assumir responsável. Não foram gravadas vendas ou orçamentos de QA na empresa. Contas, perfis e vendedor temporários foram removidos após verificar ausência de vínculos.
