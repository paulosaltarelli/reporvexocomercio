# Operação de restaurante e supermercado sem emissão fiscal

## Escopo desta evolução

Este pacote amplia a operação e conserva a emissão fiscal fora desta etapa. Os recebimentos, pagamentos e estornos registrados são confirmações manuais do operador sobre valores efetivamente movimentados. Eles não comandam a maquininha nem transferem dinheiro no banco.

## Restaurante

### Cardápio, adicionais e preparo

Em **Atendimento → Cardápio e fichas técnicas**, configure cada produto:

- Exibição no cardápio, permitindo manter ingredientes internos fora da tela do garçom.
- Destino padrão de preparo, cozinha ou bar.
- Grupos de adicionais, quantidade mínima/máxima de escolhas e preço por opção.
- Ingrediente opcional de cada adicional, com quantidade e unidade próprias.
- Ficha técnica do prato, incluindo ingredientes e equivalências cadastradas.

Produtos preparados por ficha técnica não controlam simultaneamente saldo próprio. Ingredientes precisam estar ativos e ter controle de estoque. A ficha pode usar unidades vinculadas ao produto; a conversão precisa ser representável com as três casas decimais do estoque. O sistema rejeita arredondamentos silenciosos e receitas recursivas.

As opções e os preços acordados ficam registrados no lançamento da comanda. Modificações posteriores do cardápio não alteram pedidos e comprovantes anteriores. O estoque é consumido ao confirmar a venda; o cancelamento devolve as quantidades e os custos originais uma única vez.

Uma receita base também pode ser vendida nos demais canais habilitados da empresa. Nesse caso, a ficha é resolvida no servidor. Produtos que exigem escolhas obrigatórias precisam ser lançados pela comanda, que apresenta essas opções. Dados de receita enviados pelo navegador não autorizam consumo nem alteram custo.

### Divisão e recebimentos durante o atendimento

O caixa pode receber por valor, por pessoa ou por itens e quantidades. Divisão por pessoas distribui os centavos sem alterar o total: R$ 100,00 para três pessoas resulta em R$ 33,33, R$ 33,33 e R$ 33,34.

Cada recebimento real cria seu próprio registro financeiro e movimento de caixa. No fechamento, esses registros são vinculados à venda, sem uma segunda baixa. O plano final cobre somente o saldo restante. Taxas e troco seguem a forma de pagamento configurada.

Recebimentos e divisões exigem `restaurant.receive`; estornos exigem `restaurant.refund`. O perfil de caixa de restaurante recebe a primeira capacidade por dependência do fechamento. O perfil de garçom permanece dedicado ao atendimento.

Antes do fechamento, o responsável pode registrar um estorno confirmado. A despesa original de cartão permanece registrada, pois não se presume que a adquirente devolveu sua taxa. Após o fechamento, aplica-se o fluxo da venda. Itens pagos ou alocados não podem ser cancelados de modo a deixar recebimentos acima do consumo.

### Atualização de salão e produção

Salão, comanda e cozinha consultam uma revisão do atendimento periodicamente enquanto a página está visível e online. A revisão é derivada dos mesmos registros apresentados na tela.

Uma edição em andamento é preservada. A tela indica quando existem novidades e oferece atualização; a atualização automática ocorre apenas quando não há edição ou foco que possa ser perdido. Sessão expirada, acesso alterado e falta de conexão são informados sem executar mutações ou repetir pedidos.

## Supermercado

### Autorizações de supervisor

O operador solicita uma alteração com motivo. O supervisor decide no próprio acesso; a senha do supervisor não é digitada na sessão de outro operador.

A autorização fica vinculada ao operador, carrinho, versão, ação e conteúdo solicitado. Tem validade de quinze minutos e é consumida uma vez. Mudança no carrinho, expiração, desativação do supervisor ou redução do seu limite impede o uso da autorização antiga.

Descontos respeitam o limite do perfil do supervisor e as regras do produto/grupo. Redução de quantidade, retirada de item e cancelamento seguem o mesmo controle auditável. O perfil **Supervisor de supermercado** permite operar essa frente sem conceder acesso geral ao ERP.

### Queda de conexão e recuperação

A página pode guardar leituras como rascunhos da aba, identificados por empresa, operador e carrinho. São guardados somente códigos, quantidades e identificadores de repetição. Dados de clientes e pagamento não fazem parte desse armazenamento.

Ao voltar a conexão, a conferência e a importação usam os preços, unidades e validações do servidor. O reenvio dos mesmos identificadores não duplica itens. Um lote inválido é recusado de forma transacional, mantendo os rascunhos para correção.

O fechamento permanece bloqueado enquanto houver leituras locais pendentes, importação em andamento ou atualização obrigatória. Rascunho offline não é venda concluída nem recebimento confirmado. Armazenamento indisponível é informado; o operador pode optar explicitamente pela operação somente online após conferência manual. Um conteúdo corrompido exige descarte confirmado, sem apagar silenciosamente leituras desconhecidas.

## Dispositivos e impressão

Em **Configurações → Dispositivos e impressão**, escolha o padrão de bobina de 80 ou 58 mm, identifique a impressora e abra um documento de teste. A preferência de cada computador continua disponível na tela de impressão.

Leitor que funciona como teclado, digitação de peso e etiqueta de balança são os meios disponíveis. A Epson TM-T20X-II USB usa o driver instalado e a janela de impressão do navegador. O teste não registra venda, estoque ou caixa. Os documentos de atendimento e comprovantes continuam mostrando o telefone do cliente quando cadastrado.

Os modelos da balança e da maquininha ainda não foram identificados. Balança direta, TEF, SmartPOS e validação física dependem desses equipamentos e dos contratos do fornecedor. Não há indicação de conexão ativa com um dispositivo que ainda não foi homologado.

## Delivery e provedores externos

A área **Integrações de pedidos** contém mapeamento de catálogo, conferência de preços, registros persistentes de eventos e pedidos, controle de repetição e retentativas limitadas.

As operações de teste são explicitamente simuladas e isoladas. Elas não criam venda, baixa de estoque, recebimento ou pedido na cozinha real. O andamento pode ser validado nessa área para conferir o mapeamento e os estados.

iFood, aiqfome e Uai Rango permanecem aguardando cadastro, credenciais, autorização do estabelecimento e homologação. Nenhum webhook público ou conector real é habilitado por esse pacote. Os contratos e as fontes oficiais estão em `DELIVERY_INTEGRACOES.md`.

## Verificação de liberação

- Recebimento, divisão, taxa, estorno, repetição e concorrência sem duplicidade financeira.
- Ficha, conversão, custo, insuficiência de estoque e cancelamento com os valores originais.
- Autorizações revogadas, expiradas ou modificadas recusadas.
- Recuperação de leituras e bloqueio do fechamento com rascunhos pendentes.
- Permissões e dados separados entre empresas, inclusive nas novas tabelas compartilhadas.
- Conferência em celular, tablet e desktop e suíte integral em banco isolado.
- Migrações aditivas, backup, verificação do manifesto e reversão estrutural que preserve dados.

As credenciais administrativas existentes, o acesso do Ivan e a pausa de emissão Pix em produção são preservados pela publicação. As lojas de teste recebem exemplos isolados e identificados; configurações de clientes reais não são ativadas automaticamente.
