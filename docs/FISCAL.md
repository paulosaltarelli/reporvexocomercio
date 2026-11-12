# Emissão fiscal do Vendra

Revisão: 12/11/2026. Branch `feature/fiscal-nfce`, integrada à `main` `25eea15` por merge, preservando catálogo unificado, serviços legados, caixa, precificação e histórico. Um pacote comum, banco central e banco operacional por assinante. Nenhuma customização por cliente.

## Estado e escopo

Implementados: cadastro privado de A1 e credenciais na empresa, configuração do emitente por ambiente, regras por produto, conferência preservada, solicitação transacional de NFC-e, numeração concorrente, outbox/worker, consulta/reconciliação, reenvio explícito após referência não encontrada, cancelamento fiscal independente, inutilização de faixa, XML privado e DANFE em PDF.

**A implementação é de homologação. Não há autorização para produção e não foi realizada emissão oficial neste trabalho.** Os testes usam certificados gerados localmente e respostas HTTP simuladas, explicitamente sem autorização SEFAZ. O certificado do proprietário não foi recebido nem instalado. Não houve contratação Focus, alteração na VPS ou envio a clientes.

Fluxo disponível: venda presencial dentro da UF a consumidor final não contribuinte, já recebida em dinheiro, Pix ou transferência. Consumidor CPF/CNPJ é validado localmente; identificação obrigatória depende do limite da UF configurado e aprovado pelo contador. Operações para contribuinte, delivery, interestaduais, cartão/TEF, recebimentos parciais e pagamentos mistos precisam de extensão específica. Serviços municipais nunca entram como mercadorias; NFS-e e NF-e modelo 55 permanecem integrações distintas, fora desta entrega. Não anunciar estes documentos como implementados.

O sistema não consegue credenciar uma empresa apenas porque ela possui certificado. Empresa exclusivamente prestadora de serviços pode não estar habilitada para NFC-e; UF, município, inscrições e natureza efetiva da operação precisam de validação. Não inferir UF de telefone ou dos dados de demonstração.

## Configuração por empresa

Em **Minha empresa**:

1. Preencher razão social, CNPJ, IE, regime, endereço completo, UF, CEP e código IBGE. O CRT fiscal é confirmado explicitamente pelo contador, sem inferência automática a partir do regime comercial.
2. Informar contador responsável, operação pretendida e confirmação do credenciamento NFC-e em homologação.
3. Informar ID da empresa já cadastrada na Focus, tokens próprios de gestão e emissão de homologação, fuso horário do emitente, natureza da operação, série e próximo número reconciliado, limite de identificação e prazo de cancelamento aplicáveis à UF. O prazo é limitado ao máximo de 30 minutos informado pela API; prazo inferior da UF deve ser cadastrado. Sem valor fictício padrão.
4. Enviar A1 `.pfx`/`.p12` (até 2 MB) e senha em formulário privado, autenticado e com CSRF. Conferem-se abertura, chave privada, CNPJ exato, assinatura digital e período de validade. Datas X.509 UTC são convertidas para o fuso da aplicação antes de persistir. Isso não equivale à validação completa da cadeia ICP-Brasil/revogação: a aceitação efetiva também depende do provedor e da SEFAZ. A3 e e-CPF sem CNPJ correspondente não atendem este fluxo inicial.
5. Informar CSC/identificador de homologação quando exigidos, ou declarar a dispensa com fundamento validado. Certificado, senha, CSC e tokens são cifrados pelo Laravel, no banco do próprio tenant, e ocultos na serialização. Campos secretos nunca são repopulados em HTML ou `old input` de erro. Campos vazios mantêm segredos já cadastrados; trocar PFX exige a senha correspondente. Não enviar estes valores pelo chat.
6. Autorizar explicitamente a sincronização do cadastro/certificado com a Focus. O sistema consulta primeiro a empresa remota e verifica CNPJ antes do PUT, usa `certificado_especifico=true` e não cria empresa/plano nem habilita produção. Uma alteração local concorrente invalida a sincronização. Mudanças posteriores no cadastro, certificado, CSC ou credenciais exigem nova sincronização.

É necessária conta Focus compatível com SaaS/emitentes, autorizada pelo proprietário. Nenhum plano foi contratado. URLs de protocolo ficam no adaptador, não são campos livres de usuário; não são dados do emitente. **Nenhum CNPJ, endereço, certificado, CSC, token, série ou alíquota de cliente é fixado no código.** Textos legais de homologação e códigos padronizados do protocolo são constantes do produto.

