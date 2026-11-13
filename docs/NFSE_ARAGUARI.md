# NFS-e em Araguari: integração direta e homologação

Pesquisa consultada em **13/11/2026**. Documento de evidências e dependências; não representa emissão homologada, autorização fiscal ou implementação concluída.

## Rota municipal e transição nacional

O comunicado oficial de Araguari, publicado em **29/10/2026** e atualizado em **04/11/2026**, informa:

- ME/EPP optantes pelo Simples Nacional: emissão exclusivamente pelo Emissor Nacional desde **01/11/2026**, deixando de emitir pelo NFe Cidades.
- Lucro Presumido, Lucro Real e demais não optantes: NFe Cidades permitido até **30/11/2026**; uso exclusivo do Emissor Nacional a partir de **01/12/2026**.
- Sistemas próprios devem providenciar integração com o Emissor Nacional. O comunicado cita a Resolução CGSN nº 191/2026; essa referência foi encontrada no comunicado, sem validação independente do texto normativo nesta pesquisa.
- Contato oficial para dúvidas: Departamento de Tributação, **(34) 3690-3013**.

Fonte primária: https://www.araguari.mg.gov.br/comunicado-importante-emissao-de-notas-fiscais-de-servicos

A página municipal de serviço, publicada e modificada em **18/09/2026**, ainda direciona para https://www.nfe-cidades.com.br/ e identifica o serviço como gratuito. Ela é anterior ao comunicado de transição; seu link não basta para decidir a rota fiscal atual do contribuinte.

Fonte: https://www.araguari.mg.gov.br/servicos/nota-fiscal-eletronica-nfe

### Divergência no monitoramento nacional

Na planilha oficial de adesões de **10/11/2026**, primeira planilha, linha 2829, o registro MG/ARAGUARI apresenta `Conveniado Ativo`, `AderenteAmbienteNacional=Sim`, `AderenteEmissorNacional=Não`, `AderenteMAN=Não`, `AtivoNaBase=Sim` e `AtivoUltimoPeriodo=Sim`. O CNPJ 16829640000149 dessa linha é o do município, não o da empresa usuária.

Essa fotografia administrativa diverge do comunicado municipal sobre a operação do emissor. Adesão ao ambiente nacional/ADN não equivale, isoladamente, a habilitação para emissão via Sefin. Por outro lado, a flag negativa da planilha não deve invalidar automaticamente o comunicado de migração. A implementação pode avançar para o padrão nacional, mas a habilitação efetiva precisa ser verificada com a empresa, seus parâmetros municipais e uma resposta do ambiente restrito.

Fontes: https://www.gov.br/nfse/pt-br/municipios/monitoramento-adesoes e https://www.gov.br/nfse/pt-br/municipios/monitoramento-adesoes/municipios-aderentes-20261110.xlsx/@@download/file

## Integração nacional sem intermediário pago

Há documentação oficial para integração direta do contribuinte. NFS-e utiliza serviços próprios do Sistema Nacional NFS-e: não é emissão pela SEFAZ de NF-e/NFC-e e não reutiliza CSC de NFC-e.

A página oficial de APIs, atualizada em **20/08/2026**, lista estes endereços de documentação:

- Sefin Nacional, produção restrita: https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional/docs/index
- Sefin Nacional, produção: https://sefin.nfse.gov.br/SefinNacional/docs/index
- Parâmetros municipais, produção restrita: https://adn.producaorestrita.nfse.gov.br/parametrizacao/docs/index.html
- ADN contribuintes, produção restrita: https://adn.producaorestrita.nfse.gov.br/contribuintes/docs/index.html
- DANFSe, produção restrita: https://adn.producaorestrita.nfse.gov.br/danfse/docs/index.html

Esses são URLs de documentação, não URLs de operação a serem concatenados por suposição. As tentativas de consultar o Swagger Sefin neste ambiente retornaram falha de transporte/reset; não se comprovou a causa nem uma conexão autenticada bem-sucedida.

Fonte: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao

