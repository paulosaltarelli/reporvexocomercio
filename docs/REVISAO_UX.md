# Revisão UX/UI — Vendra

Revisão do estado atual em 12/11/2026: catálogo unificado, regras de preço, logo, financeiro, caixa, previsão, dashboard e página comercial.

## Ajustes aplicados

- Menu reorganizado em **Cadastros, Operações, Financeiro, Relatórios e Administração**, com Visão geral direta. O grupo da rota atual permanece aberto e destacado. Produtos e serviços compartilham um único destino; o filtro do catálogo distingue os tipos. Recursos administrativos continuam ausentes para operadores.
- Dashboard administrativo ganhou uma série de vendas de 14 dias. Usa vendas concluídas reais, inclui dias zerados, exclui cancelamentos e mostra os valores exatos em tabela expansível. O acumulado geral permanece identificado separadamente do período do gráfico.
- A previsão financeira ganhou uma linha do saldo ao fim de cada dia do horizonte. A referência zero é explícita, e previsão não é apresentada como dinheiro recebido. Todos os valores da série estão disponíveis na tabela diária, inclusive dias sem compromissos.
- Filtros de caixa e projeção passaram a ter organização vertical no celular. A data e a situação da conferência ficam explícitas, inclusive ao imprimir.
- Na contagem do caixa, há prévia da diferença entre contado e esperado. Usa centavos inteiros e não altera o fechamento: o servidor continua calculando o resultado definitivo.
- Histórico e detalhes secundários ficam recolhidos. Antes da impressão, detalhes de leitura são abertos temporariamente e depois restaurados; formulários e ações não são abertos. O histórico de outros fechamentos não compõe a impressão do dia atual.
- Tabelas horizontais recebem acesso por teclado também depois de abrir um detalhe ou redimensionar a tela. Nomes acessíveis específicos já existentes são preservados.
- Os estilos existentes do cabeçalho empresarial e do logotipo de impressão foram preservados. Os ajustes desta revisão foram acrescentados ao fim do CSS.

## Onde os gráficos agregam valor

**Vendas por dia:** responde se o ritmo recente está aumentando, diminuindo ou concentrado em poucos dias. Não confunde venda com baixa financeira.

**Saldo projetado:** evidencia quando os compromissos podem levar o saldo para baixo de zero. Complementa o valor final da projeção, que sozinho esconde quedas intermediárias.

Não foram espalhados gráficos por estoque, preços, compras ou caixa diário. Nessas telas, o próximo passo depende mais de um saldo, uma divergência e uma ação clara do que de uma visualização decorativa. Não há pizza misturando dinheiro físico, Pix e cartão, pois isso poderia sugerir um saldo de gaveta incorreto.

## Pontos preservados

- As prévias de preço respeitam a política automática/alerta e a base de custo do catálogo unificado.
- Custos, margens e regras não são expostos ao operador.
- A interface não anuncia emissão fiscal, integração bancária nem movimentação automática de dinheiro.
- A landing usa recursos reais e identifica a representação ilustrativa; não há testemunhos, números comerciais ou preços inventados.

## Validação e próximos cuidados

Smoke local em Chromium: 1440, 768, 390 e 320 px sem rolagem horizontal do documento; cinco grupos de menu, alternância móvel, layout dos gráficos, moeda negativa e restauração segura do estado de impressão passaram. As séries usadas no produto vêm do backend; fixtures foram usadas apenas no teste local de layout.

A validação Blade/Laravel e a conferência no ambiente real cabem ao QA da integração. Recomenda-se validar com uma pessoa que opere o balcão e outra que faça o fechamento, com teclado e leitor de tela, antes de adicionar mais indicadores. Os gráficos usam floats somente para coordenadas; totais financeiros e valores originais permanecem fornecidos pelo servidor.

## Revisão posterior: visão anual e regras comerciais

O gráfico anterior de 14 dias foi substituído por 12 meses e seletor de ano. Barras azuis em gradiente, destaque ciano do maior mês, total anual, quantidade de vendas e tabela acessível representam os dados reais do período. O teste de navegador confirmou a visão completa de 2025 e a alternância para 2026. As regras de desconto/comissão ficam em disclosures separados da formação de preço, com herança explícita e comissão interna excluída do comprovante do cliente.
