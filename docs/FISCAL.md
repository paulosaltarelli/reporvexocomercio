# Fiscal — diagnóstico e fundação (12/11/2026)

## Estado desta entrega

Base: `4fd2c610e3781e02d6310dbf98d71b2262ce31f1`, conferida com `git ls-remote origin refs/heads/main`. Checkout isolado, branch `feature/fiscal-nfce`. Nenhuma alteração/deploy na VPS, transmissão, contratação ou custo de provedor.

Foram lidos PRODUTO, ANALISE_FUNCIONAL_ERP, OPERACAO, TESTES e os serviços/modelos de vendas, estoque, financeiro e resolução de empresa. Não há AGENTS.md neste checkout. CAIXA.md, PRECIFICACAO.md, catálogo unificado, controls_stock, CashService e PricingService não estão nesta base. Serviços são separados em `services`; `sale_items` preserva product_id/service_id e valores históricos. A integração com mudanças do chat principal exigirá nova leitura e merge normal, nunca reset forçado.

Implementado aqui: contratos sem transporte, estados, guarda de produção, migrações aditivas propostas para central e tenants e testes unitários de segurança. Não há adaptador operacional, emissão, worker, tela, webhook, armazenamento ou download fiscal implementados. O contrato não é uma integração pronta. Migrações não foram aplicadas em bancos operacionais. A guarda é uma primitiva a ser obrigatoriamente chamada em todos os futuros caminhos de transmissão, inclusive reprocessamento; não substitui essa integração.

## Adequação e informações necessárias

Modelo 65 permanece candidato, não confirmado. Faltam UF/município, identificação e inscrições em cadastro seguro, regime/CRT, contador responsável, credenciamento/ambientes, natureza das operações, certificado A1/validade, CSC quando exigido e equipamento/largura de impressão. Não inferir UF dos dados demonstrativos. Consultar SEFAZ da UF informada e regras de destinatário/valor/operação antes de aceitar emissão. NFS-e e modelo 55 ficam separados. Não implementar SAT por associação ao termo cupom.

## Comparação e recomendação inicial

Consulta em 12/11/2026. Recomendo Focus NFe como primeiro adaptador candidato, sujeito à UF, prova de homologação e aceite comercial. Não contratar nesta fase.

| Estratégia | Evidência e decisão |
|---|---|
| Direta/NFePHP | `sped-nfe` cobre 55/65 e mantém assinatura/comunicação; o composer consultado aceita PHP >=7.4 e exige extensões XML, OpenSSL e SOAP. Isso permite avaliar PHP 8.3, mas não comprova compatibilidade por execução. Sem tarifa de API; VPS, manutenção de XSD/NT, certificado, CSC, contingência, suporte e monitoramento ficam com Vexo. NFS-e exige biblioteca/integração separada. Menor dependência comercial; maior responsabilidade operacional. |
| Focus NFe | Documenta NFC-e nacional, homologação, referência única, consulta, cancelamento e inutilização; A1 e armazenamento/webhooks. Preços consultados: Retail R$62,90/mês, um emitente, 500 NFC-e + 100 NF-e; excedentes R$0,055/NFC-e e R$0,16/NF-e. Retail+ R$657,90/mês, emitentes ilimitados, 9.000 NFC-e + 1.000 NF-e; excedentes R$0,065 e R$0,16. Suporte por ticket/e-mail. DANFC-e indicada em HTML: PDF/térmica precisa prova. Retenção contratual, contingência, autenticação de webhook e adequação exata à UF ainda precisam validação. Dependência de disponibilidade e contrato mitigada por exportação local. |
| Nuvem Fiscal | Documentação indexada ainda lista NFC-e, certificado/configuração por empresa e escopo OAuth. A página inicial indexada anuncia desativação em 31/07/2026. Abertura direta falhou (502 na pesquisa; 403 no curl). Não recomendar contratação nem reutilizar preços históricos; continuidade, suporte, arquivos e operações não confirmados. |
| TecnoSpeed/PlugNotas | Documenta NF-e/NFC-e/NFS-e, inutilização e configuração de contingência por empresa. Alternativa para cotação; valores fixos/por documento, cobertura específica, retenção, SLA, homologação e contrato SaaS precisam confirmação. Não assumir funcionalidades de outro produto TecnoSpeed. |

Fontes primárias:

- https://github.com/nfephp-org/sped-nfe e https://github.com/nfephp-org/sped-nfe/blob/master/composer.json
- https://focusnfe.com.br/precos/ e https://doc.focusnfe.com.br/docs/nfce-operacoes-disponiveis-na-api
- https://www.nuvemfiscal.com.br/ e https://dev.nuvemfiscal.com.br/docs/nfce/ (limites de acesso acima)
- https://docs.plugnotas.com.br/ e https://atendimento.tecnospeed.com.br/hc/pt-br/articles/38024197110295
- https://www.nfe.fazenda.gov.br/portal/principal.aspx — consultar catálogo de NT e XSD novamente ao implementar; pesquisa encontrou atualizações de 2026, não foi validado um pacote XSD específico nesta entrega.
- https://www.gov.br/receitafederal/pt-br/acesso-a-informacao/acoes-e-programas/programas-e-atividades/cnpj-alfanumerico — CNPJ deve permanecer string, incluindo letras; não aplicar limpeza apenas numérica.
- https://nfce.encat.org/desenvolvedor/qrcode/ e https://dfe-portal.svrs.rs.gov.br/NFCE/Documentos — confirmar manual/QR e URLs da UF na implantação.

IBS/CBS: selecionar versões vigentes das NT, tabelas CST/classificação tributária, cronograma e XSD; parametrizar com contador. Não há alíquotas, CFOP ou CSOSN padrão nesta entrega. A checagem lexical da chave em ProviderResult não é validação XSD, assinatura ou checksum.

