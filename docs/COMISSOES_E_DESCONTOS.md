# Comissões e descontos

## Estado da entrega

Implementado e publicado em 12/11/2026, com migrações aditivas 000012 e 000013. A suíte consolidada passou em checkout QA isolado: 65 testes e 1.011 verificações, sem falhas ou skips. A conferência de navegador após o deploy validou configurações de grupo/produto, bloqueio de redução manual fora da permissão, prévia de orçamento, relatório e exclusão da comissão do comprovante impresso. O histórico anterior foi preservado, sem retrocomissões. Detalhes das execuções em TESTES.md e VALIDACAO.md.

## Conceitos que a operação deve distinguir

- **Margem de formação do preço:** define o preço sugerido a partir do custo. Não é percentual de desconto nem percentual de comissão.
- **Desconto:** redução do preço bruto de referência até o total líquido efetivamente vendido. Reduz receita, sem alterar quantidades movimentadas ou o custo histórico dos itens.
- **Comissão apurada:** valor calculado e congelado por item na venda, conforme o percentual efetivo daquele momento.
- **Comissão proporcional ao recebido:** parte da comissão apurada correspondente ao recebimento acumulado da venda. É referência para conferência, não prova de pagamento ao vendedor.
- **Comissão paga:** desembolso real para o vendedor. Esta ampliação não cria pagamento, folha ou conta a pagar automática de comissão. Não deve exibir comissão apurada/elegível como “já paga”.

O cálculo da comissão não acrescenta sua despesa automaticamente ao custo de referência, custo médio, formação de preço, margem líquida ou DRE. Taxas/impostos e política de remuneração continuam separados.

## Configuração e herança independente

No grupo:

| Campo | Padrão | Efeito |
|---|---|---|
| `discount_enabled` | `false` | Desconto desabilitado, inclusive redução implícita por digitar preço menor que o catálogo. |
| `max_discount_percent` | `0` | Teto de redução efetiva permitido. Habilitar desconto com teto zero continua sem permitir redução positiva. |
| `commission_percent` | `0` | Não há comissão automática enquanto o administrador não definir um percentual. |

No produto/serviço unificado, cada um dos três campos é opcional (`null` = herdar seu respectivo campo do grupo). As heranças são independentes entre si e também da margem/política de preço.

Exemplos:

- Grupo permite desconto até 10%; produto com `discount_enabled=false` não permite desconto, mesmo herdando teto10.
- Produto com habilitação herdada e teto próprio5 usa a habilitação do grupo e teto5; ter teto positivo sozinho não habilita desconto se o grupo estiver desabilitado.
- Produto com comissão própria0 não recebe a comissão positiva do grupo; 0 é decisão explícita, não ausência de configuração.
- Serviço segue as mesmas regras de desconto/comissão, sem criar movimento de estoque por ser vendido.

Para ativar, o administrador escolhe o grupo, habilita descontos, informa teto e/ou percentual de comissão e salva; o produto pode manter herança ou receber exceções explícitas. A configuração nova vale para operações novas; não reescreve snapshots de vendas passadas.

## Bruto, líquido e limite de desconto

Para cada item, o servidor utiliza o preço de catálogo corrente e o preço unitário ofertado. O valor enviado como “catálogo visto” pelo navegador não é autoridade para substituir o catálogo do banco.

`referência unitária = maior(preço de catálogo, preço ofertado)`

`bruto do item = referência unitária × quantidade`

`líquido do item = preço ofertado × quantidade × (1 − desconto explícito/100)`

`desconto efetivo em valor = bruto − líquido`

`desconto efetivo percentual = (bruto − líquido) ÷ bruto × 100`, quando bruto for positivo.

A redução implícita por ofertar abaixo do catálogo e o desconto explícito entram **juntos no teto efetivo**. Não se pode contornar o limite reduzindo o preço unitário e deixando o campo de desconto em zero. Valores monetários finais e arredondamentos são calculados pelo domínio decimal no servidor.

