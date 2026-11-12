# Prompt — agente fiscal do Vendra

Você será o arquiteto e desenvolvedor da integração fiscal do **Vendra, um produto da Vexo**. Quero implementar emissão de cupom fiscal para vendas de mercadorias, com segurança, simplicidade operacional e isolamento entre empresas. Seu escopo inicial é **NFC-e, modelo 65**; confirme primeiro se esse documento é adequado à UF e à operação da empresa.

## 1. Contexto e arquitetura existente

- Repositório: https://github.com/paulosaltarelli/reporvexocomercio, branch principal `main`.
- PHP 8.3, Laravel 12, MySQL 8. Um único pacote para todos os clientes; banco central de empresas e banco separado por empresa assinante. Não criar forks/customizações por cliente.
- Aplicação: https://2.25.146.67:8445/; acesso da empresa de homologação: `/app/vexo/login`.
- VPS Ubuntu 24.04. Aplicação em `/srv/reporvexo`, serviço isolado `vendra.service`, porta HTTPS 8445. Outros sistemas usam 80/443/8443/8444 e não podem ser interrompidos.
- Neste ambiente cloud, SSH usa a rota TCP autorizada do Tailscale (IP da VPS: `100.72.251.54`). Não presuma acesso direto à porta 22 pública, disponibilidade de arquivos do Windows ou existência da chave deste outro chat. Use as credenciais já configuradas no seu ambiente; nunca peça nem exiba chave privada ou senha em chat.
- Leia as instruções do repositório e `docs/PRODUTO.md`, `docs/ANALISE_FUNCIONAL_ERP.md`, `docs/OPERACAO.md`, `docs/CAIXA.md`, `docs/PRECIFICACAO.md`, `docs/TESTES.md` e o código efetivo antes de propor mudanças. Há desenvolvimento no chat principal; registre o commit-base e confirme que o código disponível está atualizado.
- Trabalhe em uma branch própria, por exemplo `feature/fiscal-nfce`, no checkout isolado desta tarefa. Não faça reset/push forçado, não sobrescreva trabalho de outros chats e não crie worktree por padrão.

Já existem catálogo unificado, compras, estoque com custo médio, políticas de preço automático/alerta, vendas, orçamentos, OS, financeiro parcelado com baixas parciais, reembolsos vinculados, fluxo/projeção, fechamento diário, usuários, logo e relatórios.

Vendedores agora têm cadastro `Seller` independente de login. `salesperson_id` identifica o vendedor creditado; `user_id` identifica o executor. O `seller_id` antigo continua sendo uma referência legada a usuário, não deve ser confundido com o novo ID. A conversão preserva o vendedor do orçamento e registra o executor separado; mudanças anteriores ao faturamento são auditadas. Não confunda esses papéis com o emitente fiscal.

Classes importantes: `TenantConnection`, `ResolveTenant`, `TenantAuthenticated`, `Product`, `Checkout`, `Inventory`, `Documents`, `FinancialService`, `CashService`, `PricingService`, `BusinessProfile`, `ReportController`.

O catálogo possui `type=product/service` e `controls_stock`. Serviços e itens sem controle não geram movimentação de estoque; usam custo de referência. Preços atuais podem mudar automaticamente, mas vendas e custos históricos são snapshots. Serviços antigos têm compatibilidade legada: não altere IDs nem vínculos históricos.

## 2. Diagnóstico e perguntas indispensáveis

Primeiro inspecione o que já existe. Depois peça apenas informações ausentes que impeçam progresso:

1. UF, município, CNPJ e inscrições do emitente, regime tributário e contador responsável pela validação tributária.
2. Se a empresa está credenciada para NFC-e na SEFAZ e quais ambientes já estão disponíveis.
3. Disponibilidade de certificado A1, validade e procedimento seguro para configurá-lo; CSC e identificação do CSC quando exigidos. Solicite **cadastro seguro**, nunca valores de certificado, senha, CSC ou token no chat.
4. Tipos de operação: consumidor final, venda a empresa, presencial/delivery, interestadual, descontos, frete, pagamentos e devoluções.
5. Impressão desejada: inicialmente DANFE NFC-e em PDF/impressão térmica, identificando largura e equipamento disponíveis.

Não deduza a UF pelo telefone. Não invente CNPJ, inscrições, CFOP, CST/CSOSN, alíquotas ou credenciais. A base Vexo contém **dados demonstrativos** e não pode ser usada para emissão em produção. Os rótulos visíveis “Demo” foram retirados a pedido do proprietário; isso não transforma os dados em operações reais. Consulte os marcadores internos em `demo_runs`, inclusive a carga anual, em vez de procurar palavras nos nomes. Implemente uma trava de produção para empresas com demonstração ativa.

