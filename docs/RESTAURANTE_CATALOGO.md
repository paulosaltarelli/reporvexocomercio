# Cardápio, adicionais e ficha técnica

O cardápio administrativo exige `restaurant.catalog.manage` e o modo restaurante habilitado. Empresas e produtos existentes continuam usando as regras anteriores até uma configuração explícita.

## Configuração

Em **Cardápio e fichas técnicas**, escolha um produto e configure:

- Destino padrão: cozinha ou bar. A equipe pode selecionar outro destino ao lançar o pedido.
- Exibir no cardápio: desmarque para ingredientes e insumos. Produtos ocultos continuam disponíveis nas fichas, mas não podem ser lançados na comanda por um identificador enviado manualmente. Produtos sem configuração anterior continuam visíveis.
- Grupos de adicionais: mínimo e máximo de escolhas, opções disponíveis e preço adicional por unidade do produto. Mínimo maior que zero torna a seleção obrigatória.
- Consumo opcional de ingrediente por adicional, além dos ingredientes da ficha principal.
- Estoque: regra do próprio produto ou somente os ingredientes da ficha técnica.

Para usar ficha técnica, habilite a gestão de estoque da empresa. O produto preparado precisa estar com saldo zero e sem controle próprio; os ingredientes precisam ser produtos ativos com controle de estoque. Essa separação impede baixar simultaneamente o prato e seus ingredientes. Ingredientes não podem ser outras fichas técnicas.

Informe quantidades por uma unidade do produto preparado. Conversões utilizam as unidades/equivalências já cadastradas para o ingrediente. O resultado precisa ser exato em três casas na unidade de estoque; quantidades menores exigem cadastrar uma unidade de estoque mais adequada, em vez de arredondar silenciosamente.

## Durante o atendimento

O servidor valida os adicionais permitidos e os limites de cada grupo. O preço unitário registrado já inclui os adicionais. A tela não deve acrescentar esses valores novamente ao total confirmado.

Cada lançamento congela nomes, preços, identificadores dos adicionais, revisão da ficha, quantidades dos ingredientes e suas unidades-base. Alterar ficha, preços ou equivalências afeta somente novos lançamentos. Repetir a mesma tentativa não consulta novamente o preço dos adicionais.

Lançar ou enviar pedidos à produção não movimenta estoque. No fechamento da venda, o Checkout utiliza a ficha congelada, valida a unidade e o controle dos ingredientes e registra o consumo com o custo real apurado pelo estoque. O custo estimado da ficha administrativa aparece somente para perfis com `costs.view`.

Se faltarem ingredientes e a política bloquear estoque negativo, a venda inteira é revertida pela transação: não ficam venda, baixa parcial ou títulos financeiros. Se o estoque for desabilitado depois do lançamento, o fechamento de itens com ingredientes é bloqueado até revisar a configuração.

O cancelamento da venda utiliza os movimentos originais de ingredientes e sua valorização, nunca a ficha atual. Repetir o cancelamento não cria novos estornos. Alterações incompatíveis na unidade ou no controle de estoque do ingrediente devem ser corrigidas antes do estorno.

Quando um produto pronto recebe um adicional com ingrediente, o custo unitário da venda inclui ambos. O custo original do estoque próprio fica preservado na auditoria para que seu estorno não incorpore novamente o ingrediente à média do produto. O cancelamento revalida a permissão vigente dentro da transação.

## Contratos e limites

- API interna: `RestaurantCatalog::publicCatalog`, `quote` e `save`; `RecipeInventory::normalizeIngredients`, `quantities`, `consume` e `cancel`.
- Snapshot na comanda: `commercial_terms._restaurant_catalog`, com `addons` e `recipe`. A resposta pública do cardápio não expõe custos nem ingredientes.
- Migração aditiva: `2026_11_21_000301_create_restaurant_catalog`, com três tabelas próprias e sem alterar colunas legadas.
- Até 12 grupos por produto, 30 opções por grupo, 20 escolhas por grupo, 50 opções escolhidas por lançamento e 50 ingredientes por ficha. Uma opção pode ser escolhida uma vez por lançamento e é multiplicada pela quantidade do produto.
- Produtos com ficha técnica também utilizam seus ingredientes-base ao confirmar vendas no comércio e supermercado. Produtos com escolhas obrigatórias precisam ser lançados na comanda. Esses canais resolvem a ficha no servidor e não aceitam receitas enviadas pelo navegador. Ocultar um ingrediente do cardápio não impede sua venda normal com controle de estoque nos demais canais.

Não há ativação automática, cálculo tributário, emissão de nota fiscal ou integração com equipamentos neste recurso.
