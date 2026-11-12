# Perfis tributários reutilizáveis

Esta etapa permite organizar parâmetros tributários comuns em perfis, vinculá-los a produtos em lote e definir um perfil padrão por grupo, preservando exceções individuais. A organização do cadastro não amplia os modelos/operações fiscais suportados nem constitui validação legal automática.

## Regras de uso

A prioridade de resolução é:

1. Exceção/regra tributária individual existente no produto.
2. Perfil vinculado diretamente ao produto.
3. Perfil padrão do grupo, quando não houver as duas alternativas anteriores.

Um vínculo selecionado que esteja inativo, inválido ou incompleto deve bloquear a conferência/emissão afetada com motivo específico. Não cair silenciosamente para uma regra inferior só para conseguir transmitir. Uma exceção individual não é removida ao associar um perfil por lote ou grupo; retirá-la deve ser uma ação separada, explícita e auditada.

- Cada perfil pertence à empresa conectada, tem nome, parâmetros, revisão e referência de conferência contábil. Administradores autorizados fazem a manutenção; uma revisão concorrente exige reabrir e conferir o formulário.
- Um grupo fornece **herança**, não uma cópia em massa de regras para cada produto. Produto com vínculo direto ou exceção permanece prioritário.
- A associação em lote tem prévia e confirmação. O conjunto confirmado e a revisão do perfil precisam corresponder à prévia; alterações concorrentes exigem nova conferência. Perfis específicos existentes só podem ser substituídos quando isso for escolhido explicitamente; exceções individuais continuam preservadas.
- Revisões e vínculos registram auditoria antes/depois e autoria. Repetir a mesma requisição não deve duplicar a aplicação; reutilizar a chave para outra intenção deve ser recusado.
- Mudanças afetam **conferências e intenções futuras**. Não reescrever snapshots, XML assinado, regras/valores congelados de documentos antigos ou operações incertas; estes precisam ser recuperados/consultados no fluxo próprio.
- A associação altera campos fiscais de vínculo/identidade e auditoria; não recalcula preço, margem, custo médio, estoque, comissão, vendedor ou caixa.

## O que não é compartilhado pelo perfil

GTIN, CEST, NCM, origem e unidade pertencem ao produto. Não copiar um código de outro item só porque ambos usam o mesmo perfil; não derivar classificação tributária apenas do grupo comercial ou do nome do produto. Os novos campos fiscais GTIN/CEST podem continuar pendentes após a migração, sem serem preenchidos artificialmente.

`SEM GTIN` é uma declaração factual para produto que realmente não possui GTIN, não um substituto automático para dado faltante ou rejeitado. Dígito verificador local não comprova cadastro GS1/CCG. CEST e NCM exigem enquadramento e tabelas aplicáveis; cadastrar um perfil não os valida por si só.

## Limites e responsabilidade dos parâmetros

O sistema valida formato, compatibilidades e o recorte fiscal efetivamente implementado. Campos com nomes “validado” ou “referência contábil” registram quem informou/conferiu os dados na aplicação; não comprovam, sozinhos, revisão profissional ou aprovação oficial.

- Não criar perfis de exemplo na empresa operacional durante deploy, nem preencher alíquotas desconhecidas com zero.
- Uma alíquota zero explicitamente informada também precisa ser correta para empresa, produto, operação, data e enquadramento. Não trocar CST/CSOSN, PIS/COFINS ou classificação RTC apenas para passar na validação.
- Um perfil não é universal para todo produto, UF, destino, regime ou período. Operações fora do recorte implementado continuam bloqueadas ou exigem evolução revisada do cálculo.
- A função compartilhada não elimina certificado, CSC/QR quando aplicável, credenciamento, configuração do emitente, numeração reconciliada, transporte seguro ou validações de pagamento/documento exigidos pelo fluxo oficial.
- Esta etapa não libera produção, não executa emissão e não transforma uma simulação interna em homologação oficial.

## Qualidade necessária antes da publicação

Validar isolamento entre empresas, controle de acesso, precedência individual/direto/grupo, perfil inativo sem fallback, revisão concorrente, vínculo em lote com prévia obsoleta, idempotência e auditoria. Demonstrar que documentos congelados e regras individuais permanecem intactos, que GTIN/CEST não são inventados e que o cálculo/transporte continua bloqueando casos não suportados.

