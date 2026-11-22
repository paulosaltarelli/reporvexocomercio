# NFC-e direta no Vendra

O módulo fiscal integra a aplicação existente e as vendas. Não há PDV separado nem provedor pago. A comunicação usa endpoints oficiais selecionados pela biblioteca NFePHP conforme a UF do emitente. A primeira UF informada pelo proprietário é MG; os dados reais permanecem configuráveis por empresa.

## Configuração

Em Minha empresa, complete razão social, CNPJ, IE, regime tributário, endereço e código IBGE. Informe credenciamento NFC-e de homologação, responsável contábil e operação. Cadastre PFX/A1 da empresa, senha, CSC e identificador de homologação, CRT, série, próxima numeração reconciliada, fuso horário e limites aplicáveis à UF. Nenhum dado fiscal da empresa é fixado no código.

Certificado e segredos ficam criptografados com a chave da aplicação e vinculados à empresa emitente. A validação local confere o certificado e a configuração da empresa. O credenciamento e a autorização de documentos são verificados junto à SEFAZ. Alterações invalidam a validação anterior.

Cadastre as regras fiscais dos produtos com o contador. A implementação inicial atende somente operações expressamente suportadas e bloqueia situações sem dados suficientes; não presume alíquotas, benefícios ou enquadramentos. Serviços precisam de NFS-e e não são emitidos neste módulo. Confira as restrições tributárias na interface e no código TaxProfile.

## Venda e documento

### Prévia da reforma em 2027

Em **Fiscal → Gerenciar perfis tributários**, crie um perfil com a finalidade **Prévia de revenda em 2027**. Informe a data de início, a alíquota efetiva de CBS e a referência da validação contábil. A data final padrão é 31/12/2027. Em **Fiscal → Regras por produto → Simular 2027**, selecione esse perfil para comparar o cálculo antes de mudar o vínculo do produto.

A prévia atende uma revenda interna CFOP 5102 no lucro real ou presumido, com ICMS CST 00 e IBS/CBS integrais. Ela calcula o IBS estadual, o municipal e a CBS por item e exibe a base, o ICMS destacado e o total. A transmissão de documentos com essa regra, o Simples Nacional, o tratamento específico de restaurantes e as mercadorias com alíquotas reduzidas exigem outras etapas de implementação.

Em **Compras**, a configuração `VENDRA_PURCHASE_INVOICES_ENABLED=true` disponibiliza a entrada de NF-e de fornecedores independentemente da emissão de mercadorias. A prévia do XML confere os valores declarados de IBS, CBS e Imposto Seletivo com os totais da nota. O responsável informa o tratamento dos tributos cobrados antes da confirmação da compra.

### Homologação da venda

A ação fiscal na venda permite conferir a operação e solicitar NFC-e em homologação. A série/número são reservados sob bloqueio; XML assinado, chave, configurações e itens são congelados antes de qualquer transmissão. A outbox processa a comunicação fora das transações comerciais. Repetir a solicitação retorna o mesmo documento.

Consulta usa a chave original. Timeout, processamento ou duplicidade não provocam emissão com outro número. Reenvio exige solicitação administrativa e nova consulta que confirme documento não encontrado, sempre com os mesmos bytes assinados. Autorização exige XML/protocolo correspondentes ao documento original, ambiente e assinatura. Uma resposta HTTP bem-sucedida não autoriza nota.

Cancelamento exige documento autorizado, motivo e prazo configurado, além de evidência oficial do evento correspondente. XML autorizado, XML do cancelamento e DANFE ficam privados e vinculados à venda, com controle de acesso por empresa. Inutilização ainda está desabilitada, sem consumir numeração. Contingência e produção não estão habilitadas.

## Operação

`php artisan vendra:fiscal-work --company=SLUG --limit=10` processa a fila. As unidades ops/vendra-fiscal.* permitem execução periódica com usuário exclusivo. Dependências estão fixadas em composer.lock. XMLs/PDFs ficam no disco privado fiscal, fora do diretório público. Backup deve preservar bancos, arquivos privados e APP_KEY; não rotacione a chave sem migração dos segredos.

A biblioteca exige extensão SOAP. Na VPS compartilhada a extensão foi preparada em /opt/reporvexo-tools/php para carregar somente no Vendra; não altere o PHP dos outros sistemas. Os testes usam configuração CLI própria nesse diretório.

## Validação e limites

Testes com certificados autogerados e respostas SOAP simuladas verificam software, não equivalem a homologação oficial. Sem certificado ICP-Brasil real, CSC, credenciamento e dados do emitente não é possível comprovar emissão na SEFAZ. Produção permanece bloqueada, inclusive para a base com dados ilustrativos. A ativação futura depende de revisão contábil, manutenção das notas técnicas e evidências oficiais de homologação.

Fontes: https://www.nfe.fazenda.gov.br/portal/ ; https://dfe-portal.svrs.rs.gov.br/Nfe ; https://www.sped.fazenda.mg.gov.br/spedmg/nfce/ ; https://github.com/nfephp-org/sped-nfe ; https://github.com/nfephp-org/sped-common ; https://github.com/nfephp-org/sped-da . Consulte docs/REVISAO_FISCAL_DIRETA.md para achados e limitações da pesquisa das especificações atuais.

## OS mista em homologação

A área de NFS-e permite consultar os registros históricos da empresa. A emissão da nota de serviço é externa ao Vendra. Na conferência de uma OS mista já vinculada a um registro histórico de DPS, a aplicação verifica a integridade desse registro e a correspondência entre itens e valores da venda. A segregação para homologação de mercadorias exige que o valor total seja integralmente reconciliado.

Os modelos 55 e 65 consultam os meios efetivamente recebidos para exibir a modalidade do Pix. Para uma unidade comercial acima de seis caracteres, abra a regra fiscal do produto e informe uma abreviação equivalente. Ela é aplicada a uCom e uTrib sem conversão adicional de quantidade ou preço.