Em **Fiscal → Regras por produto**, registrar CFOP, CST/CSOSN, PIS/COFINS, GTIN/SEM GTIN, CEST quando aplicável, percentual aproximado de tributos e tratamento IBS/CBS, sempre com referência da aprovação do contador. NCM, origem e unidade vêm do catálogo. GTIN tem formato e dígito verificador conferidos; isso não consulta o cadastro GS1.

Cálculo inicial suporta CSOSN 102/103/300/400 sem grupos especiais, CST 00/40/41/50, PIS/COFINS 01/02 ou 04/06/07/08/09, e IBS/CBS integral CST 000 com classificação e alíquotas explícitas, ou não aplicabilidade justificada. Usa BCMath, arredondamento por componente e base efetivamente vendida; não usa custo médio. Para a operação simples suportada, a base IBS/CBS deduz ICMS, PIS e COFINS destacados. ST, combustíveis, reduções, benefícios, importação, monofásicos especiais, IPI, DIFAL, frete e descontos fiscais especiais e demais situações não cobertas exigem extensão, nunca troca artificial de código para permitir emissão. O provedor e a SEFAZ continuam responsáveis pela aceitação oficial do XML.

## Venda, snapshots e pagamentos

O checkout grava `sale_items.fiscal_kind` para os novos itens, inclusive serviços vendidos pelo catálogo unificado. Vínculos legados de serviço continuam preservados. Uma venda antiga sem tipo congelado não é emitida automaticamente: exige revisão assistida, sem inferir que `product_id` significa mercadoria hoje.

Na venda, **Conferir dados fiscais** permite registrar uma conferência imutável (`review_required`) com usuário, tenant, data, hash canônico, valores históricos e pendências. Não reserva número nem emite. Metadados tributários capturados nessa conferência são identificados como atuais e não aprovados automaticamente.

**Solicitar emissão em homologação** exige confirmação explícita de operação e de compartilhamento para teste. Sob lock da venda, valida emitente/certificado, sincronização, operação, regras, destinatário e valores. Sob lock da sequência, reserva um único número e grava documento e outbox na mesma transação. Repetir a solicitação retorna o documento existente. Não usa MAX+1.

O snapshot de emissão congela payload, emitente, regras e referências contábeis, valores, operação, versão XSD, fingerprint do certificado e hash da configuração. Preço/cadastro alterados depois não recalculam a nota. Bruto e desconto comerciais ficam separados no payload; bases e total usam o líquido vendido. Comissão do vendedor não é somada à nota. Apenas o total de mercadorias e a parcela correspondente da forma comercial de pagamento entram na NFC-e; a parcela dos serviços é preservada separadamente. Não há segundo lançamento de estoque ou financeiro ao autorizar/reconciliar.

## Transmissão e recuperação

Estados do documento: `pending → sent → authorized/rejected/uncertain`; cancelamento aceito leva `authorized → cancelled`. Conferência preparatória é um registro distinto. Inutilização tem registro e estados próprios (`pending/uncertain/voided/rejected`). HTTP 200/201 não significa autorização.

O worker recebe IDs e tenant_id, resolve empresa ativa no central, conecta o banco correto, confere snapshot/contexto e sempre limpa a conexão em `finally`. Reclama lease com token em transação curta; todas as chamadas externas ocorrem após commit. Lease expirada/resultado perdido leva a consulta, não a outro POST ou número. Resposta de worker antigo não pode sobrescrever o detentor do lease. Polling é o mecanismo de reconciliação; não há endpoint de webhook exposto nesta versão.

Uma emissão só fica autorizada após resposta oficial 100 e XML autorizado com protocolo, CNPJ, ambiente 2, modelo 65, número/série/chave, digest, assinatura e total correspondentes. XML passa pelo XSD `PL_010_V1.30`, empacotado no lockfile NFePHP, preservando a versão no snapshot. Assinatura é conferida contra o certificado cadastrado no momento da intenção. Falha de download, PDF ou persistência após autorização fica como consulta necessária, recuperando os arquivos sem nova nota. Corpo completo de resposta pessoal nunca vira log ou mensagem de erro.

