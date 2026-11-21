# Integrações de delivery

## Situação

Planejamento da conexão da Vendra com iFood, aiqfome e UaiRango. Os conectores dessas plataformas ainda precisam de implementação e homologação. Nenhum conector de marketplace está ativo nesta entrega.

As frentes de restaurante e supermercado compartilham cadastros, estoque e financeiro. A importação de delivery precisará identificar o canal de atendimento e os dados de retirada ou entrega. As comandas atuais trabalham com mesas. O pedido externo deverá ter um canal próprio para seguir até a produção e o fechamento.

## Acessos necessários

| Plataforma | Próxima ação | Confirmações exigidas antes da ativação |
| --- | --- | --- |
| iFood | Cadastrar a integração no portal de desenvolvedores e vincular um estabelecimento autorizado | Aplicativo, autorizações, ambientes disponíveis, contrato de eventos e critérios de homologação |
| aiqfome | Solicitar acesso técnico e autorização do estabelecimento | Versão da API aplicável, credenciais, identificação da loja, eventos, autenticação e processo de homologação |
| UaiRango | Solicitar acesso pelo portal técnico e vincular o estabelecimento | Credenciais, identificação da loja, ambientes, notificações e validação da integração |

A documentação e a habilitação comercial da conta devem confirmar os requisitos de cada conector. O sistema guardará as credenciais em armazenamento privado. A implantação exigirá autorização do responsável pelo estabelecimento.

Fontes oficiais para iniciar o cadastro e confirmar o contrato técnico vigente. [iFood Developer](https://developer.ifood.com.br/) · [aiqfome API](https://developer.aiqfome.com/docs/api/v2) · [UaiRango Developer](https://www.uairango.com/developer/home)

## Arquitetura proposta

Cada estabelecimento externo será associado explicitamente à empresa correta da Vendra. A identificação da empresa será resolvida pela configuração dessa associação. O corpo recebido da plataforma fornecerá o identificador externo do estabelecimento, que precisa corresponder ao vínculo autorizado.

Uma caixa de entrada persistente guardará o pedido por `empresa + plataforma + estabelecimento + pedido externo`. Uma segunda identificação única por evento impedirá a repetição de processamento. As transições de estado serão validadas a partir do estado atual, incluindo notificações repetidas, atrasadas ou fora de ordem.

O recebimento HTTP de um evento e o aceite comercial do pedido terão registros separados. A confirmação de recebimento ao provedor ocorrerá depois da gravação durável. O aceite, a rejeição e as atualizações operacionais usarão uma fila de saída com chave de repetição e possibilidade de retentativa. A escolha entre notificações e consulta periódica seguirá o contrato efetivamente habilitado por cada plataforma.

Cada adaptador implementará somente as capacidades autorizadas de seu provedor, como consultar pedido, receber eventos, confirmar recebimento, aceitar, rejeitar e atualizar andamento. A autenticação de callbacks será validada conforme a especificação daquele provedor antes de aceitar o evento. Sem configuração válida, a entrada ficará indisponível.

## Catálogo e operação

O responsável fará a correspondência entre os códigos externos e os produtos internos. Itens ou opções sem correspondência ficarão em conferência. A integração precisará preservar descrição, preço acordado, quantidades, observações e os complementos efetivamente suportados pelo catálogo da loja.

Pedidos confirmados seguirão o canal de retirada ou entrega e a produção de cozinha e bar. Cancelamentos terão motivo, origem, identificador e trilha de auditoria. Uma operação repetida deverá preservar uma única comanda, uma única venda e seus lançamentos de estoque e financeiro.

## Pagamentos e conciliação

O pedido deve distinguir pagamento recebido pela plataforma, pagamento a receber do consumidor e repasse devido ao estabelecimento. Valores pré-pagos à plataforma serão registrados com a respectiva origem e obrigação de repasse. O fechamento diário do dinheiro da loja deve refletir apenas os recebimentos efetivos naquele meio.

Taxas, comissões, ajustes e repasses serão conciliados com as informações disponíveis do provedor. Cancelamento e estorno exigirão referência ao pedido e à transação de origem, com controle de repetição. A confirmação de recebimento financeiro dependerá do contrato de pagamento e das evidências fornecidas pela plataforma.

## Critérios de liberação

- Autorização do estabelecimento, configuração privada e ambiente de validação conferidos.
- Associação de catálogo e tratamento de opções desconhecidas verificados.
- Testes de duplicidade, concorrência, reentrega de eventos, isolamento entre empresas e recuperação após falha aprovados.
- Fluxo completo de pedido, produção, venda, recebimento e conciliação validado no ambiente disponibilizado pelo provedor.
- Cancelamento, estorno e indisponibilidade do provedor tratados sem perda de pedidos ou duplicação financeira.
- Publicação gradual por estabelecimento, com auditoria e possibilidade de suspender o conector.

A ativação real fica dependente dos cadastros, credenciais, autorizações e homologações acima.
