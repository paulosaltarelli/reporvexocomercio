# Homologação — 12/11/2026

- Laravel 12 / PHP 8.3 / MySQL 8 na VPS.
- 19 testes, 79 verificações, todos passaram; nenhum teste de integração pulado.
- Testes usaram bancos exclusivos `vendra_test_central`, `vendra_t_test_a`, `vendra_t_test_b`; a empresa Vexo não recebeu os dados de teste.
- Navegador Chromium: login real, navegação em sete telas operacionais, renderização desktop/mobile e ausência de erros JavaScript.
- Páginas de orçamento, OS e financeiro cobertas pela suíte de renderização; conversão, etapas e liquidação cobertas pela suíte MySQL.
- Arquivos `.env`, `.git/config`, `composer.json` e logs não acessíveis via web (403/404).
- Certificado público Let’s Encrypt verificado para 2.25.146.67; HTTPS da porta 8445 respondeu 200 com validação de cadeia e identidade habilitada.
- Serviço próprio `vendra.service`, usuário próprio `vendra-web`, acesso MySQL por banco. Apache existente e portas 80/443/8443/8444 continuaram ativos.
- Renovação pública existente preservada. Timer Vendra acompanha o certificado e recarrega apenas seu processo. Uma futura renovação real ainda não foi observada por este teste.

Isso demonstra os fluxos testados em homologação; não equivale a teste de carga, auditoria independente, ensaio completo de recuperação ou certificação fiscal. Consulte as limitações em PRODUTO.md.
