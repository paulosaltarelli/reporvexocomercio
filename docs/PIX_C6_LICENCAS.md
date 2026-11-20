# Recebimento das licenças por Pix C6

A plataforma cobra uma parcela de contrato por vez, na conta C6 da titular da plataforma. Em Licença, abra a parcela e gere o Pix. O QR e o copia e cola contêm a mesma sequência fornecida pelo banco. O vencimento da parcela vem do contrato. O QR da cobrança imediata tem validade solicitada de uma hora.

O botão de consulta e o conciliador verificam no servidor a cobrança pelo txid e o recebimento pelo endToEndId. Os dois registros bancários precisam concordar sobre txid, valor integral, horário e identificador de ponta a ponta. A chave da cobrança precisa ser a da conta recebedora. O saldo e o acesso da empresa são recalculados pela baixa de contrato. Confirmação no navegador, cobrança aberta ou pagamento em valor divergente não liquidam a parcela.

Uma tentativa de geração com resposta incerta conserva o txid. Emissão repetida consulta esse identificador antes de tentar criar a cobrança. Para gerar outro QR, o sistema exige confirmação de expiração da tentativa anterior no banco e saldo em aberto. O histórico preserva as duas tentativas. Se chegar um crédito tardio, o conciliador pode localizar a tentativa original. Créditos excedentes ou incompatíveis exigem conferência com o extrato do C6. Um endToEndId corresponde a uma única baixa na plataforma.

## Homologação e ambientes

Solicite o credenciamento de software house e o roteiro no portal C6 Developers. Confirme com o banco os endereços completos de token e da base da API Pix para o ambiente, a forma de autenticação OAuth, os escopos e a chave e certificado mTLS da conta recebedora. As páginas públicas do banco identificam o produto e a etapa de homologação. O portal técnico retornou HTTP 403 nesta pesquisa, portanto endereços bancários, credenciais e escopos específicos da conta não foram validados nesta entrega. O código não define URLs externas padrão.

- [C6 Empresas — APIs de integração](https://www.c6bank.com.br/apis-integracao/)
- [C6 Bank — cadastro e homologação de ERPs](https://www.c6bank.com.br/blog/api-c6-bank)
- [C6 Developers — portal do integrador](https://developers.c6bank.com.br/)
- [API Pix do Banco Central, contrato 2.10.0](https://github.com/bacen/pix-api/blob/2.10.0/openapi.yaml)

A integração implementa os recursos relativos do padrão do Banco Central. Usa GET /cob/{txid} e, somente se o banco responder 404, PUT /cob/{txid}. Confirma um recebimento por GET /pix/{endToEndId}. Esse último recurso consulta o crédito Pix identificado da conta autenticada. O sistema não importa o extrato geral da conta corrente. Reembolsos e créditos parciais ou múltiplos seguem para revisão. Não há operação de transferência de saída.

## Configuração privada

Os nomes abaixo têm prefixo C6_PLATFORM_PIX_. Para o sandbox de uma empresa de demonstração coexistir com clientes reais, use o prefixo C6_PLATFORM_PIX_SANDBOX_ em um segundo conjunto **completo e independente**. Nesse segundo conjunto o ambiente já é sandbox.

| Sufixo | Valor |
|---|---|
| ENABLED | true somente após configurar o acesso ao ambiente correspondente |
| ENVIRONMENT | production para clientes reais, sandbox para instalação exclusiva de homologação |
| APPROVAL_REFERENCE | Referência da autorização do C6 para aquele ambiente |
| API_URL | URL HTTPS da base da API Pix do contrato C6, com prefixo e versão, anterior a /cob e /pix |
| TOKEN_URL | URL HTTPS completa de emissão do token |
| AUTH_METHOD | client_secret_post ou client_secret_basic, conforme o contrato de credenciamento |
| SCOPE | Escopos autorizados, se exigidos na solicitação do token |
| CLIENT_ID, CLIENT_SECRET | Credenciais do aplicativo vinculado à conta recebedora |
| KEY | Chave Pix da conta recebedora |
| CERT_PATH, PRIVATE_KEY_PATH | Caminhos privados do certificado e da chave PEM do banco |
| PRIVATE_KEY_PASSWORD | Senha da chave privada, quando aplicável |

Mantenha chaves e certificados fora da raiz web, legíveis apenas pelo processo do serviço. A demonstração exige perfil de sandbox e URLs HTTPS de token e API em domínio C6 com identificação sandbox. Empresas reais exigem perfil de produção. O ambiente do Laravel pode continuar em produção para que o usuário teste o sandbox da demonstração. As reservas vinculam conta e ambiente e as baixas identificam a empresa e a parcela. Nenhuma credencial bancária é entregue ao navegador.

Para o ensaio da demonstração, abra uma parcela da própria demonstração, gere o QR e confira a expiração. Use somente o mecanismo autorizado pelo C6 para simular o recebimento no sandbox. Consulte a parcela, compare o endToEndId e o valor com o registro de crédito e repita a consulta para verificar uma única baixa. Faça um segundo ensaio com expiração e renovação do QR. Registre os identificadores de teste, datas e protocolo de aprovação sem armazenar tokens ou chaves privadas. Configure a produção somente com as credenciais e os endereços de produção autorizados para a conta.

O worker de licenciamento exige habilitação separada para conciliar e emitir antecipadamente. A emissão automática preserva o primeiro txid e retoma a mesma tentativa após timeout. Uma cobrança expirada só é renovada sob demanda na parcela. No cadastro de demonstração, a emissão é manual no sandbox.

## Testes locais

A suíte C6 usa respostas HTTP simuladas com chamadas externas bloqueadas. As regressões de contrato exercitam pagamento divergente, consulta autenticada do recebimento, repetição da baixa, isolamento por empresa, expiração, renovação e confirmação tardia de uma tentativa anterior. As verificações locais não substituem a liberação do aplicativo pelo banco.

## Notificações e reversão

A conciliação periódica consulta o banco sem depender de webhook. A
implementação de notificações aguarda a especificação C6 aplicável à conta
Vexo, incluindo autenticação, formato, cadastro e reenvio. Uma notificação
autenticada deve disparar a mesma verificação de cobrança e recebimento usada
na conciliação. Inclua na homologação a entrega duplicada e a recuperação de
uma notificação perdida.

Em uma reversão, configure `C6_PLATFORM_PIX_ISSUANCE_PAUSED=true` na aplicação
e no worker para suspender novas emissões e renovações. Preserve o histórico. Mantenha a conciliação e a configuração bancária
disponíveis para os Pix já emitidos. A configuração atual de emissão
automática controla apenas o worker. Valide o procedimento com a frente que
opera a Hostinger antes da publicação.