O manual oficial de contribuintes descreve `POST /nfse` para recepção da DPS XML, validação e retorno síncrono da NFS-e ou rejeição; `GET /nfse/{chaveAcesso}` para consulta; consultas da DPS e eventos próprios. Os exemplos de caminhos devem ser combinados com o contrato vigente, sem inventar envelope JSON ou endereço-base. O manual também descreve consulta do convênio e dos parâmetros por município/serviço. A consulta de DPS restringe determinadas informações à identidade do certificado da conexão correspondente ao prestador, tomador ou intermediário.

Manual disponibilizado na documentação atual: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-emissor-publico-api-sistema-nacional-nfs-e-v1-2-out2025.pdf/@@download/file . O nome do arquivo menciona v1.2/out2025; o histórico interno consultado contém primeira versão de 17/03/2025. Não se assume que ele substitua schemas/notas técnicas posteriores.

A página de documentação atual foi modificada em **04/11/2026**. A página de produção restrita, modificada em **20/10/2026**, disponibiliza NT SE/CGNFS-e 009, anexos v1.01 de 20/10/2026 e schemas correspondentes. Portanto o adaptador deve validar DPS contra os arquivos atuais do ambiente selecionado, incluindo exigências de IBS/CBS aplicáveis; não transportar automaticamente o modelo tributário de mercadorias para serviços.

Fontes: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual e https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita

## NFe Cidades: limite do que foi comprovado

O aplicativo público contém menus `Importar RPS e Escriturações (formato XML)`, `manual` e `Códigos para integração`. O caminho de interface do manual é `/home/actions/import-gov-manual`. Ao acessá-lo sem sessão, o servidor entrega a estrutura do aplicativo, não um PDF ou contrato de integração. Não foi encontrado manual público verificável com WSDL, endpoint de homologação ou padrão ABRASF comprovado para Araguari. Não se deve adivinhar esses dados nem contratar um fornecedor com base em semelhança de nomes.

Se a empresa ainda precisar da rota municipal durante a transição: acessar o portal pelo link oficial, entrar na conta do contribuinte, selecionar Araguari e abrir os menus acima; obter o manual/PDF ou URL público, schemas, endereço oficial de homologação e procedimento de habilitação. Se não estiverem disponíveis, solicitá-los ao Departamento de Tributação. Credenciais devem ser configuradas diretamente no ambiente seguro; não enviar senha, chave privada ou PFX por conversa. Para empresa já obrigada ao nacional, o manual legado não é pré-requisito da implementação nacional.

## Próximos passos concretos para homologar

1. Confirmar município e regime tributário reais da empresa, inscrição municipal e habilitação. Não preencher CNPJ/IBGE/inscrição usando o registro do município. Aplicar as datas de transição acima.
2. Disponibilizar certificado válido da empresa e senha somente no armazenamento seguro já previsto; validar titularidade, validade, assinatura XML e autenticação TLS conforme contrato atual. O cadastro/login do portal não substitui a integração autenticada.
3. Consultar convênio e parâmetros municipais vigentes: serviço, tributação, retenção, regime e benefícios. Bloquear combinação desconhecida em vez de inventar alíquota. Parametrização depende do contribuinte e serviço; não basta existir schema.
4. Implementar DPS e consulta de resultado exclusivamente em produção restrita, com identificador/numeração persistidos e idempotência. Distinguir rejeição fiscal, falha de transporte e resultado desconhecido; consultar antes de repetir uma transmissão inconclusiva.
5. Executar uma DPS válida e guardar retorno autorizado ou rejeição real, XML, chave e protocolo/resposta. Só afirmar homologação após essa evidência. A consulta DANFSe é etapa posterior à existência da nota.
6. Em OS/venda com produtos e serviços, separar os documentos e bases: serviços seguem NFS-e; produtos seguem o fluxo NF-e/NFC-e suportado. Não incluir silenciosamente toda a venda na DPS nem apresentar recibo interno como nota autorizada.

Não foi realizada emissão nesta pesquisa. A viabilidade técnica direta está documentada; o sucesso para a empresa depende de habilitação, certificado, parametrização e resposta real de homologação. Não foi comprovado endpoint municipal legado nem equivalência entre ele e o padrão nacional.
