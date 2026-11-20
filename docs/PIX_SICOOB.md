# Pix Sicoob por título

Em Contas a pagar e receber, o operador com a permissão de gerenciar o financeiro pode gerar uma cobrança imediata pelo saldo de uma parcela a receber. O código e a imagem QR vêm da mesma sequência Pix copia e cola. A consulta compara o txid, a chave e os valores com a reserva do título. A baixa ocorre após a consulta autenticada encontrar um único Pix integral concluído com identificador de ponta a ponta e horário bancário. A data financeira usa o fuso da aplicação. Repetir a geração ou a consulta reutiliza o txid e a baixa. O razão conserva a autoria do emissor da cobrança e o registro de conciliação identifica o operador que consultou.

## Configuração

A configuração abaixo identifica uma única empresa por instalação. O vínculo por ID precisa corresponder ao cadastro central. Empresas demonstrativas ficam fora do fluxo. O aplicativo obtém o token OAuth por client credentials e usa mTLS no token e na API. Os dois endereços devem vir do contrato de acesso do cooperado para o ambiente escolhido.

| Variável | Valor esperado |
|---|---|
| SICOOB_PIX_TENANT_ID | ID central da empresa recebedora |
| SICOOB_PIX_CLIENT_ID | Identificador do aplicativo contratado |
| SICOOB_PIX_KEY | Chave Pix da conta recebedora |
| SICOOB_PIX_API_URL | Base HTTPS da API Pix, incluindo a versão, sem o caminho /cob |
| SICOOB_PIX_TOKEN_URL | Endereço HTTPS completo de emissão de token |
| SICOOB_PIX_CERT_PATH | Caminho privado do certificado PEM aceito pelo banco |
| SICOOB_PIX_PRIVATE_KEY_PATH | Caminho privado da chave PEM correspondente |
| SICOOB_PIX_PRIVATE_KEY_PASSWORD | Senha da chave, quando aplicável |

Instale os arquivos fora da raiz pública, com leitura restrita ao serviço. Confirme o ambiente, cabeçalhos e escopos cob.read e cob.write no portal do banco. Teste primeiro com as credenciais do ambiente de homologação em uma instalação de QA. A integração está desligada por padrão. A implementação foi desenvolvida contra o contrato público do Banco Central e respostas simuladas. A aceitação pelo Sicoob para a conta do cooperado depende de homologação bancária.

## Conferência diária

A geração reserva um identificador local e consulta GET /cob/{txid}. Só após uma resposta 404 o sistema solicita PUT no mesmo recurso. Uma falha de comunicação conserva o identificador. O botão de consulta busca a cobrança novamente e registra o recebimento elegível. Enquanto o QR estiver em circulação, consulte o banco antes de receber o título por outro meio.

A validade solicitada é de uma hora. Se o código expirar, houver devolução, diferença de valor, mudança de conta, baixa por outro meio ou fechamento prévio do caixa na data bancária, confira o extrato com txid e endToEndId. A rotina atual não recria automaticamente cobranças expiradas, altera datas de fechamento, processa devoluções nem consome webhooks. A conciliação é iniciada pelo operador. O serviço mantém o estado bancário no Sicoob e grava no histórico local a reserva e a conciliação. O teste integrado simula a criação, o pagamento divergente, a confirmação e a repetição da consulta.

## Referências

- [Contrato oficial da API Pix do Banco Central, versão 2.10.0](https://github.com/bacen/pix-api/blob/2.10.0/openapi.yaml)
- [Portal Developers Sicoob](https://developers.sicoob.com.br/portal/)