| Catálogo | Oferta | Desconto explícito | Bruto por unidade | Líquido por unidade | Redução efetiva |
|---|---|---|---|---|---|
| 100,00 | 100,00 | 10% | 100,00 | 90,00 | 10% |
| 100,00 | 90,00 | 0% | 100,00 | 90,00 | 10% |
| 100,00 | 90,00 | 5% | 100,00 | 85,50 | 14,50% |
| 100,00 | 120,00 | 10% | 120,00 | 108,00 | 10% |

No terceiro exemplo, teto de 10% deve rejeitar a operação. Não se somam simplesmente10%+5%: o desconto explícito incide sobre a oferta90, resultando em redução combinada14,50%.

Quando descontos estão desabilitados, uma oferta acima do catálogo sem redução continua sendo possível; uma oferta abaixo do catálogo não é aceita como exceção silenciosa. As regras devem ser verificadas no servidor, mesmo sem controles visíveis na interface.

O teto é relativo ao preço de referência de catálogo/oferta, **não é proteção de custo mínimo**. Um desconto dentro do teto pode resultar em margem baixa ou negativa conforme o catálogo e o custo; a configuração precisa ser comercialmente responsável. Não anunciar o limite como garantia de lucro.

## Cálculo e snapshots de comissão

`comissão do item = líquido do item × comissão efetiva/100`, arredondada para dois decimais por item.

`comissão da venda = soma das comissões já arredondadas dos itens`.

Não aplicar o percentual sobre o bruto nem recalcular uma comissão global em substituição à soma dos snapshots. Exemplo: líquido200 e taxa5% produzem comissão10. Se o item recebeu desconto, a base é seu líquido reduzido, não o preço anterior.

A venda preserva vendedor e os dados comerciais necessários à conferência: bruto, desconto efetivo, líquido, percentual e valor de comissão por item e totais. Alterar grupo/produto/custo/preço ou comissão posteriormente não altera esses fatos.

### Vendedor — separação entre pessoa comercial e usuário

**Status: atribuição publicada e validada em 12/11/2026.** Esta seção substitui a regra inicial que tratava vendedor como o próprio registro de usuário. Migração 000013 aplicada, com preservação do histórico e sem atribuir documentos antigos por suposição.

#### Cadastro e identidades distintas

- Vendedor é representado pelo modelo `Seller`, cadastro independente de `User`; pode existir sem login. Ter comissão não concede acesso ao sistema.
- `salesperson_id` identifica quem recebe o crédito comercial; `user_id` identifica quem executou/registrou a operação. Os dois IDs pertencem a tabelas diferentes e não são intercambiáveis.
- Pode haver vínculo opcional do vendedor com um usuário. Para criar nova venda ou novo orçamento/OS, o operador usa somente seu perfil comercial vinculado; não escolhe outro por adulterar payload. Se não houver perfil válido/ativo, não se deve improvisar usando o número do User como ID de vendedor.
- Administrador pode selecionar qualquer vendedor ativo da mesma empresa, inclusive vendedor sem login. Vendedor inativo não é escolha para uma operação nova.
- Atribuição deve guardar ID e nome em snapshot, evitando que uma edição cadastral posterior reescreva o rótulo histórico ou junte créditos de duas pessoas com nomes iguais. Filtros e agrupamento usam ID, não apenas nome.

#### Documento guarda o crédito; conversão guarda o executor

Novo orçamento/OS seleciona e grava o vendedor comercial, com snapshot do nome. Ao gerar a venda, o vendedor é herdado automaticamente desse documento; não é substituído pelo usuário que apertou “gerar venda”.

Exemplo: vendedor Ana (`Salesperson A`) faz o orçamento; usuário Bruno (`User B`) autorizado conclui a OS e emite a venda. A venda mantém Ana como vendedora creditada e registra Bruno como executor em `user_id`. A comissão continua ligada a Ana, apurada com a política vigente na emissão da venda; não vira comissão de Bruno por ele executar a conversão.

