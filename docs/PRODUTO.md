# Vendra — por Vexo

Nome de trabalho para o SaaS de gestão comercial da Vexo. Disponibilidade de marca e domínio ainda não verificada.

## Arquitetura aprovada

- Um único produto, repositório, pacote e versão de aplicação para todos os clientes.
- Um banco central guarda empresas assinantes, domínios, planos, assinaturas e administração da plataforma.
- Um banco MySQL separado por empresa assinante guarda seus usuários operacionais, clientes, produtos, serviços, estoque, vendas, ordens de serviço e financeiro.
- Filiais pertencem à empresa assinante; não implicam cópia do código.
- Credenciais MySQL individuais por empresa e permissões restritas ao respectivo banco.
- O contexto da empresa deve ser resolvido e autorizado no servidor. Nunca aceitar nome de banco ou credenciais enviados pelo navegador.
- Filas, cache, sessões e arquivos também devem carregar o contexto da empresa. Remover contexto e conexões após cada execução, inclusive falhas.
- Uma pasta de migrações operacionais compartilhada evolui todos os bancos. Atualizações precisam de backup, registro por empresa, controle de versão, interrupção em falha e retomada.
- Não criar branches, diretórios de aplicação ou tabelas particulares para clientes. Diferenças são configurações, permissões e módulos do produto.

## Entregas

1. Fundação: identidade, autenticação, painel da plataforma, empresas, provisionamento de bancos, permissões e testes de isolamento.
2. Cadastros: clientes, fornecedores, produtos, serviços, variações e dados fiscais.
3. Operação: compras, estoque, orçamentos, vendas, pagamentos e devoluções.
4. Serviços: ordens de serviço, equipamentos, veículos e aprovações.
5. Financeiro e relatórios: caixa, títulos, parcelas, fluxo e resultados.
6. Preparação fiscal: contratos de integração e dados para NFS-e. Sem emissão, certificado ou comunicação fiscal nesta fase.

Cobrança recorrente automática, emissão fiscal e integrações externas serão entregas próprias; não simular sua disponibilidade.

## Identidade visual

- Marinho: #061329 — marca e navegação.
- Azul: #1463EB — ações principais.
- Ciano: #25C4ED — destaques.
- Fundo: #F4F7FB — telas operacionais.
- Texto: #17243B; texto secundário: #64748B.
- Branco: #FFFFFF; bordas: #DFE7F1.

Vendra é o produto; Vexo é a empresa desenvolvedora. Usar a assinatura “por Vexo”.
As cores são uma interpretação visual das referências fornecidas, não amostras exatas dos arquivos originais.

## Situação inicial

Primeira versão de homologação implementada: autenticação por empresa, perfis administrador/operador, troca de senha e revogação de sessões, edição/desativação de usuários, grupos e margens, produtos, clientes, fornecedores, serviços, custo médio, movimentações, compras integradas, vendas mistas, estorno com histórico financeiro, orçamentos, OS, parcelas mensais, baixas parciais, relatórios por período e CSV. Cadastro da própria empresa e dados fiscais preparatórios disponíveis.
Validação e maturação operacional ainda são necessárias antes de uso comercial.
Ainda não há administração web da plataforma/assinaturas, cobrança recorrente automática, NFC-e/NF-e/NFS-e, integração bancária, conciliação, caixa com abertura/fechamento, importação de XML, depósitos, grades, séries/lotes e relatórios contábeis completos.
Os campos fiscais são preparatórios e não equivalem à integração de emissão. Dados cadastrais fiscais e regras tributárias precisarão ser completados na etapa fiscal.
