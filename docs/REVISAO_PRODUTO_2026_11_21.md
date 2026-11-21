# Revisão de produto e operação — 21/11/2026

Revisão da loja, das frentes de restaurante e supermercado, da Central Vexo e da apresentação comercial. As referências são páginas e manuais públicos oficiais consultados nesta data. A avaliação da Vendra usa seu código, testes isolados e capturas de navegador; não houve acesso a contas privadas dos concorrentes.

## Critérios

- O operador precisa identificar a próxima ação sem entrar na retaguarda.
- Nome, código, quantidade, unidade e valor devem permanecer próximos na venda.
- Estado, confirmação e recuperação de erro devem ser claros, inclusive no celular.
- Uma consulta deve abrir o registro correspondente, preservando o contexto da empresa.
- Melhorias visuais precisam conservar permissões, preços, versões e registros financeiros no servidor.

## Comparação e aplicação

| Área | Referência pública | Evidência da referência | Aplicação na Vendra nesta revisão |
| --- | --- | --- | --- |
| Caixa e supermercado | [Bling — vendas no frente de caixa](https://ajuda.bling.com.br/hc/pt-br/articles/360035625414-Fazer-vendas-no-Frente-de-Caixa) | Documenta localização por parte do nome, SKU ou código de barras e definição da quantidade antes de inserir. | Entrada principal aceita código, SKU ou nome. Nome abre opções para escolha e conserva a quantidade; códigos exatos mantêm inserção rápida. |
| Garçom | [Saipos — comanda digital](https://saipos.com/sistema/comanda-digital) e [Consumer — categorias](https://consumer.com.br/sistema-categorias-produtos) | Apresentam cardápio categorizado e operação móvel; o Consumer descreve organização por categorias. | Cardápio dividido em grupos cadastrados, com atalhos, contagem e busca combinada. Grupo “Outros” cobre produtos sem grupo. |
| Ordens de serviço | [Omie — consulta de OS](https://ajuda.omie.com.br/pt-BR/articles/498956-consultando-as-ordens-de-servico-que-ja-cadastrei) e [Bling — preenchimento de OS](https://ajuda.bling.com.br/hc/pt-br/articles/360035679374-Preencher-uma-Ordem-de-Servi%C3%A7o) | Omie documenta consulta por etapas e por listagem. Bling distingue observações internas da impressão ao cliente. | A Vendra já filtra tipo, etapa, cliente e equipamento. Revisão inclui identificação de clientes homônimos e verificação da repetição de envio na criação. Um novo Kanban fica para uma evolução própria. |
| Compra e estoque | [Bling — importação de XML de entrada](https://ajuda.bling.com.br/hc/pt-br/articles/360036460513-Como-importar-o-XML-de-nota-de-entrada) | Descreve importação, conciliação de produtos e lançamentos de estoque e contas após conferência. | Revisão do caminho compra → contas a pagar, da unidade/saldo no lançamento e da mensagem sobre saldo negativo conforme a configuração real. A importação existente mantém sua revisão e confirmação. |
| Financeiro | [Omie — contas a receber](https://ajuda.omie.com.br/pt-BR/articles/6858019-consultando-as-contas-a-receber-que-cadastrei) e [resumo financeiro](https://ajuda.omie.com.br/pt-BR/articles/9260936-visualizando-e-entendendo-o-resumo-financeiro) | Mostram consulta por situação, vencimento e valores, com acesso ao detalhamento. | Compra abre seus próprios títulos. Histórico resolve as formas de pagamento em lote. Resumos gerais e lista filtrada conservam seu escopo identificado. |
| Central, contratos e acessos | [Asaas — usuários e permissões](https://central.ajuda.asaas.com/hc/pt-br/articles/32094727948315-Como-adicionar-editar-e-excluir-usu%C3%A1rios) | Documenta gestão de usuários e permissões por função. | Mantida separação entre administração, DBA da empresa e operação. Melhorias de navegação para a parcela exata, prioridade de pendências, filtros e situação de convites. A referência orienta a clareza do fluxo, sem pressupor identidade entre os produtos. |
| Landing page | Correspondência com os recursos implementados e a segmentação da Vendra | A apresentação anterior destacava principalmente comércio e serviços. | Quatro rotinas explícitas — comércio, assistência/serviços, restaurante e supermercado — com descrições dos recursos existentes e grade adaptável ao celular. |

## Ajustes desta revisão

### Operação

- Supermercado: pesquisa de nome no campo principal, seleção explícita mesmo com um resultado, conservação da quantidade e identificação de resultado vazio. A escolha continua passando pela validação de versão e propriedade do carrinho.
- Restaurante: grupos reais do cadastro, itens identificados por grupo, busca que combina texto e grupo, contagem e mensagem para busca sem resultado. Quantidade, destino e observação de preparo permanecem no item.
- Cliente: documentos e telefones disponíveis ajudam a distinguir nomes iguais quando o perfil pode consultar clientes.
- OS: o formulário informa que as observações aparecem no documento impresso entregue ao cliente. Repetição de criação verifica ator e conteúdo original com o registro de requisição já usado em vendas e compras. Reenvio igual conserva o documento e seu preço original; reenvio modificado é recusado. Documentos anteriores sem esse registro continuam consultáveis, editáveis e faturáveis; uma nova tentativa de criação com sua chave é recusada com orientação para conferir a operação existente.
- Estoque: apresentação da unidade e do saldo; texto de saldo negativo acompanha a parametrização da empresa.

### Gestão e Central

- Compra e financeiro: acesso aos títulos da compra e resolução em lote dos rótulos de pagamentos no histórico.
- Mensalidades: acesso direto à parcela e filtros de abertas/histórico, com pendências primeiro.
- Implantação: validade efetiva do convite, indicação de nova tentativa de envio, ligação para o histórico de e-mails e indicação de empresa suspensa.
- E-mails: filtro por tipo de mensagem, mantido ao abrir e voltar dos detalhes.
- Autenticação da Central: controle de visibilidade da senha usando o componente existente.
- Apresentação: instrução de implantação alinhada ao convite por e-mail e landing cobrindo os quatro segmentos.

## Prioridades identificadas na revisão inicial

Atualização posterior nesta data: ficha técnica, adicionais, recebimentos parciais, divisão de conta, destino padrão, supervisão de caixa e recuperação de rascunhos foram implementados. Consulte `OPERACAO_PILOTO_SEM_NF.md`. Integrações reais e dispositivos externos continuam dependendo das habilitações descritas naquele documento.

| Prioridade | Evolução | Resultado esperado e dependências |
| --- | --- | --- |
| Alta | Ficha técnica e adicionais no restaurante | Composição e consumo de ingredientes, destino padrão de preparo, opções e preços de adicionais. Exige regras de estoque/custo próprias e cobertura de cancelamento. |
| Alta | Integrações de delivery | Entrada e atualização de pedidos em fila auditável. Depende do cadastro e da homologação de iFood, aiqfome e Uai Rango; o plano está em `INTEGRACOES_DELIVERY.md`. |
| Alta | Rotina contínua de cozinha e salão | Atualizar estados sem perder a edição do pedido, com indicador de conectividade e tratamento de sessão expirada. |
| Alta | Dispositivos e pagamento de supermercado | Validar leitores, etiquetas e impressoras com equipamentos reais; TEF, balança direta e operação offline exigem projetos específicos. |
| Média | Pesquisa de compras e movimentações | Filtros de fornecedor, referência, produto e período; conservar o contexto ao abrir documentos e voltar à lista. |
| Média | Anotações internas da OS | Separar o que será entregue ao cliente de notas privadas do atendimento, com permissões e impressão próprias. |
| Média | Catálogos e clientes em volume | Pesquisa paginada no servidor e seletores com identificação suficiente; medir desempenho com catálogos e históricos maiores. |
| Média | OS em painel por etapa | Complementar filtros/lista com um painel de acompanhamento, mantendo as transições autorizadas e a emissão da própria OS. |
| Média | Métricas operacionais por segmento | Tempos de preparo e atendimento, desempenho de caixa, produtos e margens com definições consistentes. |
| Em homologação externa | Pix de mensalidades C6 | Habilitação e aprovação bancária, autenticação, retorno e baixa automática. A emissão de produção permanece pausada conforme a configuração autorizada. |
| V2 | Emissão de NF de produto | Projeto fiscal posterior. A revisão de UX mantém o escopo atual de emissão de serviço. |

## Validação e publicação

A validação da versão exige testes de domínio e HTTP, conferência de navegador em celular, tablet e desktop e suíte integral em banco isolado. A publicação preserva as quatro contas administrativas sincronizadas, o acesso do Ivan, os dados das lojas e a pausa de emissão Pix. O resultado da execução e o commit ficam vinculados na evidência de QA da versão e no histórico Git. As capturas e os testes de interface usam dados de exemplo; a validação de dispositivos físicos segue as dependências indicadas acima.
