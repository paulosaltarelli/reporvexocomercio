# Homologação — 12/11/2026

- Laravel 12 / PHP 8.3 / MySQL 8 na VPS.
- 38 testes, 271 verificações, todos passaram; nenhum teste de integração pulado.
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