Conferir os pontos compartilhados de emissão e simulação e preservar os arquivos de PDV, preços, estoque, custo médio, comissão, vendedores e caixa. O hash dos arquivos protegidos complementa os testes comportamentais fiscais, sem representar uma execução inexistente da suíte comercial.

## Plano incremental de implantação

Ferramenta: `ops/deploy-fiscal-profiles.sh`. Base esperada: `main` em `b5b8154`, depois da etapa de simulação/rascunho. Stage permitido: `/srv/vendra-fiscal-profile-qa.*`. O responsável informa o caminho concreto depois de preparar e validar o QA.

A ferramenta exige `--apply` para executar; o estado efetivo de cada implantação fica registrado no journal privado. No modo padrão, ela só imprime o plano:

```sh
bash ops/deploy-fiscal-profiles.sh --plan
bash -n ops/deploy-fiscal-profiles.sh
```

### Manifestos

Usar formato `SHA256`, dois espaços, caminho relativo, sem comentários, caminhos absolutos, `..`, duplicatas ou symlinks:

- `overlay.sha256`: somente arquivos finais revisados dos perfis, resolver/manager, controller/model/auditoria, parâmetros/views fiscais, pontos fiscais necessários e migração000007. Testes, Composer, `vendor`, dados, segredos e demais módulos não entram no overlay.
- `baseline.sha256`: hashes dos arquivos do overlay que já existiam no `main` efetivamente publicado. Diferença na VPS exige revisão, não aceite automático do conteúdo local ou sobrescrita de mudanças desconhecidas.
- `pdv.sha256`: reutilizar `/root/vendra-fiscal-review/pdv.sha256`, a relação protegida de101 arquivos comerciais/PDV. Tanto stage como produção precisam corresponder; arquivo protegido não pode entrar no overlay.

O digest do próprio manifesto final precisa ser conferido independentemente e informado ao script. Qualquer mudança posterior ao QA exige atualizar o manifesto e revalidar o trecho correspondente. A allowlist é exata; um novo caminho necessário deve ser acrescentado conscientemente ao plano, nunca por wildcard amplo.

`Product` e `ProductGroup` não precisam de modificação em seus arquivos de modelo nesta etapa. A migração acrescenta colunas fiscais de vínculo; não permitir alterações comerciais nesses modelos para contornar o escopo. `composer.json`/`composer.lock` devem permanecer idênticos no stage e em produção; não há dependências novas e o script não executa Composer.

Exemplo, **somente depois** de QA e revisão reais:

```sh
VENDRA_FISCAL_PROFILES_DEPLOY_APPROVED=1 VENDRA_FISCAL_PROFILES_QA_PASSED=1 \
bash /srv/vendra-fiscal-profile-qa.DIRETORIO_REVISADO/ops/deploy-fiscal-profiles.sh --apply \
  --stage /srv/vendra-fiscal-profile-qa.DIRETORIO_REVISADO \
  --manifest /root/vendra-fiscal-profiles/overlay.sha256 \
  --baseline /root/vendra-fiscal-profiles/baseline.sha256 \
  --pdv /root/vendra-fiscal-review/pdv.sha256 \
  --manifest-sha256 DIGEST_REVISADO_DE_64_HEXADECIMAIS
```

As variáveis documentam a decisão operacional do administrador e não substituem autorização, revisão ou evidência de testes.

### Sequência de aplicação