A regra “operador só cria para seu próprio perfil” vale para escolher o proprietário comercial de uma **nova operação avulsa/documento**. Ela não autoriza reatribuir um documento existente ao converter. A conversão usa o proprietário aprovado carregado do banco, mantém as autorizações do fluxo e ignora/rejeita tentativa de sobrescrita pelo cliente HTTP.

#### Inativação e reatribuição explícita

- Se o vendedor do documento ficou inativo antes da emissão, bloquear a conversão e explicar a pendência; não transferir automaticamente o crédito para o executor, administrador ou criador.
- Antes de gerar a venda, administrador pode reatribuir a vendedor ativo mediante motivo obrigatório e registro de auditoria: documento, vendedor anterior/novo, snapshots, usuário autor, motivo e data.
- A reatribuição é uma decisão comercial explícita; não altera os itens/preços do acordo ou a autoria de criação do documento. O novo proprietário e seu nome ficam registrados no documento e são herdados na conversão.
- Depois de emitida a venda, a atribuição fica imutável no fluxo corrente. Inativar/renomear vendedor, alterar login ou editar documento não pode redirecionar comissão passada.
- Verificação de estado e atribuição precisa ocorrer na mesma transação/trava da conversão. Interface mostrando vendedor ativo alguns segundos antes não substitui a verificação no servidor.

#### Legado sem inferência de propriedade

- `sales.seller_id` antigo, FK de `User`, é preservado como dado legado. Backfill de `salesperson_id` só é admitido quando esse vendedor antigo é comprovado; não usar `sale.user_id` ou o criador como suposição de vendedor.
- Documento legado sem proprietário comercial não ganha vendedor por inferência do criador. Administrador deve vinculá-lo explicitamente antes da emissão, ou a compatibilidade de API deve validar o `seller_id` legado como **User**, resolver seu perfil comercial e registrar primeiro a propriedade autorizada.
- Nunca copiar o inteiro `seller_id` diretamente para `salesperson_id`: User17 pode ser uma pessoa diferente de Salesperson17. Se o cliente envia os dois campos e eles conflitam, rejeitar a ambiguidade em vez de escolher silenciosamente.
- Vendas históricas sem prova de proprietário permanecem identificadas como não vinculadas; não atribuir retroativamente para preencher relatório. Desativação não apaga vendedores ou snapshots já utilizados.

#### Incremento mínimo para conferência de comissão

Detalhes do documento/venda e consulta de comissão devem distinguir **“Vendedor creditado”** de **“Registrado por”**. Na conversão, exibir o vendedor herdado em vez de um seletor que sugira troca livre. Esse esclarecimento, junto ao snapshot e à auditoria da reatribuição, é necessário para conferir a comissão sem confundir login, executor e crédito comercial.

Isso não cria folha de pagamento, quitação de comissão, lançamento automático a pagar ou nova forma de cálculo. Comissão apurada/proporcional continua diferente de comissão efetivamente paga.

Critérios de integração: criar vendedor sem login; operador não atribui outro perfil em nova operação; administrador escolhe vendedor ativo; conversão por outro usuário preserva vendedor e separa executor; vendedor inativo bloqueia até reatribuição administrativa auditada; atribuição pós-emissão não muda; legado sem prova não é creditado; filtros por vendedor não juntam pessoas de mesmo nome.

## Orçamento e ordem de serviço

Na criação, itens do orçamento/OS devem passar pelas regras de desconto vigentes e preservar o acordo comercial. Aprovação e conversão não baixam estoque duas vezes.

Na conversão autorizada, o servidor carrega o documento aprovado/concluído do banco e honra o preço/desconto acordado, inclusive documentos legados sem a nova marca de acordo. Não depende de um `approved=true`, `agreement=true` ou de outro sinalizador enviado pelo navegador. A exceção não existe para venda avulsa fabricada pelo cliente HTTP.