## Fluxo a implementar

1. Administrador configura emitente/ambiente, credenciamento, numeração reconciliada, regras aprovadas pelo contador e referência ao cofre. Homologação padrão; emitente desabilitado por padrão. Empresas existentes são classificadas como demonstração até revisão explícita.
2. Na transação da venda, congelar emitente/destinatário/endereço, classificação fiscal por item, valores, quantidades, descontos/frete/tributos e pagamentos. Criar intenção/documento/outbox na mesma conexão tenant. Não acessar provedor nessa transação. Se emissão for solicitada depois, criar intenção em transação própria com a venda bloqueada e preservar seu snapshot.
3. Serializar alocação com `SELECT FOR UPDATE` em fiscal_sequences, incrementar e gravar documento/outbox na mesma transação. Não usar MAX+1. Restrição única protege emitente/ambiente/modelo/série/número; ambiente pertence ao emitente. Nunca trocar número após timeout.
4. Worker recebe somente tenant_id, document_id e operation_id. Resolve Tenant ativo no central, conecta TenantConnection, valida correspondência e guarda de produção. `finally` desconecta. Reclama lease em transação curta; comunica depois do commit. Lease expirada após envio implica consulta, não reenvio. Token de lease impede persistência por worker antigo; resultado atrasado exige reconciliação oficial.
5. prepared → pending → sent → authorized/rejected/uncertain. uncertain só sai por consulta conclusiva. authorized → cancelled somente com evento oficial aceito. Duplicatas repetem estado sem apagar histórico; resposta antiga não ressuscita cancelamento. Correção de rejeição e reemissão exigem fluxo explícito futuro; não sobrescrever snapshot original.
6. Persistir código, protocolo, chave, XML/eventos e hashes antes de concluir reconciliação. HTTP 200 não basta. Falha de download após autorização exige recuperar arquivos, sem reemitir. Nunca movimentar estoque ou financeiro nesse fluxo.

## Regras, segurança e operação pendentes

Endereço fiscal estruturado do consumidor ainda é lacuna no cadastro atual. Validar NCM, origem, CFOP, CST/CSOSN, CEST/benefício quando aplicável, unidade, GTIN, bases/tributos, CRT, pagamentos, troco, descontos/frete, identificação e IBS/CBS conforme operação. Campo desconhecido deve bloquear com mensagem acionável. Custo médio nunca alimenta base tributária automaticamente.

Vendas mistas: separar itens municipais por tipo efetivo, inclusive quando o catálogo unificado chegar; preservar os vínculos legados. NFC-e totaliza só mercadorias. Alocar pagamentos por método em centavos com critério determinístico, resíduo explícito e conservação da soma entre documentos; validar arredondamento por item com contador e XSD. Não produzir NFS-e implícita nem enviar total integral da venda mista. A alocação ainda não está implementada.

Cancelamento comercial não cancela fiscal. Exibir pendência e exigir ação/permissão fiscal separada, prazo e justificativa conforme UF. Não reabrir caixa consolidado, apagar vendas ou reverter dinheiro automaticamente. Devolução e inutilização são eventos próprios; a inutilização precisa registro independente de venda/documento e reserva concorrente de faixa, ainda a implementar (a tabela fiscal_events desta fundação é só de documentos).

Segredos: cofre/referência por emitente/ambiente, A1 privado cifrado, validação de identidade/validade/uso; sem upload público/logo e sem conteúdo sensível nos logs. Nenhuma tela de cadastro seguro foi entregue. Webhook futuro autenticado, resolve empresa/documento por referência previamente conhecida, deduplica evento e consulta quando necessário; não confiar em tenant_id fornecido pelo remetente.

XML/DANFE: disco privado por tenant/ambiente/documento, autorização por usuário/perfil e hash verificado. Não publicar URL previsível, não buscar URL arbitrária do webhook. Usar XML autorizado/protocolo e QR oficial. Até lá, manter comprovante interno identificado como não fiscal. Contingência desabilitada até procedimento da UF/provedor validado; nunca fabricar autorização.

Worker/outbox ainda não operacionais: não instalar serviço nem cron com esta branch. Futuro comando deve varrer tenants ativos e outbox por lotes, limitar tentativas, permitir consulta/reconciliação, emitir métricas redigidas e nunca depender de QUEUE_CONNECTION=sync. Homologação deverá provar recuperação após queda em cada ponto entre envio, autorização e persistência.

Implantação futura: regressões e MySQL 8 concorrente/isolamento; backup cifrado externo com APP_KEY/cofre/arquivos; restauração ensaiada; migrações central e tenants sob manutenção apenas Vendra; homologação oficial com XML/protocolo preservados; revisão do contador; autorização explícita do proprietário antes de produção fiscal. Definir retenção legal e acesso com contador/jurídico; sem exclusão automática por política genérica. Esta entrega não comprova retenção, restauração ou homologação.

## Validação

Execução local em Docker PHP 8.3.36, PHPUnit 11.5.57: **6 testes, 10 verificações aprovadas**. Verificam evidência mínima de autorização, impedimento de reenvio em situação incerta, resposta atrasada sobre cancelamento e guarda demonstração/produção. Sintaxe PHP aprovada nos contratos, migrações e testes verificados. Não são mocks de SEFAZ e não validam emissão. A suíte comercial não foi executada; o runtime isolado não tem bcmath/pdo_mysql nem fixture MySQL configurada. Permanecem pendentes: migrations MySQL, concorrência, integração tenant/outbox, permissões/downloads, adaptador, cancelamento/inutilização, schemas/cálculos, DANFE e homologação real. Nenhum teste oficial foi executado por falta de emitente/credenciais/configuração.