Enquanto aguarda dados, avance na inspeção, desenho, contratos, migrações e testes que não dependam deles.

## 3. Pesquisar e escolher o caminho de integração

Compare duas estratégias com fontes oficiais atuais:

- Integração direta com SEFAZ, avaliando bibliotecas PHP mantidas, como NFePHP, compatibilidade, assinatura e manutenção.
- API de provedor fiscal, comparando opções adequadas a SaaS multiempresa, como Focus NFe, Nuvem Fiscal e TecnoSpeed, ou alternativas verificadas.

Apresente uma recomendação concreta: cobertura por UF, NFC-e/NF-e/NFS-e, certificado/CSC, custo fixo e por emissão, homologação, cancelamento, inutilização, contingência, eventos/webhooks, armazenamento de XML, suporte e dependência do fornecedor. Diferencie preço consultado e informação ainda não verificada. Não contrate serviços nem assuma custos sem aprovação do proprietário.

Consulte Portal Nacional da NF-e, SEFAZ da UF, ENCAT, Receita Federal, esquemas XSD e Notas Técnicas vigentes. Verifique atualizações de IBS/CBS e CNPJ alfanumérico quando aplicáveis. Cite links e data da consulta; não use regras fiscais antigas sem conferir.

Não configure uma solução para SAT/CF-e apenas porque é chamada de “cupom”; confirme o modelo exigido pela UF na data da implantação.

## 4. Módulo fiscal separado do núcleo comercial

Crie adaptadores por provedor e ambiente, com operações claras de emitir, consultar, cancelar, inutilizar, obter XML/protocolo e gerar DANFE. Permita evolução futura para NF-e modelo 55 e NFS-e sem implementar todos os documentos agora.

Requisitos essenciais:

- Configuração por empresa/emitente e ambiente. Homologação como padrão seguro; credenciais, numeração e arquivos separados de produção.
- Numeração/serie/modelo/ambiente/emitente protegidos por locks e restrições únicas, inclusive concorrência.
- Documento fiscal persistido com estado explícito: preparado, pendente, enviado, autorizado, rejeitado, situação incerta/consulta necessária e cancelado, além de outros estados que a documentação exigir.
- Registrar referência à venda, chave de acesso, número, série, protocolo, códigos oficiais, eventos, datas e hashes dos arquivos. Não marcar autorizado só por receber HTTP200.
- Congelar os dados fiscais e valores usados na emissão. Não recalcular uma nota antiga usando preço atual do produto ou cadastro posteriormente alterado.
- Fila/outbox transacional: gravar intenção de emissão junto à operação e transmitir após commit. Não fazer chamada externa dentro da transação que mantém locks de estoque/financeiro.
- Jobs devem carregar IDs e `tenant_id`; restaurar o contexto correto antes de ler modelos e limpar conexões/contexto em `finally`. O ambiente atual pode usar fila síncrona: implemente e documente o worker/outbox necessário, sem assumir que já existe infraestrutura assíncrona fiscal.
- Repetição, timeout, resposta perdida, webhook duplicado e falha após autorização não podem gerar nota duplicada ou novo número indevido. Consultar a situação antes de repetir quando o resultado for incerto.
- Logs úteis e redigidos, sem certificado, senha, CSC/token ou payloads pessoais completos. Webhooks autenticados, idempotentes e associados ao documento/empresa conhecidos no servidor.

## 5. Regras tributárias e vendas mistas

Levante campos e validações exigidos para emitente, destinatário, itens e pagamentos: NCM, origem, CFOP, CST/CSOSN, CEST quando aplicável, unidades/GTIN, benefícios, bases e tributos, descontos, frete, troco e identificação do consumidor conforme UF e valor.

Não aplique um perfil tributário genérico a todos os produtos/empresas. Parametrização precisa ser validada pelo contador. Campos ainda desconhecidos devem bloquear a emissão com orientação clara, sem defaults fictícios.

Não use apenas o tipo atual do cadastro para classificar uma venda histórica: o tipo e o controle de estoque podem ter mudado após a venda. Inspecione os snapshots existentes, acrescente os fiscais necessários ao fluxo novo e exija conferência dos legados quando a classificação original não puder ser comprovada.

Uma venda pode misturar mercadorias e serviços. **Não envie serviços municipais como mercadorias na NFC-e** nem use o total inteiro da venda como total fiscal se parte precisar de outro documento. Proponha a separação fiscal e a alocação coerente dos pagamentos. NFS-e é uma integração distinta e pode ficar para a próxima fase.