Após uma consulta retornar referência não encontrada, o administrador pode pedir **reenvio da mesma referência**. O worker consulta novamente; apenas outro 404 permite POST com a referência, número e payload originais. Não há renumeração automática, reset do histórico ou reenvio genérico após timeout. Solicitação explícita é auditada.

Rejeição não é apagada nem corrigida automaticamente. O código oficial é preservado. O fluxo de substituir payload após rejeição ainda exige confirmar a semântica de correção da referência com o provedor e implementar revisões auditáveis; não editar snapshot ou criar outra nota silenciosamente. Autorizações/cancelamentos tardios não ressuscitam documento cancelado.

## Cancelamento, inutilização e caixa

Cancelamento fiscal exige justificativa e estado/prazo configurado da UF. Pedido de cancelamento entra na outbox, e a mudança depende da consulta e do XML do evento oficial aceito (135/155). Preserva XML original, evento/protocolo e DANFE marcado como cancelado. Cancelar a venda não cancela automaticamente a nota, e cancelar a nota não estorna estoque/dinheiro. Não contornar fechamento diário, apagar lançamentos ou reabrir datas.

Inutilização reserva atomicamente a faixa não usada a partir da próxima numeração da série, sem venda fictícia. Não confundir com devolução ou cancelamento. Após resultado incerto, consulta inutilizações antes de qualquer decisão; não repete cegamente a solicitação. Preserva XML e protocolo oficial 102 cifrados. Uma faixa rejeitada não volta automaticamente ao estoque de números.

Contingência/offline não é habilitada. Resposta de contingência exige reconciliação e nunca produz autorização simulada. Evoluir somente após procedimento oficial da UF e suporte comprovado do provedor.

## Arquivos e acesso

XML, cancelamento e PDF ficam no disco `fiscal`, fora do webroot, sem `serve`/symlink, cifrados e com hash SHA-256. Caminhos incluem tenant/ambiente/documento. A1 nunca usa upload de logo e não possui rota de download.

Downloads fiscais exigem administrador autenticado da empresa, validam estado e integridade e usam `private, no-store`/`nosniff`. Caminhos recebidos do provedor são limitados ao host e prefixos conhecidos, sem redirecionamento, URL arbitrária ou envio de token a terceiros. DANFE é gerado pelo NFePHP DA exclusivamente a partir do XML autorizado, incluindo QR oficial e dados congelados; permite baixar/compartilhar manualmente e imprimir o PDF em impressora comum. Não há disparo automático de e-mail/WhatsApp.

## Instalação e operação

Nenhuma instalação na VPS foi executada. Antes de implantar, integrar a branch revisada, executar regressões e seguir `OPERACAO.md`, preservando outros serviços/portas. Dependências novas: NFePHP common, NFePHP NF-e (schemas) e DA; instalar extensões PHP 8.3 incluindo soap, dom/xml, openssl, curl, mbstring, bcmath, pdo_mysql e gd. Usar composer.lock, nunca atualizar dependências na VPS sem revisão.

1. Backup recuperável, manutenção apenas do Vendra, aplicar migrações central e tenants conforme o runbook. Não usar migrate:fresh em bancos operacionais.
2. Garantir permissões privadas do storage e persistência do APP_KEY original. Configurar empresa/certificado no formulário seguro.
3. Testar um lote manual: `php artisan vendra:fiscal-work --company=SLUG --limit=10`.
4. Unidades propostas: `ops/vendra-fiscal.service` e `.timer`, usuário `vendra-web`, caminho compartilhado `/srv/reporvexo`, UMask 0077, sem mudanças nos outros serviços. Revisar nomes/permissões no servidor, instalar unidades, validar com systemd-analyze e só então habilitar o timer. `OnUnitInactiveSec=30` evita sobreposição do mesmo serviço; leases protegem múltiplos executores. Não há dependência de QUEUE_CONNECTION assíncrona.
5. Verificar fila pendente, leases vencidas, consultas necessárias, espaço, erros redigidos e acesso aos arquivos. Suspender o timer fiscal antes de manutenção/backup consistente. Não matar/reiniciar Apache/MySQL compartilhados.

Backup existente inclui bancos e storage privado; o PFX cifrado está no banco do tenant. É obrigatório proteger o pacote externo com chave separada, preservar APP_KEY, restringir acesso e ensaiar restauração em ambiente isolado. Conferir hashes, documentos/eventos/arquivos, isolamento, configurações e fila antes de retomar. Restaurar uma outbox antiga exige consulta oficial antes de reenvio; não zerar attempts/leases ou fabricar estado autorizado. Retenção legal e obrigações por documento devem ser definidas com contador/jurídico; não existe exclusão automática genérica de arquivos fiscais.