A comissão é apurada com a regra vigente **na emissão da venda**, não como uma obrigação gerada ao abrir o orçamento. O preço acordado pode ser histórico e a comissão atual; a interface precisa explicar essa diferença em vez de fingir que os dois foram fixados na mesma data.

O documento respeita seu fluxo: orçamento aprovado ou OS concluída; emissão repetida retorna a mesma venda. O acordo de preço não autoriza saldo negativo, item inválido ou bypass de autorização.

## Relatório administrativo de comissões

A rota `/app/{empresa}/commissions` é exclusiva de administrador. O período é pela **data da venda**, com filtro opcional por vendedor. A seleção considera vendas concluídas com comissão positiva, usando os snapshots; não refaz o percentual a partir da configuração atual.

Os cartões de venda líquida/desconto desse relatório devem dizer que representam **as vendas com comissão dentro do filtro**, não toda a receita/desconto da empresa. Vendas sem comissão ficam fora dessa seleção.

Para cada venda:

`proporcional ao recebido = comissão apurada × recebido acumulado ÷ total líquido da venda`.

O recebido é limitado entre zero e o total da venda; total zero produz proporcional zero. O valor é arredondado no resultado acumulado, não em cada baixa parcial separadamente. Isso evita diferenças de centavos por fragmentar recebimentos.

Exemplo: venda líquida100, comissão10 e recebimento acumulado40 apresentam comissão apurada10, proporcional ao recebido4 e restante associado ao saldo6. A próxima baixa muda o acumulado; **não registra que4 foram pagos ao vendedor**.

O período filtra a origem da venda, não a data das baixas: uma venda de janeiro recebida em fevereiro pode ter seu proporcional atualizado quando janeiro for consultado depois. O relatório é uma visão atual de reconciliação das vendas do período, não um fechamento histórico imutável de comissões.

Canceladas são excluídas dos cálculos ativos sem apagar snapshots, pagamentos ou reversões. Cancelar não apaga um eventual pagamento real de comissão que tenha sido realizado fora do sistema; esse acerto continua sendo um procedimento financeiro separado.

## Critérios de aceitação para a integração

1. Grupo começa com desconto desabilitado/teto0/comissão0; produto nulo herda e produto0/false sobrescreve apenas o campo correspondente.
2. Redução implícita, explícita e combinada respeitam o teto;100→90 com5% explícito é14,50%, sem aceitar “só5%”.
3. Operador não burla teto ou vendedor por requisição direta; usuário de outra empresa/inativo não é vendedor válido.
4. Líquido e comissão fecham em centavos com quantidade fracionária, múltiplos itens e replay; alterar políticas depois não muda snapshots.
5. Orçamento/OS novo valida a política; a conversão honra o acordo carregado do DB e aplica comissão vigente à venda. Venda avulsa não aceita a exceção de acordo por payload.
6. Relatório usa data de venda e somente snapshots de concluídas; baixas parciais alteram o proporcional acumulado sem duplicar centavos ou lançar pagamento de comissão.
7. Cancelamento exclui dos cálculos ativos e preserva os registros; reemissão/replay não duplica comissão, estoque ou título.
8. Tela distingue bruto, desconto, líquido, comissão apurada e proporcional; não promete comissão paga nem margem líquida garantida.

## Três riscos concretos a conferir

- **Limite aparente:** validar apenas o desconto explícito permitiria burlar a política por preço unitário menor. A prévia precisa mostrar a redução total e o backend deve recusar o excesso.
- **Relatório interpretado como pagamento/período de caixa:** cards sem contexto poderiam sugerir comissão já paga, ou recebida apenas dentro do filtro. Rótulos devem explicitar data da venda, recebimento acumulado e ausência de desembolso automático.
- **Exceção de documento forjada:** confiar em flag enviada pelo navegador permitiria contornar políticas e vender como “acordo antigo”. Somente o documento aprovado no banco pode fornecer a condição, com comissão atual e regras transacionais preservadas.