1. Conferir base/overlay/PDV e dependências, lintar PHP novo e verificar a leitura do ini CLI como `vendra-web`. Não alterar ini/SO ou configurações PHP globais.
2. Registrar estado anterior do timer/oneshot, parar só `vendra-fiscal.timer` e esperar o lote em andamento terminar naturalmente por até60 segundos. Nunca matar um SOAP em voo nem chamar o worker manualmente para “restaurá-lo”.
3. Parar apenas `vendra.service`, manter Apache80/443/8443/8444 e MySQL ativos e suspender comandos administrativos concorrentes de escrita Vendra durante a janela.
4. Confirmar por leitura, com web e worker pausados, que a outbox real de todas as bases retidas está vazia de operações incompletas. Não apagar, concluir ou renumerar pendências para passar nessa verificação.
5. Fazer backup local privado com aplicação/APP_KEY e bancos pelo procedimento existente; guardar journal em `/var/lib/vendra/fiscal-profiles-deploy`. Esse material contém segredos, não pertence ao Git/chat e ainda precisa de proteção externa e recuperação ensaiada.
6. Copiar somente o overlay como `root:vendra-web`/640, diretórios750, sem percorrer `vendor`, normalizar toda a aplicação ou tocar arquivos fiscais privados existentes.
7. Executar **somente** `database/tenant-migrations/2026_11_12_000007_create_fiscal_tax_profiles.php` em cada banco retido. Não executar migração central, migrações fiscais anteriores ou `migrate-tenants` genérico. A migração cria perfis/auditoria e vínculos fiscais **nullable**, sem cadastrar perfis, alíquotas ou dados fiscais reais.
8. Incluir empresas inativas por clone de contexto de manutenção em memória, sem alterar seu campo `active` ou habilitar acesso. As operações da aplicação usam `vendra-web` e ini próprio; o shell administrativo só gerencia backup, arquivos e serviços.
9. Limpar caches de configuração/rotas/views, compilar Blade e lintar os PHP compilados. Normalizar apenas caches gerados. Não modificar estado comercial nem campos da empresa, não usar seed e não importar PFX/CSC.
10. Conferir novamente hashes do PDV e sintaxe do Apache próprio. Iniciar só Vendra e aguardar login HTTPS200 por tentativas limitadas, mantendo a validação TLS. Somente então restaurar o estado ativo anterior do timer, sem alterar enabled/disabled persistente e sem executar o oneshot manualmente.

A retomada do timer restaura o fluxo oficial já existente; quando houver uma intenção deliberadamente enfileirada e requisitos válidos, ele continuará podendo processá-la. A criação/associação de perfis não enfileira automaticamente notas, e o script não faz chamada SEFAZ nem teste de emissão.

### Falha e recuperação

Antes de começar o overlay, uma falha tenta restabelecer o serviço anterior e só retoma o timer depois de HTTPS pronto. Após cópia/migração parcial, mantém somente Vendra e timer em manutenção para revisão. Conferir sempre o estado efetivo dos serviços.

MySQL DDL não é transacional; a migração preserva histórico e não admite rollback destrutivo. Não reexecutar cegamente, apagar auditoria ou restaurar backup por cima de outras bases. Documentos reais, outbox, XML/protocolos e APP_KEY precisam ser preservados. O retorno de pacote anterior pode ser incompatível com schema evoluído; decidir a correção a partir do journal e do ponto de recuperação revisado.

## Validação depois da publicação

Conferir lista inicialmente sem perfis semeados, criar um perfil somente com dados deliberadamente fornecidos pelo responsável, inspecionar a prévia de associação, preservar exceção individual, testar herança de grupo e apresentar pendências reais. As primeiras verificações em produção podem ser apenas de leitura; fixtures ficam no QA e não devem aparecer na empresa operacional.

Disponibilidade HTTPS e timer ativo não comprovam correção tributária ou autorização oficial. Registrar separadamente funcionalidades validadas, limitações e qualquer pré-requisito real para emissão.

## Evidência de validação

- FiscalTest completo: 37 testes, 456 verificações aprovados, sem falhas ou testes ignorados.
- Unitários: 24 testes, 50 verificações aprovados.
- Filtro HTTP final: 1 teste, 39 verificações aprovadas, incluindo recuperação da prévia por GET após erro de confirmação e bloqueio do operador.
- O filtro inicial dos quatro novos casos também passou; suas verificações não são somadas à suíte completa.
- Cobertura: prioridade de regras, identidade atual do produto e compatibilidade legada, bloqueio de perfil inativo, padrão por grupo, prévia obsoleta, atomicidade/idempotência, substituição explícita de vínculos diretos, preservação de exceções e de identificadores, permissões e isolamento, snapshots de NFC-e e simulação preservados por revisão.
- Nenhum certificado ou protocolo fictício foi cadastrado na empresa operacional, nenhuma alíquota foi presumida e nenhum teste transmitiu dados à SEFAZ.
- A ferramenta de implantação foi revisada em modo plano. Uma execução efetiva registra manifests, backup, estado dos serviços e resultado no journal privado.
