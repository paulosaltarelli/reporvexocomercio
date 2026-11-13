# Schemas oficiais NFS-e: produção restrita

Origem: Portal Nacional NFS-e, documentação de produção restrita atualizada em 20/10/2026, consultada em 13/11/2026:
https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita

Arquivos extraídos do pacote oficial de schemas produção restrita v1.01 de 20/10/2026. Cópias integrais, sem alteração de conteúdo; hashes locais em SHA256SUMS. A versão e a data devem ser revistas antes de habilitar qualquer transmissão.

Este corte prepara DPS tpAmb=2, assina XMLDSig RSA-SHA1/C14N e valida estrutura localmente. A escolha de algoritmos localmente suportada pelo schema não comprova aceitação das regras atuais do endpoint; confirmar manual de assinatura/contrato autenticado antes de transmitir. Não há autorização, endpoint de emissão ativo, DANFSe ou cancelamento neste módulo.

Dependências ainda bloqueantes para transmissão: A1 do prestador; habilitação real no emissor nacional; regras municipais/serviço e tributação verificadas; contrato Swagger autenticado, envelope de requisição, limites, esquema de resposta, comprovação XML NFS-e/assinatura/ambiente/eco DPS e consulta por DPS em timeout. Não aceitar JSON mock como evidência fiscal.

A rota antiga NFe Cidades não é equivalente à API nacional. Araguari comunicou migração nacional em 01/11/2026 para Simples e 01/12/2026 para demais regimes: https://www.araguari.mg.gov.br/comunicado-importante-emissao-de-notas-fiscais-de-servicos
