# Revisão funcional/técnica — NFC-e direta à SEFAZ

Consulta: **12/11/2026**. Escopo exclusivo do checkout `/workspace/vendra-fiscal-review`; nenhum arquivo ou dado do PDV publicado foi alterado. Esta revisão leu `TaxProfile`, `NfcePayload`, `FiscalReadiness`, configuração/schema e `FISCAL.md`. Não recebeu certificado real, não transmitiu documento, não consultou cadastro privado nem demonstrou homologação oficial.

## Decisão de integração

O objetivo atual do proprietário é **integração direta à SEFAZ, sem API paga**, com certificado A1/PFX de cada empresa e ambiente de homologação. O documento `FISCAL.md` lido ainda descrevia Focus, tokens de emissão/gestão, empresa remota e sincronização do PFX. Esse contrato não serve como pré-requisito do transporte direto: remover/desativar essas dependências no adaptador/configuração/readiness direto, sem enviar certificado ou dados à Focus. Nenhuma contratação é necessária para utilizar os webservices oficiais; continuam existindo os custos próprios de certificado, desenvolvimento, manutenção e operação.

Uma configuração local validada substitui a ideia de “sincronização com provedor”. Seu hash deve vincular emitente, ambiente, UF/autorizador, certificado, série/numeração, parâmetros aprovados e versão de regras. Os transportes devem permanecer distintos; escolher “direto” não pode chamar o adaptador Focus por fallback.

## Fontes oficiais efetivamente consultadas

