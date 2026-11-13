# Primeira autorização em homologação

## Documentos distintos

- **NF-e, modelo 55:** documento estadual de mercadorias, com DANFE. Nesta integração inicial, a operação é presencial, interna e destinada a cliente identificado, com endereço fiscal estruturado.
- **NFC-e, modelo 65:** cupom fiscal eletrônico de mercadorias, com DANFE NFC-e. Usa série e numeração próprias, certificado A1 e CSC da empresa.
- **NFS-e:** documento de serviços, com regras municipais e integração própria. Não é emitida pelos web services NF-e da SEFAZ-MG.

Os testes internos do Vendra não autorizam documentos fiscais. Uma autorização em homologação exige resposta do ambiente oficial e documento fiscal correspondente; um cenário de simulação nunca satisfaz esse requisito.

## Preparação no Vendra

1. Em Minha empresa, confira razão social, CNPJ, inscrição estadual, regime tributário e endereço completo com código IBGE. Não selecione regime ou CRT apenas para contornar uma validação.
2. Cadastre o A1/PFX válido e sua senha na tela segura. O certificado deve corresponder ao CNPJ da empresa. Não envie arquivo, senha ou CSC em mensagens, issues ou commits.
3. Configure e valide localmente o modelo desejado. NF-e e NFC-e têm configuração e sequência independentes. NF-e não exige CSC; NFC-e exige o CSC e seu identificador fornecidos pela UF.
4. Cadastre as regras fiscais dos produtos, diretamente ou por perfil/grupo. NCM, origem, unidade e GTIN/SEM GTIN pertencem ao produto. CFOP, CST/CSOSN e alíquotas dependem da operação e do regime: não são preenchidos com taxas presumidas.
5. Para NF-e, complete o endereço fiscal do cliente e sua condição de contribuinte de ICMS. Para o primeiro teste, use uma venda contendo somente mercadorias e uma forma de pagamento atendida pela integração.
6. Confirme expressamente a transmissão em homologação na venda. Acompanhe o resultado no histórico fiscal; timeout exige consulta da mesma chave, sem emitir novamente com nova numeração.
7. Confira o XML autorizado e o DANFE do modelo correto. Cancelamento fiscal e cancelamento comercial são operações distintas.

Substituir o certificado exige revalidação das configurações afetadas. Trocar a configuração atual não reescreve o XML de uma emissão já solicitada.

## Primeiro corte da NF-e

O suporte inicial não cobre venda interestadual, exportação, frete destacado, devolução, ICMS-ST/IPI ou separação de mercadorias e serviços de uma venda mista. Operações fora desse corte devem ser bloqueadas, preservando integralmente os itens da venda. Não omitir serviços para fazer uma venda mista parecer uma venda exclusivamente de mercadorias.

## NFS-e em Araguari

Segundo o comunicado municipal atualizado em 04/11/2026, Simples Nacional passa a usar exclusivamente o Emissor Nacional desde 01/11/2026; demais regimes a partir de 01/12/2026. A empresa precisa confirmar regime, habilitação e parâmetros aplicáveis. A adesão de um município ao ambiente de dados nacional, isoladamente, não confirma a habilitação de cada prestador para emitir.

O ambiente nacional de testes é chamado **produção restrita**. Ele não se confunde com produção nem com simulação local. Consulte [NFSE_ARAGUARI.md](NFSE_ARAGUARI.md) para fontes, cronologia e limitações verificadas.

## Fontes oficiais

- SEFAZ-MG / NFC-e: https://portalsped.fazenda.mg.gov.br/spedmg/nfce/
- Portal Nacional NF-e / documentos: https://www.nfe.fazenda.gov.br/portal/principal.aspx
- Araguari / transição NFS-e: https://www.araguari.mg.gov.br/comunicado-importante-emissao-de-notas-fiscais-de-servicos
- NFS-e / documentação: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual
- NFS-e / produção restrita: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita
