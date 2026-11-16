# Frente de caixa do Vendra

A tela de balcão abre diretamente na venda. O cliente começa como consumidor
final; identificação, troca de vendedor e observações ficam em um bloco recolhido.
Não há etapa prévia “Quem será atendido?” nem seleção de um próximo passo.

## Operação

- Digitar nome, SKU ou ler código de barras no campo inicial.
- Enter inclui uma correspondência única; resultados ambíguos exigem seleção.
- Ler novamente o mesmo item aumenta sua quantidade sem apagar preço ou desconto
  já ajustados. Quantidades usam milésimos inteiros; valores usam centavos inteiros.
- Quantidade, preço e desconto podem ser conferidos nas linhas do carrinho.
- Total, pagamento e confirmação ficam no mesmo formulário. Cadastro de cliente
  não é obrigatório. O vendedor vinculado ao usuário continua sendo o padrão.
- A prazo revela vencimento e parcelas. A opção recebido agora mantém parcela única.
- Dinheiro tem prévia opcional de valor entregue e troco. Esse campo não altera o
  valor contabilizado da venda e não cria movimento financeiro próprio.
- F2: buscar item; F4: pagamento; F9: registrar venda, sujeito à validação do formulário.
  Enter nos campos numéricos dos itens volta à busca e não encerra a venda.
- Depois do comprovante, Nova venda inicia um carrinho com nova chave. Para
  administradores, conserva a escolha do vendedor; operadores mantêm seu próprio vínculo.
- Orçamentos e OS ficam como operações secundárias, respeitando a configuração
  da empresa. Consultas do dia ficam recolhidas abaixo do caixa.

## Regras preservadas

A conclusão usa o POST e o serviço transacional de checkout já existentes.
O servidor continua validando saldo, tipos habilitados, limite de desconto,
atribuição do vendedor, preço visto, parcelas e chave de repetição. A proteção de
interface contra clique duplo complementa a idempotência do servidor.

Pix e cartões são registros manuais, sem cobrança/TEF ou conciliação automática.
A venda comercial e a autorização fiscal continuam tendo estados separados.
Não há alteração de banco, comissão, fiscal ou permissões nesta evolução.

## Integração

Foi inspecionada a branch histórica `feature/pdv-checkout` (`a9229d2`), que já
propunha checkout integrado e busca por código. Essa proposta ainda mantinha a
busca de cliente antes dos itens. A implementação atual reaproveita o fluxo de
checkout existente e incorpora o atendimento direto, preservando a navegação e
as configurações SaaS mais recentes, o mapeamento de serviços legados e os
originais da marca Vexo.

Base anterior publicada: `37257ac`. O overlay desta evolução fica limitado ao
controller de balcão, views de balcão/venda/itens/layout/navegação e `pos.css`/`pos.js`.
Landing, imagens e estilos da marca, banco, certificados e credenciais ficam preservados.

## Validação

A suíte focada final passou com **18 testes e 300 assertions**, sem erros,
falhas ou skips, em 131,146 segundos. Abrange renderização, permissões, venda
anônima, repetição, validação, descontos, estoque, parcelas, concorrência e OS.
O lint PHP passou em **88 views compiladas**; a sintaxe de `pos.js` foi conferida.

No Chromium, o fluxo foi exercitado em 320, 390, 768 e 1440 px, incluindo leitura
repetida sem duplicar linha, busca sem acento, quantidades fracionárias, desconto,
troco, valor insuficiente, Pix, parcelas, atalhos, duplo acionamento, menu modal e
restauração após erro. Nenhuma venda foi enviada por esse teste de navegador;
os POSTs de integração ocorreram apenas nos bancos de teste.

Os resultados de publicação ficam no PR e no journal privado correspondente.
Não tratar aprovação de revisão anterior como execução da fonte nova.

## Correção de permissão após publicação

A pasta nova `resources/views/partials` foi criada com modo efetivo 0700 porque
`umask 0077` restringiu `mkdir(mode=0750)`. O arquivo tinha modo 0640 e grupo
correto, mas `vendra-web` não conseguia atravessar a pasta. Isso causou HTTP 500
nas telas autenticadas, algo que o smoke apenas dos logins não detectou.

A pasta foi corrigida para 0750, mantendo root:vendra-web, sob o lock compartilhado.
Dashboard e frente de caixa foram renderizados como `vendra-web` usando objetos
fictícios em memória e sessão array, sem criar usuário, venda ou consultar dados.

Para novos diretórios, aplicar `chmod(0750)` explicitamente depois de criar e
atribuir grupo. Antes de reabrir o serviço, testar os arquivos do overlay com
`ops/check-web-readable.py`; a leitura é conferida com o usuário web e inclui
implicitamente a travessia dos ancestrais. Não considerar lint ou respostas 200
apenas de login como prova de renderização das telas internas.
