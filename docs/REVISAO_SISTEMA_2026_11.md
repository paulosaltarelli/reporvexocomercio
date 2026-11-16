# Revisão do Vendra — novembro de 2026

## Base verificada

Revisão iniciada sobre `a8f17e4567ddfddebb9a6c85539671d8bf70492b`, na branch
`feature/implantacao-empresa`. A `main` remota ainda estava em `5a1a239`.
Na leitura de produção, os hashes dos arquivos em `app`, `routes`,
`resources/views` e `public` correspondiam à base da implantação.
A instalação em `/srv/reporvexo` não possui metadados Git.

A consulta de estado confirmou tenant `vexo`, ID 1, modo `shared`, banco
`vendra_shared_live`, identificação de demonstração ativa e um administrador
central ativo. Não foram alterados dados, usuários, senhas ou configuração fiscal.

O relatório histórico recuperado em `/tmp/vendra-platform-full-junit.xml`
registra 166 testes, 2.389 assertions, zero erros, falhas ou skips. Esse relatório
pertence à revisão anterior e não substitui a validação desta mudança.

## Correções e melhorias

- Menu lateral com hierarquia consistente, ícones de traço, identificação da
  empresa, seleção da página atual e grupos que abrem de forma exclusiva.
- Os atalhos de criação permanecem nas telas de trabalho. A navegação lateral
  concentra destinos de consulta e acompanhamento.
- O grupo de estoque deixa de aparecer vazio para operadores quando o recurso
  está desabilitado. O título do catálogo segue produtos, serviços ou ambos.
- Cabeçalho identifica seção e página atual em vez de repetir o slogan.
- No celular, o menu abre sobre a tela, tem botão de fechamento, Escape,
  contenção de foco, restauração do foco e bloqueio de interação com o fundo.
  A mudança para desktop libera o fundo e remove o estado modal.
- A troca de senha fica acessível também no menu móvel. A identificação do
  usuário permanece no rodapé da navegação.
- Quando JavaScript não está carregado, a navegação permanece no fluxo da
  página, com grupos nativos expansíveis.
- Dashboard deixa de oferecer criação de compra para empresa somente de
  serviços, e oculta atalhos de OS, indicadores e reposição de estoque quando
  os recursos correspondentes estão desabilitados.
- Painel de operação deixa de mostrar acompanhamento de OS e blocos de
  orçamentos desabilitados. As listagens históricas continuam acessíveis.

A mudança é de apresentação. As permissões no servidor e as validações de
operações permanecem necessárias; esconder links não substitui autorização.
Não há migração de banco nesta revisão.

## Validação

A revisão inicial da navegação passou na suíte completa: **168 testes,
2.416 assertions, zero erros, falhas ou skips**, em 497,139 segundos.

Após os ajustes posteriores dos atalhos/indicadores por módulo e a inclusão do
painel de operação no smoke de telas, os seis testes focados passaram:
**6 testes, 136 assertions, zero erros, falhas ou skips**, em 33,882 segundos.
A fonte final contém 169 casos; não foi executada uma suíte completa de 169.
As contagens de execuções diferentes não são somadas.

A compilação Blade seguida de lint PHP passou em **87 views compiladas**.
Essa checagem identificou e permitiu corrigir, ainda no QA, duas diretivas
adjacentes que causavam ParseError no dashboard. A execução focada final ocorreu
depois da correção.

Na inspeção em Chromium, foram verificadas páginas renderizadas em 320, 390,
768 e 1440 px, além de uma tela de 390 × 568 px. As verificações incluem
rolagem horizontal do documento, erros JavaScript, grupo vazio, abertura e
fechamento, Escape, foco de teclado, contenção de foco, mudança de viewport,
impressão e fallback com os scripts removidos.

O QA usa `/srv/vendra-review-qa.20261116`, storage e cache próprios e somente
configuração dos bancos dedicados de teste. O runner utiliza
`/run/lock/vendra-test-suite.lock`, sem manutenção ou testes na instalação operacional.
As páginas usadas na validação visual são respostas Laravel com dados fictícios.

## Limites e sequência

Esta revisão prioriza navegação e coerência da interface com os módulos habilitados.
Não comprova homologação fiscal remota nem conclusão de funcionalidades que o
relatório de transferência descrevia como pendentes. Os erros históricos do log
operacional consultado eram de 12 a 14 de novembro; não foram tratados como falhas
atuais sem reprodução.

A publicação deve comparar novamente os hashes de todos os arquivos substituídos,
usando o lock compartilhado de deploy e backup verificável. Preservar a landing,
chaves, dados e arquivos privados. Não reaplicar a migração da demonstração nem
implantar `main` antiga por cima da versão atual.

## Publicação separada da landing

Durante esta revisão, o usuário autorizou explicitamente a publicação do PR #11,
commit `7f9e792d3acbc50bf253f8cc98b639ca4a5d6c46`. Foram aplicados somente
`resources/views/welcome.blade.php`, `public/marketing.css` e
`public/brand/vendra-vendas.jpg`. A produção passa a combinar a base SaaS
`a8f17e4` com esse overlay comercial. Os menus desta revisão não foram publicados.

A publicação utilizou `/run/lock/vendra-fiscal-deploy.lock`, drenagem natural do
worker, pausa somente do Vendra e de seu timer e backup completo verificado em
`/var/backups/vendra/20261116T145704Z-vCaNj1`, incluindo control plane. Os três
JPEG anteriores e `marketing.js` mantiveram seus hashes; 325 arquivos protegidos
permaneceram iguais. Serviço e timer retomaram o estado ativo.

Smoke HTTPS da landing, CSS/JS, quatro imagens e dois logins passou. O teste do
PR foi repetido contra o HTTPS real, encaminhado por túnel SSH temporário com
validação do certificado para o IP publicado: 320, 390, 768, 1024 e 1440 px,
quatro imagens 1200 × 760 decodificadas, sete CTAs, âncoras, teclado, FAQ, impressão,
nenhum overflow horizontal ou erro JavaScript. Cliques de teste foram cancelados,
sem envio de mensagem. O túnel foi encerrado ao concluir. Journal privado da
publicação: `/srv/vendra-landing-pr11.KVyLYa`.
