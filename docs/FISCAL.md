# NFC-e direta no Vendra

O módulo fiscal integra a aplicação existente e as vendas. Não há PDV separado nem provedor pago. A comunicação usa endpoints oficiais selecionados pela biblioteca NFePHP conforme a UF do emitente. A primeira UF informada pelo proprietário é MG; os dados reais permanecem configuráveis por empresa.

## Configuração

Em Minha empresa, complete razão social, CNPJ, IE, regime tributário, endereço e código IBGE. Informe credenciamento NFC-e de homologação, responsável contábil e operação. Cadastre PFX/A1 da empresa, senha, CSC e identificador de homologação, CRT, série, próxima numeração reconciliada, fuso horário e limites aplicáveis à UF. Nenhum dado fiscal da empresa é fixado no código.

Certificado e segredos ficam criptografados com a chave da aplicação no banco exclusivo da empresa. Nunca são exibidos novamente, enviados a intermediário ou incluídos no repositório. A validação local confere certificado/configuração; não comprova credenciamento nem autorização da SEFAZ. Alterações invalidam a validação anterior.

Cadastre as regras fiscais dos produtos com o contador. A implementação inicial atende somente operações expressamente suportadas e bloqueia situações sem dados suficientes; não presume alíquotas, benefícios ou enquadramentos. Serviços precisam de NFS-e e não são emitidos neste módulo. Confira as restrições tributárias na interface e no código TaxProfile.

## Venda e documento

A ação fiscal na venda permite conferir a operação e solicitar NFC-e em homologação. A série/número são reservados sob bloqueio; XML assinado, chave, configurações e itens são congelados antes de qualquer transmissão. A outbox processa a comunicação fora das transações comerciais. Repetir a solicitação retorna o mesmo documento.

Consulta usa a chave original. Timeout, processamento ou duplicidade não provocam emissão com outro número. Reenvio exige solicitação administrativa e nova consulta que confirme documento não encontrado, sempre com os mesmos bytes assinados. Autorização exige XML/protocolo correspondentes ao documento original, ambiente e assinatura. Uma resposta HTTP bem-sucedida não autoriza nota.

Cancelamento exige documento autorizado, motivo e prazo configurado, além de evidência oficial do evento correspondente. XML autorizado, XML do cancelamento e DANFE ficam privados e vinculados à venda, com controle de acesso por empresa. Inutilização ainda está desabilitada, sem consumir numeração. Contingência e produção não estão habilitadas.

## Operação

`php artisan vendra:fiscal-work --company=SLUG --limit=10` processa a fila. As unidades ops/vendra-fiscal.* permitem execução periódica com usuário exclusivo. Dependências estão fixadas em composer.lock. XMLs/PDFs ficam no disco privado fiscal, fora do diretório público. Backup deve preservar bancos, arquivos privados e APP_KEY; não rotacione a chave sem migração dos segredos.

A biblioteca exige extensão SOAP. Na VPS compartilhada a extensão foi preparada em /opt/reporvexo-tools/php para carregar somente no Vendra; não altere o PHP dos outros sistemas. Os testes usam configuração CLI própria nesse diretório.

## Validação e limites

Testes com certificados autogerados e respostas SOAP simuladas verificam software, não equivalem a homologação oficial. Sem certificado ICP-Brasil real, CSC, credenciamento e dados do emitente não é possível comprovar emissão na SEFAZ. Produção permanece bloqueada, inclusive para a base com dados ilustrativos. A ativação futura depende de revisão contábil, manutenção das notas técnicas e evidências oficiais de homologação.

Fontes: https://www.nfe.fazenda.gov.br/portal/ ; https://dfe-portal.svrs.rs.gov.br/Nfe ; https://www.sped.fazenda.mg.gov.br/spedmg/nfce/ ; https://github.com/nfephp-org/sped-nfe ; https://github.com/nfephp-org/sped-common ; https://github.com/nfephp-org/sped-da . Consulte docs/REVISAO_FISCAL_DIRETA.md para achados e limitações da pesquisa das especificações atuais.