| Fonte | Evidência obtida nesta consulta | Consequência |
|---|---|---|
| [SVRS — Documentos NFC-e](https://dfe-portal.svrs.rs.gov.br/Nfce/Documentos) | Índice acessível: MOC7.00 e anexos; Manual DANFE/QR5.1; NT2025.002v1.51 RTC datada01/08/2026; NT2026.006 vinculação de pagamento datada25/08/2026; NT2026.004 CNPJ alfa v1.01 com homologação indicada para15/06/2026; IT2025.002 cClassTrib v1.71 e tabela datadas30/10/2026; tabela de meios de pagamento04/03/2026. | Confrontar a implantação e o pacote instalado com essas versões e cronogramas, não apenas com um MOC antigo ou versão do NFePHP. Datas são as exibidas no índice; não equivalem a vigência uniforme em todas as UFs. |
| [SVRS — Serviços NFC-e](https://dfe-portal.svrs.rs.gov.br/Nfce/Servicos) | Relação oficial separa produção/homologação e autorizadores AM, GO, MS, MT, PR, RS, SP e SVRS; serviços4.00 de autorização, retorno, consulta, status, eventos e inutilização. A página informa adicionar `?wsdl` à URL para obter WSDL. | Resolver autorizador/endpoint pela UF e modelo65/ambiente2. Não copiar o endpoint de outra empresa nem presumir que toda UF usa a SVRS. |
| [SVRS — classificação tributária](https://dfe-portal.svrs.rs.gov.br/CFF/ClassificacaoTributaria) | Tabela oficial acessível apresenta CST000 “Tributação integral” e cClassTrib000001 “Situações tributadas integralmente pelo IBS e CBS”, além de diversos CSTs/classes e indicadores. | Formato com seis dígitos não comprova classe existente, compatível, permitida no modelo e vigente. CST/classe/indicadores devem ser cruzados com tabela versionada. |
| [Receita Federal — Reforma Tributária](https://www.gov.br/receitafederal/pt-br/assuntos/reforma-tributaria) | Portal oficial acessível e referências para serviços/orientações da reforma. | Usar orientação oficial junto da NT/tabelas e validação da operação; não criar alíquota geral por suposição. |
| [Portal Nacional NF-e](https://www.nfe.fazenda.gov.br/portal/principal.aspx), listas de NT/schema | As consultas às listas retornaram ciclo302 neste ambiente. | Não interpretei a falha como ausência de atualização nem como confirmação de pacote vigente. |
| [ENCAT — QR](https://nfce.encat.org/desenvolvedor/qrcode/) | Resposta503 nesta consulta; o manual5.1 foi localizado no índice oficial SVRS. | Não declarei conteúdo/cronograma detalhado de QR como conferido por esse endpoint. |

Limites de acesso: o índice SVRS fornece downloads por `DownloadArquivoEstatico`; as tentativas aos PDFs/XLSX devolveram HTTP200 com corpo vazio. Portanto, **o conteúdo integral e os cronogramas desses anexos não foram lidos nesta revisão**. A página da calculadora oficial de bases IBS/CBS foi uma aplicação sem texto recuperável; não fundamenta uma fórmula universal aqui. O Planalto respondeu429 para LC214 e a página estadual sobre CNPJ destinatário retornou bloqueio. Não repeti esses acessos como se fossem provas positivas.

Não uso documentação de API privada como fonte normativa para o transporte direto. O conjunto final a arquivar antes da homologação é MOC/anexos, NTs efetivamente vigentes, tabelas e pacote XSD com data/hash, além das regras publicadas pela **UF real** do emitente. A UF não foi inferida de telefone, endereço de demonstração ou nome da loja.

## Achados materiais no código lido

### 1. Bruto comercial, unitário e desconto não podem formar XML inconsistente

`NfcePayload` utiliza quantidade histórica, `unit_price` ofertado, `gross_total` comercial e `discount_amount` combinado. No comercial, o bruto pode ser maior que quantidade×oferta porque considera preço de catálogo anterior à redução implícita.

Exemplo: catálogo100, oferta90, desconto explícito5%; bruto comercial100, desconto combinado14,50, líquido85,50. O payload atual pode levar quantidade1, unitário90, bruto100 e desconto14,50. O produto quantidade×unitário é90, não100.

O XML deve escolher **uma representação fiscal coerente**, derivada dos snapshots, sem mudar o líquido ou histórico comercial. Duas representações aritméticas possíveis são bruto100/unitário100/desconto14,50 ou bruto90/unitário90/desconto4,50; a implementação precisa selecionar e testar uma delas, com precisão de `qCom`, `vUnCom`, `vProd`, `vDesc` e tolerâncias previstas no leiaute. Não misturar campos das duas. Conferir também unidades tributáveis/conversões; o fluxo estreito pode exigir unidade comercial=tributável e bloquear conversão não modelada.

### 2. `TaxProfile` valida forma, mas não toda a regra tributária

Pontos positivos: parâmetros explícitos; incompatibilidade básica CST/CSOSN×CRT; BCMath; GTIN com dígito; código não suportado rejeitado em vez de improvisado.

Limites concretos:

- `^5xxx` não comprova CFOP adequado à revenda e ao CST/CSOSN/NCM/operação. Não aceitar um CFOP de ST com um perfil “sem ST” só porque ambos passam no formulário. A operação inicial deve ter combinações permitidas explicitamente aprovadas, não todos os5xxx.
- `CRT1/4`×CSOSN por comprimento é apenas uma primeira barreira. MEI/Simples, benefícios, incidência e códigos permitidos precisam de enquadramento específico. Não trocar código para satisfazer validação.
- ICMS, PIS e COFINS usam a mesma base líquida da linha. Essa base não é universal: enquadramento, exclusão de ICMS da base de PIS/COFINS quando aplicável, reduções, benefícios, despesas e demais componentes podem mudar o cálculo. Uma operação fora da fórmula implementada deve ser bloqueada, ou receber modelo explícito e aprovado; não apresentar o cálculo genérico como correto para todos os regimes.
- Os códigos PIS/COFINS de alíquota zero/monofásico/isenção não são intercambiáveis. Não preencher um deles automaticamente para “zerar” imposto.
- `transparency_rate` é estimativa informativa separada, não soma obrigatória dos tributos destacados. Precisa de origem/critério aplicável ao produto; não usar uma taxa fictícia para satisfazer o campo.

A função recebe o valor da venda, não o custo médio; isso é correto como distinção comercial/fiscal. Ainda assim, a base fiscal final deve ser a base legal da operação, não uma aproximação universal da receita.

### 3. Reforma IBS/CBS precisa de tabela, vigência e caso suportado

O perfil atual só suporta CST000 integral, mas aceita qualquer `rtc_class` de seis dígitos. Deve validar classe real, compatibilidade com CST, indicadores e permissão no modelo/ambiente/data segundo tabela versionada. A página oficial confirma que000001 existe para tributação integral; isso **não** significa que seja o código correto para todos os produtos ou empresas.

`rtc_mode=not_applicable` com justificativa livre não comprova dispensa. Na primeira emissão real, ausência de tratamento tributário vigente/validado deve bloquear; não usar essa opção para suprimir grupo obrigatório.

A fórmula atual `líquido − ICMS − PIS − COFINS` pode representar somente um caso específico; não cobre automaticamente IS, IPI, regimes especiais, reduções ou todas as regras de transição. As alíquotas dependem do enquadramento e data; valores não conhecidos devem permanecer pendentes, **não virar zero por padrão**. Zero informado explicitamente também precisa de justificativa/enquadramento compatível.

Conferir os totais RTC requeridos no leiaute vigente (`IBSCBSTot` e demais grupos/campos aplicáveis), além de totais ICMS tradicionais. Não basta adicionar percentuais ao payload ou obter um XML que passe num XSD antigo. Número do pacote `PL_010_V1.30` não é, por si só, prova de equivalência à NT2025.002v1.51; comparar os arquivos/regra efetivos, sem concluir incompatibilidade apenas pela diferença de números.

### 4. GTIN local não é cadastro de produto validado

O dígito verificador de GTIN8/12/13/14 e a rejeição de todos zeros são barreiras úteis. Não comprovam existência no CCG/GS1, titularidade, compatibilidade NCM/CEST ou regras de obrigatoriedade da NT2023.003 atualmente listada em v1.30.

`SEM GTIN` só cabe quando o produto realmente não tem GTIN. Não substituir por esse literal após rejeição do cadastro central. Produto com GTIN/unidade tributável diferente precisa de modelagem própria; o fluxo inicial pode exigir igualdade comprovada.

### 5. Pagamento precisa representar os fatos da venda

`NfcePayload` exige `sale.paid` e limita método comercial a dinheiro/Pix/transferência. Porém o comercial permite várias baixas com métodos distintos; quitação booleana e método originalmente escolhido não comprovam que todo o recebimento ocorreu naquele único meio.

No transporte inicial, conferir títulos/baixas válidas da venda, ausência de legado sem evidência e consistência do valor/método/troco. Não transmitir “Pix integral” se parte foi dinheiro. Código fiscal de Pix precisa distinguir a modalidade aceita pela tabela vigente; o mapeamento genérico de todo `pix` para17 exige conferência da tabela oficial atual, não presunção de QR dinâmico. O nome comercial `transfer` também precisa corresponder ao código fiscal e dados exigidos.

Cartão, pagamentos mistos, parcial, crediário e vinculações exigidas pelas NTs específicas devem permanecer bloqueados até modelados. A NT2026.006 de vinculação de pagamentos está listada oficialmente; sua existência não prova obrigatoriedade imediata para todo caso, mas exige análise de aplicabilidade/cronograma antes de declarar suporte.

### 6. Venda mista e documentos municipais

A classificação fiscal congelada em `sale_items.fiscal_kind` é importante: `product_id` no catálogo unificado não significa mercadoria. Serviços municipais não entram como mercadoria na NFC-e nem recebem NCM fictício.

O builder separa mercadorias/serviços e atribui apenas o valor de mercadorias à forma de pagamento. Isso preserva o total comercial, mas **não prova sozinho** o rateio fiscal do recebimento nem emite a parte municipal. O readiness sinaliza serviços enquanto o builder filtra apenas certos bloqueadores; essa diferença precisa ser deliberada e visível.

Corte seguro para a primeira homologação direta: venda de mercadorias apenas, ou segregação explicitamente revisada e auditada com documentos/pagamentos correspondentes. Não tornar uma venda mista “resolvida fiscalmente” só porque a parte de serviço desapareceu do XML. NFS-e continua fora do escopo; a classificação final exige a operação/empresa reais, não o nome do cadastro.

### 7. Readiness e dependências do adaptador

`FiscalReadiness` retorna `ready_to_transmit=false` por ser conferência e adiciona pendências genéricas. Esse relatório não é uma autorização de emissão; a intenção/transmissão deve repetir validações determinísticas do fluxo suportado.

No direto, substituir mensagens/guardas de sincronização Focus por prontidão local: A1 válido e correspondência do emitente, CSC/QR quando aplicável, UF/autorizador/ambiente/serviços corretos, credenciamento e perfil tributário aprovado. Manter desconhecido como bloqueio específico; não apagar toda a lista só para habilitar botão.

## Fluxo estreito recomendado para PR1

1. Homologação (`tpAmb=2`), modelo65, emitente real validado e UF/autorizador explicitamente resolvidos.
2. Venda interna presencial, consumidor final não contribuinte, somente mercadorias do caso tributário efetivamente implementado/aprovado. Limites de identificação/destinatário dependem da UF e das alterações vigentes; não presumir aceitação de todo CNPJ destinatário por ter dígito válido.
3. Sem ST/combustível/benefício/redução/importação operacional especial/frete/contingência ou outras regras não implementadas. Não simular abrangência por combinação livre de campos.
4. Quantidade/unidade, base, bruto/desconto/líquido, tributos e totais reconciliados a partir de snapshots; nenhuma alteração no estoque/financeiro ao autorizar.
5. Quitação e meio real únicos comprovados, com grupo fiscal de pagamento válido. Preferir iniciar por dinheiro em valor exato se Pix/transferência ainda não tiverem todos os dados necessários — isso não obriga alterar o pedido do usuário para outro produto fiscal.
6. Assinar XML, validar schema/regras antes do envio, transmitir uma única intenção/numeração e consultar após incerteza. Produção bloqueada até autorização posterior e evidência oficial.

Esse corte não impede implementar agora assinatura, transporte, outbox e arquivos. O que impede o envio é uma pendência real do emitente/operação/regra, não a mera ausência de outros módulos comerciais ou de NF-e55/NFS-e.

## Dependências para homologação oficial ao vivo

| Dependência | Evidência necessária |
|---|---|
| Empresa real | CNPJ como string, IE, CRT confirmado, endereço/código IBGE/UF/fuso; cadastro não vem da demo. Validar correspondência legal com certificado e habilitação estadual. |
| A1/PFX por empresa | Upload privado, senha segura, chave privada íntegra, vigência, identificação do CNPJ e cadeia/confiança aplicável. Não enviar segredo no chat, log ou para API paga. mTLS/assinatura usam o certificado da empresa correta. |
| Credenciamento | Habilitação de NFC-e em homologação na SEFAZ competente; certificado por si só não credencia. Empresa exclusivamente de serviços pode precisar outro enquadramento/documento. |
| CSC/QR | ID/CSC de homologação quando exigidos pela versão/regras da UF, ou dispensa fundamentada. Não reutilizar credencial de produção nem assumir que um booleano de dispensa basta. |
| Protocolos e endpoints | Conjunto oficial por UF, modelo65 e ambiente2: autorização/retorno/consulta/status/eventos/inutilização e URLs oficiais de QR/consulta. URLs não podem ser destinos arbitrários fornecidos pelo usuário. |
| Versões tributárias | MOC/anexos, NTs/schema/tabelas efetivos versionados, incluindo RTC/GTIN/pagamentos/CNPJ alfa aplicáveis, com regras do contador e caso de teste esperado. |
| Numeração | Série/próximo número reconciliados com a empresa/ambiente; reserva transacional e payload/XML persistidos. Nada de MAX+1, renumerar após timeout ou resetar faixa para fazer passar. |
| Rede/TLS | mTLS até o autorizador, confiança TLS preservada e SOAP/cURL/OpenSSL/XML disponíveis. Proxy com inspeção TLS pode impedir certificado cliente; usar configuração suportada de passthrough/execução, nunca desativar a verificação. |
| Resultado oficial | XML enviado/assinado, resposta de autorização e protocolo reais de homologação, chave/ambiente/modelo/CNPJ/série/número/digest/total correspondentes, consulta posterior e DANFE/QR verificáveis. Status de serviço107 ou HTTP200 não autorizam nota. |

Homologação deve incluir autorização, consulta e evento de cancelamento válidos para o caso, registrando rejeições como rejeições. Prazos e elegibilidade do cancelamento são da UF/regra vigente, não de limite de API Focus. Inutilização não é cancelamento de venda; seu evento/protocolo precisa corresponder à faixa real reservada. Contingência permanece desabilitada no corte inicial.

## Protocolo, QR e preservação

- NFC-e mantém versão de XML4.00, mas alterações por NT/schema são independentes do número4.00. Assinatura, namespace, `infNFe/@Id`, chave e grupos novos devem ser coerentes.
- Resposta recebida/lote processado/rejeição/duplicidade não é autorização. Consultar a chave para recuperar protocolo após perda de resposta; não emitir outra nota automaticamente.
- QR oficial depende da versão aplicável, ambiente e URL da UF; não usar URL de exemplo como se fosse cupom real. O DANFE é representação do XML autorizado, não fonte para inventar protocolo.
- Cancelamento fiscal aceito preserva XML original e evento/protocolo. Não estorna automaticamente estoque/dinheiro nem reabre caixa; cancelamento comercial não prova cancelamento fiscal.
- Snapshot deve guardar regras/data/fontes e versão de configuração; tabela ou certificado alterados depois não justificam reescrever uma intenção incerta. Recuperar/consultar antes de corrigir.

## Portas de qualidade que resolvem os riscos encontrados

- Testar o exemplo100/90/5% e quantidade fracionária verificando `qCom×vUnCom`, bruto/desconto/líquido e totais no XML, não só no array intermediário.
- Recusar classe RTC inexistente, CST/classe incompatíveis, ausência de alíquota/regra e “não aplicável” sem condição suportada; não converter ausência em0.
- Testar cálculo com ICMS/PIS/COFINS e RTC contra caso esperado aprovado; bloquear caso fora do modelo de base.
- Recusar pagamento misto/legado não comprovado e modalidade fiscal de Pix desconhecida; não transformar método comercial em prova de liquidação.
- Impedir serviço municipal ou item legado ambíguo de se tornar mercadoria por conveniência; manter a parte não emitida visível.
- Demonstrar com transporte simulado a idempotência e recuperação; só chamar de homologação oficial após protocolos/XML reais. Testes de PFX autogerado não demonstram aceitação ICP-Brasil/SEFAZ.

## Conclusão

É viável implementar o primeiro adaptador direto agora, sem contratação de API paga, preservando as barreiras existentes de homologação e o histórico comercial. Os bloqueios materiais são: coerência fiscal de bruto/unitário/desconto, enquadramento/base/RTC efetivamente suportados, pagamento verdadeiro e ambiente/emitente reais prontos. Os dados desconhecidos devem bloquear somente a operação afetada com motivo claro, sem paralisar o desenvolvimento nem inventar defaults para transmitir.

UF real, certificado/CSC e regras definitivas não foram inferidos nesta revisão. Nenhuma simulação ou página acessível substitui XML/protocolo real de homologação; nenhuma mudança na VPS foi feita.
