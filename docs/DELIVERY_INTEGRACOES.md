# Integrações de pedidos delivery — base de homologação

## Entrega disponível

A tela **Integrações de pedidos**, no modo restaurante e mediante permissão, prepara referências da loja, o vínculo entre catálogo externo e interno e pedidos **simulados** com dados fictícios. iFood, aiqfome e UaiRango permanecem **Aguardando habilitação**. Não há autenticação externa, consulta remota, assinatura de mensagens ou confirmação de pedidos reais nesta versão.

A importação autenticada manual de JSON utiliza a mesma base persistente de inbox/pedido/outbox preparada para conectores futuros. Cada loja precisa habilitar explicitamente os testes de cada canal. O campo `enabled` nunca é aceito pelo formulário/serviço e é salvo como falso. `Providers::liveAvailable()` retorna falso independentemente de flags alteradas no banco.

Não existe webhook público. Nenhum `tenant_id`, usuário ou situação de pagamento é inferido de parâmetros de autorização enviados em um evento externo. A empresa é resolvida pela conexão validada da sessão autenticada; as tabelas são escopadas pelo mesmo isolamento do restante do sistema.

## Fluxo de teste

1. Habilitar importações de teste para o canal; o provedor continua desconectado.
2. Vincular os códigos externos a produtos ativos e ao destino de produção de teste (cozinha/bar).
3. Importar o JSON de exemplo da tela. Informar `event_id` e `external_id` únicos no canal da empresa.
4. Conferir cliente, telefone, endereço, quantidade, preço externo e preço atual do catálogo.
5. Aceitar. Itens sem vínculo/ativos impedem aceitação; divergência de preço requer confirmação explícita e justificativa. O pedido preserva o snapshot do preço recebido, do vínculo e do preço do catálogo no aceite.
6. Enviar à produção **simulada**, iniciar preparo e marcar cada item pronto dentro do próprio pedido. A fila real da cozinha não consulta esses itens.
7. Concluir entrega simulada. Recusa e cancelamento possuem motivo e ficam no histórico.

Todos os passos revalidam a permissão do ator na empresa, inclusive a concessão DBA e sua revogação. Mudanças usam transações, bloqueio operacional, versão otimista e auditoria. Repetir uma transição com a mesma chave mantém o resultado; trocar o conteúdo da chave falha.

## Inbox, outbox e reprocessamento

- `delivery_orders`: unicidade `(provider, simulation, external_id)` para manter identidades de teste separadas das reais.
- `delivery_events`: unicidade `(provider, direction, simulation, event_key)` pelo mesmo motivo.
- Mesmo evento e snapshot retornam o pedido existente. Um novo evento com o mesmo pedido e snapshot não duplica itens. Reuso de qualquer identidade com conteúdo divergente falha; não sobrescreve o pedido.
- O snapshot normaliza valores decimais, calcula itens/entrega e verifica o total. O hash representa o conteúdo sem a chave do evento, para deduplicar notificações do mesmo pedido.
- Saídas de teste possuem status `simulated`, zero tentativas externas e nenhuma chamada HTTP.
- `Outbox::retry` recusa eventos simulados/concluídos, aplica teto de **cinco** tentativas e verifica `next_attempt_at`. Provedores desabilitados ou sem adaptador ficam bloqueados sem consumir tentativas nem fazer chamadas.
- A política de intervalos prevista é 30 s, 120 s, 600 s, 1.800 s e 3.600 s. O worker/transportador real deve registrar tentativas e agenda após classificação de erros, com lease/bloqueio e idempotência do provedor; não há worker enviando mensagens nesta entrega.

## Financeiro e estoque

Nenhuma simulação gera `Sale`, `Payment`, `FinancialEntry` ou movimento de estoque. O cenário `prepaid` mostra **repasse esperado simulado**, líquido da taxa informada, e não dinheiro recebido no caixa. O cenário `unpaid` mostra recebimento pendente, sem baixa automática. Cancelar/concluir testes não estorna nem movimenta operações reais.

**Canal manual real e integrações financeiras externas continuam pendentes.** Um canal `manual` real deverá usar preços/adicionais do servidor, pedido de retirada/entrega sem mesa e checkout idempotente com as formas de recebimento existentes. Integrações marketplace precisarão gerar recebível de repasse, taxas identificadas e reconciliação; pagamento externo pré-pago não pode virar recebimento de caixa. Não implementar isso reutilizando um pedido de simulação ou simplesmente alterando seu `simulation`.

## Habilitação oficial — dependências externas

Fontes oficiais consultadas em 21/11/2026:

- iFood: [autenticação](https://developer.ifood.com.br/en-US/docs/food/guides/modules/authentication/intro), [assinatura de webhook](https://developer.ifood.com.br/en-US/docs/food/guides/modules/events/webhook-signature), [homologação](https://developer.ifood.com.br/en-US/docs/food/guides/modules/events/homologation). A documentação indexada exige verificação de assinatura `X-IFood-Signature` e validação na homologação. A leitura direta da página de assinatura retornou HTTP 403 durante a revisão; nenhum algoritmo ou conector foi implementado com base em suposição.
- aiqfome: [credenciais no portal](https://developer.aiqfome.com/docs/guides/get-started/step-1), [token de acesso](https://developer.aiqfome.com/docs/guides/get-started/step-2), [API V2](https://developer.aiqfome.com/docs/api/v2). O portal descreve ID Magalu, cadastro de aplicativo, Client ID, Client Secret e consentimento. Tokens/segredos deverão ser protegidos e separados por ambiente e autorização da loja.
- UaiRango: [Connect oficial](https://www.uairango.com/connect/) e [área de desenvolvedor](https://www.uairango.com/developer/login). O portal anuncia integração, mas a documentação aplicável depende de acesso. Não foram inferidos endpoints, autenticação ou assinatura de outras plataformas.

Para conectar um provedor: obter acesso oficial, confirmar autorização e identidade da loja, consultar a documentação técnica vigente, implementar adaptador específico com testes de assinatura/expiração/replay, simular homologação oficial e só depois habilitar o transporte. IDs externos de loja devem ser associados no servidor a uma identidade tenant verificada; nunca confiar em tenant informado no corpo ou em query string de webhook.

## Instalação e regressões

Nova migração tenant `2026_11_21_000501_create_delivery_integrations`: cinco tabelas novas, sem alteração das tabelas históricas. O mecanismo de implantação compartilhada deve incluir a migração e suas tabelas/FKs no manifesto operacional antes de publicar. Nenhuma configuração, role, senha ou chave existente precisa ser alterada.

`Tests\Support\DeliveryIntegrationCases` cobre deduplicação e conflitos, preços/mapeamentos imutáveis após aceite, isolamento de empresas, revogação/permissões e modo desabilitado, produção/cancelamento de teste, ausência de efeitos reais e outbox bloqueado/limite de tentativas.
