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
