# Landing comercial — revisão de 15/11/2026

Base inspecionada: main `99a74b2e1d17ca58a0d17812c5c896bdfa0db637`.
Escopo publicado: somente `resources/views/welcome.blade.php`, `public/marketing.css` e `public/marketing.js`. Este documento e `tests/marketing/landing.cjs` não entram no overlay.

## Segunda revisão visual — 15/11/2026

Base: main `f6e20b262d39a54444dbac3df6dd2d83ca99adc1` (PR #8). Esta revisão substitui o CSS cumulativo por uma folha exclusiva organizada, sem dependências. A abertura passa a usar fundo claro, texto azul-marinho e destaque azul. A prévia ganha moldura de aplicação, navegação lateral apenas decorativa e tabela HTML com caption e escopos de linha/coluna. É uma representação do fluxo, identificada como fictícia, não uma captura do ERP nem promessa de identidade com suas telas.

Headline: “Estoque e vendas em ordem. Seu negócio em movimento.” O subtítulo explicita serviços e financeiro. Benefícios e segmentos têm espaçamento e tipografia consistentes; demonstração em painel azul-marinho, FAQ e fechamento com CTA mantêm hierarquia. Ícones locais em SVG; nenhuma fonte, imagem ou biblioteca externa. Os contatos, limites fiscais e eventos/privacidade continuam iguais. Sem preços, testemunhos ou métricas comerciais inventadas.

Validação: `node tests/marketing/landing.cjs` e `node --check public/marketing.js`, além de `git diff --check`. Testes em 320, 390, 768, 1024 e 1440 px: HTTP local 200, nenhuma rolagem horizontal ou exceção JS; links/CTAs, teclado, menu/Escape, FAQ, foco, impressão, ausência de armazenamento e requisições de analytics. Ampliado o teste para a estrutura acessível da tabela e altura mínima de 44 px no CTA principal. Capturas de desktop/celular revisadas. Nenhum teste envia mensagem externa. Mantida a limitação: prévia estática, sem PHP/Laravel ou smoke da VPS.

Overlay desta segunda revisão: `resources/views/welcome.blade.php`, `public/marketing.css` e os três novos JPEG em `public/brand/`, com assets versionados `20261115c`. `public/marketing.js` não mudou. Documentação e testes ficam no Git, fora do overlay. Publicação será executada no outro chat com acesso à VPS, conforme combinado, usando os cuidados de baseline, lock compartilhado, backup e smoke descritos abaixo. O novo PR precisa estar integrado antes da publicação; não publicar uma branch junto com mudanças de ERP.

## Imagens e destaques — ajuste solicitado

Removido o parágrafo visível de aviso sobre rastreadores/WhatsApp conforme solicitação. A implementação continua sem rastreadores ativos. A área de benefícios foi substituída por três seções visuais com preços, ordens de serviço e fluxo financeiro, cada uma com destaques em texto, alt descritivo e link para ampliar a imagem sem JavaScript.

Os JPEG são **prévias estáticas reconstruídas**, baseadas nas views `prices.blade.php`, `documents/index.blade.php`, `cashflow.blade.php`, `layout.blade.php` e no CSS atual do ERP, somente lidos. Não são capturas de uma sessão autenticada ou renderização nativa Laravel. A estrutura foi resumida para apresentação. Todos os nomes, valores, alertas e projeções são fictícios; cada imagem contém essa identificação. Não representam clientes reais, resultados, ganhos ou saldo bancário conciliado. Nenhuma base ou credencial foi acessada.

Ativos: `public/brand/vendra-precos.jpg`, `public/brand/vendra-servicos.jpg`, `public/brand/vendra-financeiro.jpg`, 1200 × 760 px cada, aproximadamente 240 KB no total. Dimensões declaradas, carregamento lazy e decoding assíncrono. O teste estático passou a servir e decodificar os três arquivos e conferir alt e dimensões nos cinco viewports. A versão publicada ainda exige conferência real após deploy.

**Overlay completo do PR #9:** os dois arquivos da landing acima e esses três JPEG. `marketing.js` não mudou. Não publicar `app.css`, `ui.js`, `layout.blade.php`, fontes de geração, documentação ou testes. Conferir baseline dos arquivos existentes e ausência dos novos JPEG antes de aplicar. Seguir o procedimento de backup/lock/readiness deste documento e preservar permissões de leitura para o servidor web.

## Auditoria e decisão

A auditoria da versão pública foi bloqueada: curl com TLS verificado em https://2.25.146.67:8445/ retornou erro 60 (certificado apresentado para openai.com incompatível com o IP). Isso não permite atribuir a causa ao certificado de origem; o caminho de proxy pode estar envolvido. Nenhum bypass de TLS foi usado. Auditoria visual feita sobre o código atual do GitHub em prévia estática local, sem login nem dados reais. Não equivale a validar o PHP/servidor publicado.

Prioridades encontradas: headline genérica para tráfego frio; CTAs sem pedido explícito de demonstração; seis cartões extensos no celular; prévia com textos de 6–9 px; FAQ fiscal anterior ao desenvolvimento atual; ausência de eventos. Base já tinha marca consistente, skip link, FAQ nativo, menu sem dependências, contatos corretos e exemplos identificados como fictícios. Não havia formulário que entregasse leads; WhatsApp permanece a conversão funcional.

Narrativa implementada: dificuldade com controles separados → estoque, vendas e serviços conectados → exemplo fictício → três benefícios → segmentos → o que avaliar na demonstração → objeções e CTA. Preservados azul-marinho, azul e ciano. CTAs incluem mensagem editável com segmento e necessidade. Nenhum preço, prova social ou resultado foi inventado.

Fiscal conferido em `app/Fiscal/NfePayload.php`, `SefazProvider.php`, `SefazTransport.php`, `Nfse/PrepareDps.php` e `Nfse/Readiness.php`: fluxos SEFAZ 55/65 em homologação; NFS-e apenas preparação local, `transport_ready=false`. A landing não promete produção, homologação oficial ou autorização obtida. Nenhum arquivo fiscal foi alterado.

## Referências de posicionamento

Pesquisa consultada em 15/11/2026, em fontes oficiais; sem copiar textos, layout ou ativos:

- [Bling — controle de vendas](https://www.bling.com.br/funcionalidades/vendas): ênfase em gestão de vendas e conexão de canais. Vendra concentra a narrativa na rotina do balcão; não alega integração com marketplaces.
- [Bling — controle de estoque](https://www.bling.com.br/funcionalidades/controle-de-estoque): estoque como benefício central. Vendra demonstra saldos/custo/margem com exemplo fictício e evita prometer sincronização multicanal.
- [Conta Azul — demonstração](https://contaazul.com/demo-pme/): convite concreto para ver o sistema. Vendra explicita o próximo passo pelo WhatsApp, sem copiar duração, formulário ou condições comerciais.
- [Conta Azul — planos](https://contaazul.com/planos/): segmentação da oferta por perfil de empresa. Vendra pede segmento e necessidade, sem inventar planos ou preços.

## Medição e privacidade

Eventos DOM `vendra:whatsapp_click` e `vendra:demo_request` emitidos uma vez por ativação do CTA. `detail`: versão 1, event, placement (`header`, `hero`, `resources`, `demo`, `faq`, `contact`, `mobile_fixed`), channel `whatsapp`, attribution. Os dois eventos representam o mesmo clique/intenção; não somar como dois leads. Mensagem enviada, conversa recebida e demonstração agendada exigem confirmação posterior no processo comercial.

Sem SDK, GA4, GTM, Pixel, cookies, armazenamento local ou transmissão de eventos. Um adaptador futuro deve carregar só depois da configuração e escolha de privacidade aplicável. Não encaminhar page_location com query completa, texto da mensagem, telefone, e-mail ou identificadores de anúncios.

UTMs ficam apenas na URL original e em memória durante a página, sem persistência entre navegações nem repasse ao WhatsApp. Somente valores revisados entram em attribution: source google/meta/facebook/instagram, medium cpc/paid_social/paid_search, campaign vendra_informatica/vendra_moda/vendra_automotivo. São códigos aceitos, não campanhas criadas. Valores desconhecidos são descartados; utm_term, utm_content, gclid, fbclid e demais parâmetros nunca entram nos eventos. Para outras campanhas, revisar e ampliar a lista explicitamente. Não colocar dados pessoais nas URLs de entrada: o servidor/proxy pode registrar a URL antes do JavaScript.

## Validação local

Chromium headless disponível no ambiente, via Playwright. Executar com Playwright instalado e Chromium disponível:

```sh
node --check public/marketing.js
node tests/marketing/landing.cjs
# Opcional: QA_OUTPUT=/caminho/temporario CHROMIUM_PATH=/caminho/chromium node tests/marketing/landing.cjs
```

O teste serve somente os três arquivos da landing em porta efêmera, substituindo a expressão do ano na prévia. Não inicia Laravel, não usa banco e cancela a navegação externa dos cliques de teste.

Resultados: 320/390/768/1440 px, HTTP local 200, sem overflow horizontal ou exceções JS, sete CTAs com destino/telefone/mensagem corretos, todas as âncoras existentes, menu por teclado/Escape, foco após navegação, FAQ por Enter, impressão com FAQ aberto e contato visível, restauração do FAQ após impressão, CTA funcional sem JS. Eventos preservam somente UTMs permitidas, rejeitam valores pessoais e não geram requisição externa; cookies/localStorage/sessionStorage vazios. Revisão visual das capturas móvel e desktop. Amostras de contraste: texto principal secundário #61718a/branco 4,96:1 e botão branco/#1463eb 5,23:1; cabeçalhos da prévia ajustados para #52627b após detectar contraste abaixo de 4,5:1. Não é certificação completa de acessibilidade.

Altura em 390 px passou de 6895 a 6389 px; em 1440 de 4448 a 4246 px. O aumento de legibilidade pode ampliar altura em tablet. HTML/CSS/JS somam aproximadamente 48 KB brutos; gzip local estimado 12,4 KB, sem fontes ou imagens externas. São medidas de arquivos, não desempenho real de rede. Não foi executado Lighthouse nem medida conversão comercial. PHP CLI e dependências Laravel não estavam disponíveis para renderização nativa; a expressão Blade dinâmica preexistente do ano não foi modificada. Login da aplicação não é servido pelo teste estático.

## Publicação pendente: procedimento restrito

Não executado: faltam `/workspace/vps-access/hostinger_ed25519`, `known_hosts` e `connect.py`, e a VPN não teve prontidão comprovada. Provisionar por canal seguro, nunca por texto em chat/Git. O endereço HTTPS precisa funcionar com validação de identidade. Scripts `ops/deploy-nfse-preparation.sh` e `ops/backup.sh` foram inspecionados; não executar deploy fiscal antigo para esta entrega.

Após acesso, preparar procedimento específico e revisar antes de aplicar:

1. Buscar main novamente e comparar o commit integrado com a branch testada. Na VPS, inspecionar os três arquivos e hashes, git status e versão atual dos scripts; não presumir que a VPS coincide com main. Se algum arquivo diferir da baseline revisada, reconciliar e repetir QA antes de sobrescrever.
2. Preparar stage somente com os três arquivos, manifestos SHA-256 de baseline/overlay e cópia de recuperação com proprietário/permissões. Rejeitar symlinks e caminhos fora da allowlist. Não incluir documentação, testes, ERP, dependências, .env ou bancos no overlay.
3. Adquirir e aguardar o lock `/run/lock/vendra-fiscal-deploy.lock`. Revalidar hashes depois de obtê-lo. Registrar estados de `vendra.service`, timer e worker. Não iniciar worker manualmente.
4. Se necessário pausar para backup consistente, parar somente `vendra-fiscal.timer`, aguardar worker terminar naturalmente com prazo e abortar sem matá-lo se não terminar; parar somente `vendra.service`. Sem escritas, executar `VENDRA_BACKUP_ACK_QUIESCED=1 /srv/reporvexo/ops/backup.sh`; validar SHA256SUMS, integridade tar/gzip e presença do dump não vazio. Backup contém segredos: fica privado na VPS.
5. Revalidar hashes sob lock e aplicar apenas os três arquivos revisados, preservando proprietário e permissões. Validar hashes finais. Compilar/conferir somente a view da landing com PHP `php -c /opt/reporvexo-tools/php/cli.ini` e usuário `vendra-web`; não limpar caches globais, migrar, seedar ou instalar dependências. Se a view compilada existir, invalidar somente a correspondente ao welcome, após conferir o caminho calculado pelo Blade.
6. Retomar somente Vendra e aguardar readiness HTTPS validado (repetições curtas e limitadas). Conferir landing, CSS/JS versionados, login sem autenticar, links/âncoras e ausência de erros. Repetir os quatro viewports contra a versão real. Só restaurar timer se estava ativo antes, depois de readiness. Em falha, restaurar somente os três arquivos do backup restrito e validar novamente antes de retomar agendamento. Preservar evidências; não iniciar outros serviços.

Não alterar apache2 global, firewall, SSH, TLS, PHP global ou outros sistemas. Nenhum deploy, migração ou envio fiscal foi feito nesta revisão.

## Próximos testes comerciais

Depois de publicar e configurar medição consentida: testar headline focada em estoque versus atendimento, mantendo o restante estável; comparar CTAs hero/fixo por segmento; acompanhar conversas recebidas, leads qualificados e demonstrações agendadas, sem confundir cliques com leads. Validar primeiro o HTTPS público antes de direcionar tráfego pago. Nenhuma campanha ou despesa foi autorizada por este trabalho.

## Quatro demonstrações do produto — 16/11/2026

Base: main `5a1a239` (PR #9 integrado). A seção visual agora reúne quatro demonstrações: custo/margem/preço; etapas de OS; venda com produtos, serviços e recebimento parcial; contas e previsão. Cada bloco inclui exemplo fictício verificável aritmeticamente e explicação do recurso. Não são depoimentos, casos de clientes ou provas sociais.

Nova imagem `public/brand/vendra-vendas.jpg`, 1200×760, reconstrução estática baseada em `resources/views/sales/show.blade.php` e no estilo do sistema, com empresa/cliente/usuário fictícios. Venda R$180, recebido R$60, saldo R$120; o registro não movimenta dinheiro. Não houve sessão autenticada ou acesso a base de dados. A identidade fictícia está na própria imagem e no texto da página.

Testes locais Chromium/Playwright em 320/390/768/1024/1440: quatro imagens decodificadas, quatro exemplos, âncoras, sete CTAs, teclado/FAQ/menu/impressão, ausência de overflow e erros JS. Sem envio de mensagens ou rastreadores. `git diff --check` aprovado. PHP/Laravel e VPS permanecem fora da validação local.

Overlay desta entrega: `resources/views/welcome.blade.php`, `public/marketing.css`, `public/brand/vendra-vendas.jpg`. Os três JPEG anteriores já pertencem ao main e devem estar disponíveis na VPS; conferir sua integridade, sem sobrescrevê-los se divergirem do baseline. Assets da página versionados como `20261116`. `public/marketing.js` não foi alterado. Não incluir documentação/testes ou arquivos do ERP no overlay. Publicação continua dependente de acesso seguro à VPS no chat executor, com o procedimento de lock, baseline, backup e smoke acima.
