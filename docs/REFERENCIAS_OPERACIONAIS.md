# Referências para as frentes operacionais

Consulta em 21/11/2026. Estudo de páginas e manuais públicos oficiais, acompanhado de leitura do código da Vendra. Não houve acesso a instalações, contas pagas ou testes práticos dos concorrentes. As descrições abaixo registram o que as fontes apresentam; os ajustes são recomendações próprias para a Vendra, sem reproduzir marcas, textos comerciais ou layouts.

## Padrões observados e aplicação

| Fonte oficial | Observação verificável | Situação e ajuste recomendado para a Vendra |
| --- | --- | --- |
| [Consumer — controle de mesas](https://consumer.com.br/sistema-controle-mesa) | Apresenta mapa com estados das mesas, tempo de ocupação e continuidade entre garçom, produção e fechamento. | Já há mesas e uma comanda aberta por mesa. Explicitar **Livre/Ocupada** e tempo desde a abertura; manter abertura/consulta como ação principal do card. Não criar reservas ou “aguardando pagamento” sem estado de negócio correspondente. |
| [Saipos — comanda digital](https://saipos.com/sistema/comanda-digital) | Destaca operação em celular/tablet, cardápio categorizado e encaminhamento dos pedidos à cozinha/copa. | Já há filtro por categoria, busca, observações e destino cozinha/bar. Priorizar alvos de toque, identificação da mesa e resumo do pedido; tornar categorias fáceis de alcançar junto à busca e às imagens dos produtos. |
| [Saipos — KDS](https://saipos.com/sistema/restaurante/monitor-kds-para-restaurante) | Descreve pedidos do PDV exibidos na cozinha para organizar preparo e comunicação com o salão. | O KDS já separa fila, preparo e prontos. Adicionar tempo desde o envio e conservar mesa, quantidade, observação e próxima ação em destaque. O relógio é recomendação nossa; a página consultada não comprova como o concorrente implementa cronômetros. |
| [Arius Loja](https://arius.com.br/arius-loja/) | Apresenta frentes distintas para teclado de operador, toque, autoatendimento e dispositivos móveis, com foco no varejo de autosserviço. | Manter o supermercado como frente dedicada, sem menus administrativos para o caixa. Favorecer leitura contínua, confirmação do item e total visível. TEF, equipamentos e operação offline dependem de projetos próprios; não decorrem da semelhança visual. |
| [Linx Empório POS — manual atual](https://share.linx.com.br/pages/viewpage.action?pageId=417834540) | Documenta busca por nome/código/código de barras, categorias, atalhos e conferência de quantidade, valor unitário e total. | Preservar scanner como foco principal, busca secundária e atalhos visíveis. Destacar confirmação do lançamento e unidade/peso. A Vendra mantém seus próprios F2/F9; familiaridade do fluxo não exige copiar as teclas do concorrente. |

A URL Linx inicialmente indicada (`pageId=469320604&navigatingVersions=true`) identifica uma versão antiga. A análise também seguiu o vínculo oficial para a versão atual acima.

## Três melhorias imediatas de baixo risco

1. **Salão e cardápio mais legíveis.** Mesas com “Livre” ou “Ocupada”, número, total e idade do atendimento. Categorias junto à busca, produtos com imagem e nome legível, resumo da mesa acessível no celular. Usar dados já disponíveis e preservar validação do servidor ao abrir/transferir mesas.
2. **Produção com referência de tempo.** Mostrar “Há X min desde envio” a partir de `sent_at`, além do horário completo e destino cozinha/bar. O contador pode atualizar localmente; pedidos novos continuam dependendo da atualização da tela enquanto não houver mecanismo de sincronização. Não chamar o item de atrasado sem uma regra de prazo configurada.
3. **Caixa com confirmação imediata.** Código e quantidade próximos, informação do último lançamento confirmado, total destacado e F2 para leitura/F9 para recebimento. Manter a lista completa e vendas suspensas acessíveis, sem misturar administração. A repetição de um SKU pode somar quantidade na mesma linha: a última posição do array não prova qual foi o último item lido. Usar confirmação do servidor ou nomear o destaque de forma fiel, sem inventar uma leitura.

## Aceitação e limites

- Em celular e tablet: mesa identificável, botões principais com alvo de toque de pelo menos 44 px, categorias e resumo sem sobreposição ou rolagem horizontal da página. Estado comunicado por texto, além da cor.
- KDS: tempo calculado sobre envio, nunca negativo; ausência de data não produz tempo fictício. Cozinha prepara/marca pronto; entrega continua sendo ação autorizada do atendimento.
- Caixa: teclado e leitor continuam funcionando após erro e após lançamento; F9 abre recebimento sem confirmar venda; foco não é roubado de campos de pagamento durante edição. Quantidade fracionada e unidade permanecem explícitas.
- Nova apresentação não amplia permissões: garçom, cozinha e caixa conservam painéis e URLs restritos; isolamento por empresa, vínculo do atendimento e bloqueio contratual continuam aplicados no servidor.
- Revalidar leitura repetida do mesmo SKU, etiqueta pesável, venda suspensa/retomada, erro de validação e ausência de JavaScript. Imagens não podem tornar o cadastro de produto obrigatório nem impedir leitura por código.

Dividir os valores entre formas de pagamento **ao fechar** já tem contrato próprio. Pagamento parcial com comanda ainda aberta, divisão por pessoa/item, ficha técnica, integração com equipamentos, delivery e funcionamento offline não devem ser apresentados como implementados por este refinamento.

## Pontos do código comparados

`resources/views/restaurant/{index,order,production}.blade.php`, `resources/views/supermarket/index.blade.php`, `public/operational-fronts.js`, `RestaurantController` e `SupermarketController`. Esses pontos descrevem o estado examinado durante o estudo; a aplicação visual segue a frente de interfaces e sua validação própria.
