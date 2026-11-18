# OS e abertura do caixa

A OS segue Aberta → Em serviço → Finalizada. Na tela, use Iniciar serviço e Finalizar serviço; aprovação continua apenas no fluxo de orçamento. OS antigas aprovadas podem iniciar normalmente, sem migração de dados.

O atendimento e os itens podem ser editados enquanto a OS estiver aberta ou em serviço. O hash de edição continua protegendo contra alterações concorrentes. Finalizar sem itens é permitido para diagnósticos sem cobrança; uma OS sem itens não gera venda. Para cobrar, inclua produtos e serviços antes de finalizar. Estoque e financeiro continuam sendo movimentados apenas na confirmação da venda, preservando o recebimento idempotente.

Na abertura do caixa, exiba o valor contado em dinheiro e a data do último fechamento anterior, mesmo após dias sem atividade. A consulta não abre o caixa automaticamente; o operador confirma a abertura. O valor recebido por Pix/cartão não entra no fundo em dinheiro. Sangrias e suprimentos registram diferenças físicas após abrir, preservando a continuidade dos fechamentos. O parâmetro de consulta não substitui o valor do fechamento anterior.

Validação executada: 11 testes passaram (183 assertions); os dois testes novos foram repetidos após o ajuste final da mensagem (31 assertions). Views compiladas. Cobertura: fluxo HTTP sem aprovação, edição em serviço, bloqueio de edição após finalizar, OS sem cobrança, orçamento preservado, recebimento único, abertura herdada do último fechamento e isolamento por loja. Publicação exige backup consistente e lock compartilhado; nenhuma migração.
