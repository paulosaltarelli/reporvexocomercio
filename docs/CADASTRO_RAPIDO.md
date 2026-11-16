# Cadastro rápido no atendimento

Disponível no caixa, orçamento e criação/edição de OS. Os botões abrem uma janela e salvam por requisição autenticada, mantendo a tela original. Cliente salvo é selecionado no atendimento; produto ou serviço salvo entra em uma linha, com o preço efetivo e regras do grupo. O item também fica disponível nas buscas, seletores e cadastros normais. Ao atingir 50 linhas, o cadastro continua salvo e a interface pede para selecionar numa linha existente.

Permissões existentes são preservadas: customers.manage para cliente; products.manage para produto/serviço. Saldo inicial exige stock.manage. O perfil Operador padrão não ganha novas funções automaticamente. Não são enviados custos no retorno do cadastro. Nome e contatos são inseridos como texto na interface.

Cliente exige somente nome. CPF/CNPJ é opcional, com validação de dígitos e bloqueio de documento já cadastrado, inclusive com pontuação diferente. Produto/serviço exige grupo, unidade e preço (zero permitido). SKU opcional é gerado a partir da chave da operação. Código de barras e SKU devem ser únicos. Serviço não controla estoque. Modo do catálogo e gestão de estoque da empresa são conferidos no servidor.

Produto controlado começa com saldo zero, ou recebe uma entrada identificada se um usuário autorizado informar quantidade real e custo unitário. A criação, entrada e registro de auditoria são atômicos. A política de preço do grupo pode aplicar preço automático; a interface usa e informa o preço efetivamente salvo. O restante do cadastro, inclusive fiscal, pode ser completado na tela normal.

Reenvios usam UUID e mutex por empresa, guardando autor, tipo, ID e hash em registros tipados da tabela tenant existente company_operation_audits. Mesma tentativa retorna o mesmo registro; outro conteúdo ou outro autor não reutiliza a chave. Nenhuma migração é necessária. O cadastro rápido não confirma venda, emite nota nem salva a OS automaticamente.

Falhas de validação ou conexão mantêm o diálogo e o atendimento. Cancelar não limpa o carrinho. Durante o salvamento, fechamento e duplo envio são bloqueados. Atalhos F2/F4/F9 do caixa são isolados enquanto o diálogo estiver aberto. Se a sessão expirar, a tela pede autenticação em outra aba sem navegar e perder o atendimento. Dados pessoais não são persistidos em localStorage.

Publicação com lock compartilhado, backup consistente, overlay restrito e renderização como vendra-web. QA usa bases dedicadas; não criar clientes, produtos ou saldos reais para testar a publicação.