O módulo comercial também registra descontos e comissões. Em vendas novas, `SaleItem.unit_price` é a referência bruta; `gross_total`, `discount_amount` e `total` preservam bruto, desconto efetivo e líquido. Não trate quantidade × preço bruto como líquido nem desconte duas vezes. Use os snapshots efetivos para o XML; comissão do vendedor não é desconto ao consumidor nem tributo. Vendas antigas receberam apenas novos campos neutros, sem retrocomissões.

O custo médio é gerencial, não a base automática dos tributos da venda. Use snapshots dos valores efetivamente vendidos e regras oficiais de cálculo/arredondamento.

## 6. Cancelamento, devolução, caixa e contingência

Cancelamento comercial/financeiro não significa automaticamente cancelamento fiscal. Defina um fluxo explícito entre estados, respeitando prazos e permissões legais da UF/modelo. Preserve o documento e os eventos. Devolução e inutilização não devem ser confundidas com cancelamento.

Não apague vendas, pagamentos ou movimentações para “corrigir” uma nota. Preserve snapshots e a trilha de auditoria. Não contorne o fechamento diário: dias financeiros consolidados bloqueiam novas baixas e estornos retroativos. Autorizar uma emissão pendente depois não pode lançar novamente estoque ou dinheiro.

Avalie contingência/offline conforme regras vigentes da UF e capacidade do provedor. Nunca simule autorização. Se houver DANFE em contingência, implemente apenas conforme o procedimento oficial, com estado e identificação adequados e posterior transmissão/reconciliação.

## 7. Certificado, arquivos e segurança multiempresa

- Certificado e senhas em mecanismo seguro, com armazenamento privado por empresa, criptografia adequada e acesso restrito. Não reutilize o upload de logo para arquivos fiscais.
- Não exponha PFX, credenciais, XML com dados pessoais ou DANFE por caminhos públicos adivinháveis. Downloads precisam de autorização por empresa/perfil.
- Conferir validade, identidade e uso do certificado. Não desabilitar TLS, verificação de certificado, assinatura ou checksum para superar falhas.
- Se rede/proxy/mTLS ou credencial não estiver disponível, diagnostique e solicite a configuração necessária, sem contornar a política de rede.
- Defina retenção e recuperação com base nas obrigações vigentes, incluindo backup cifrado externo e ensaio de restauração; não apague arquivos fiscais automaticamente por política genérica.
- Empresa de demonstração deve permanecer bloqueada para produção. Nunca transmitir dados fictícios como documento fiscal real.

## 8. DANFE e experiência de operação

Use o XML autorizado/protocolo e os dados fiscais congelados para gerar o DANFE NFC-e, QR Code e consulta oficial. Não crie QR Code “de exemplo” apresentado como fiscal nem use o layout de comprovante interno como DANFE.

Respeite o layout e textos legais vigentes. A logo pode aparecer quando permitido, mas dados do emitente devem vir do documento fiscal, não de um perfil que mudou depois. Preserve o comprovante interno claramente identificado enquanto a emissão não estiver autorizada.

A interface deve ser simples: situação fiscal da venda, emitir quando elegível, acompanhar fila, consultar, baixar XML/DANFE, reimprimir e cancelar quando permitido. Mensagens devem traduzir a causa e ação possível, mantendo o código oficial para diagnóstico. Não disparar e-mails/WhatsApp a clientes automaticamente nesta etapa.

## 9. Validação e entrega

Entregue:

1. Diagnóstico e recomendação fundamentada do provedor/integração.
2. Desenho do fluxo, estados, contratos, isolamento e configuração segura.
3. Migrações aditivas e módulo implementado sem regressão no núcleo comercial.
4. Testes de cálculo/schema quando aplicável, autorização/rejeição, consulta após timeout, idempotência e concorrência, isolamento, cancelamento, inutilização, permissões, arquivos privados e bloqueio de demonstração/produção.
5. Teste real de homologação com credenciais configuradas, preservando os resultados oficiais e distinguindo-o dos mocks. Se bloqueado por credencial, declare o bloqueio; não chame mock de emissão validada.
6. DANFE/XML e operação de worker/outbox documentados, checklist de implantação e recuperação.
7. Relação precisa do que está pronto, pendente, testado e dependente do contador/SEFAZ/provedor.

Faça instalação e testes necessários no ambiente disponível e avance autonomamente dentro do escopo. Preserve alterações em andamento e não modifique os outros sistemas da VPS. Antes de tocar produção fiscal, apresente o resultado concreto de homologação e peça autorização explícita para a virada. **Não emitir documentos em produção nem transmitir dados reais sem essa autorização.**

Comece inspecionando o repositório atual e o código. Em seguida apresente o diagnóstico inicial, as poucas informações faltantes e sua recomendação de integração.