## Fontes e decisão de integração

Consultas em 12/11/2026:

- Focus: https://doc.focusnfe.com.br/reference/emitir_nfce , https://doc.focusnfe.com.br/reference/consultar_nfce , https://doc.focusnfe.com.br/reference/cancelar_nfce , https://doc.focusnfe.com.br/reference/inutilizar_numeracao_nfce , https://doc.focusnfe.com.br/reference/consultar_inutilizacoes_nfce , https://doc.focusnfe.com.br/reference/atualizar_empresa . A API é síncrona, mas consulta continua necessária após falha de comunicação. Gestão da empresa usa endpoint próprio de conta, com consentimento explícito e CNPJ conferido; nenhum documento em produção é emitido.
- Dicionário de campos: https://campos.focusnfe.com.br/nfe/ItemNotaFiscalXML.html . Biblioteca/fonte: https://github.com/nfephp-org/sped-common , https://github.com/nfephp-org/sped-nfe , https://github.com/nfephp-org/sped-da . Versões exatas no composer.lock.
- Portal Nacional/XSD/NT: https://www.nfe.fazenda.gov.br/portal/principal.aspx . Confrontar o pacote instalado com NT/cronogramas vigentes da UF antes da homologação oficial; não confundir XSD válido com regra fiscal aprovada.
- Receita/CNPJ alfanumérico: https://www.gov.br/receitafederal/pt-br/centrais-de-conteudo/publicacoes/documentos-tecnicos/cnpj/manual-dv-cnpj.pdf . Identificadores permanecem strings; não remover letras.
- Base IBS/CBS: https://piloto-cbs.tributos.gov.br/servico/calculadora-consumo/calculadora/bases-de-calculo . Cálculo restrito à operação simples suportada; sem assumir alíquota para o cliente.
- GTIN: https://www.gs1br.org/calculadora-de-digitos-de-verificacao . Validação local não equivale à consulta GS1.
- ENCAT/QR: https://nfce.encat.org/desenvolvedor/qrcode/ . CNPJ destinatário exige conferir regra atual/UF, inclusive mudanças: https://atendimento.receita.rs.gov.br/ainda-e-permitido-emitir-nfc-e-para-destinatario-cnpj . Não aplicar proibição antiga sem conferir vigência.

Recomendação continua Focus para o primeiro adaptador: cobertura NFC-e divulgada nacional, A1/CSC, consulta, eventos, inutilização e arquivos. Custo consultado: Retail R$62,90/mês para um emitente, franquia 500 NFC-e/100 NF-e; Retail+ R$657,90/mês, emitentes ilimitados, franquia 9.000 NFC-e/1.000 NF-e. Excedentes/condições e SaaS precisam aceite comercial: https://focusnfe.com.br/precos/ . NFePHP direto evita tarifa de API, mas transfere assinatura, mTLS, manutenção, contingência e operação SEFAZ à Vexo; suas bibliotecas são utilizadas aqui para certificado/schema/DANFE. TecnoSpeed é alternativa para cotação: https://docs.plugnotas.com.br/ . Nuvem Fiscal não recomendada sem confirmar continuidade: página inicial indexada anunciava encerramento em 31/07/2026 e a leitura direta falhou; não reutilizar tabela de preços antiga.

## Validação e pendências

Ver `VALIDACAO_FISCAL.md` para resultados executados. Testes locais cobrem contexto e permissões, certificado/PFX inválido, cifragem, schema/assinatura, cálculos, segregação de serviços, idempotência/concorrência, autorização/rejeição, timeout/consulta/reenvio, cancelamento e resposta tardia, inutilização, arquivos e produção bloqueada. Simulações são identificadas como tais.

Bloqueios externos: credenciais Focus, emitente habilitado/UF real e certificado configurado no formulário; acesso administrativo da VPS não está disponível como credencial neste ambiente. Faltam homologação oficial com XML/protocolo reais de teste, revisão contábil da parametrização, restauração externa ensaiada e virada de produção explicitamente autorizada pelo proprietário. Nenhum mock deve ser chamado de emissão homologada. Produção permanece desabilitada no adaptador, mesmo se flags centrais forem alteradas.
