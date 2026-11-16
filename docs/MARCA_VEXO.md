# Marca institucional Vexo

Vexo é a empresa desenvolvedora. Vendra é o produto de gestão. A assinatura do
produto permanece “Vendra por Vexo”; a central administrativa usa a marca Vexo.

## Originais fornecidos

O usuário forneceu `vexo.zip`. Foram copiados sem edição, corte, recompressão ou
recriação os seguintes PNGs:

| Arquivo público | Original no ZIP | Uso |
| --- | --- | --- |
| `brand/vexo-logo-dark.png` | `Imagem do ChatGPT 16 de nov. de 2026, 12_07_24.png` | Marca com texto escuro e transparência, sobre fundo claro |
| `brand/vexo-logo-light.png` | `Imagem do ChatGPT 16 de nov. de 2026, 12_08_15.png` | Marca branca sobre fundo escuro |
| `brand/vexo-symbol-navy.png` | `Imagem do ChatGPT 16 de nov. de 2026, 12_07_19.png` | Ícone da central |

O ZIP também tinha uma cópia idêntica da logo horizontal e uma prancha de
apresentação. Esses dois arquivos não foram adicionados aos assets públicos.
As logos horizontais têm 2172 × 724 px; o símbolo, 1254 × 1254 px.
As dimensões de exibição são definidas em `public/brand.css`, preservando a proporção.

## Aplicação

- Landing: Vendra continua como nome do produto; a assinatura usa a logo Vexo,
  e o rodapé identifica a empresa com sua logo institucional.
- Central: logo Vexo no cabeçalho e símbolo como favicon.
- ERP: Vendra no login e no menu; logo Vexo na assinatura e no rodapé.
- A logo da empresa cliente, seus relatórios e suas configurações permanecem próprios.

## Base e validação

Base reconciliada: SaaS `a8f17e4` e landing `7f9e792`, sem a revisão de menus do PR #12.
A baseline dos quatro templates foi conferida com a produção antes de preparar o overlay.

- Testes Laravel existentes: 3 testes, 101 assertions, sem falhas/skips, em 37,298 s.
- Chromium com páginas Laravel de QA: 20 combinações de tela/largura, em 320,
  390, 768 e 1440 px; logos decodificadas e com proporções válidas, sem overflow
  horizontal ou erros JavaScript.
- Teste completo da landing: cinco larguras, imagens, CTAs, âncoras, menu, FAQ,
  eventos, impressão e ausência de requisições externas, sem falhas.
- O QA usa checkout e bancos de teste próprios; não foram criadas sessões de
  clientes ou alteradas senhas para verificar telas.

## Overlay de publicação

Somente quatro templates (`welcome`, `layout`, `auth/login`, `platform/layout`),
`public/brand.css` e os três PNGs originais. O procedimento usa lock compartilhado,
backup completo verificado, comparação de hashes e rollback restrito. Apenas os
caches Blade dessas quatro views podem ser invalidados. Sem migração, alteração
fiscal, troca de logo de cliente ou atualização de menus.
