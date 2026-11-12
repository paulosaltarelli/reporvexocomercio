# Normalização visual da base de informática

`App\Services\ComputerStoreLabels::normalize(): array` é uma migração cosmética e idempotente da carga antiga `computer-store-v1`. Não executa a carga, não apaga dados e não transforma a base em operações reais.

Só opera no banco `tenant` já resolvido, se existir `DemoRun` com esse código e `completed_at` preenchido. A marca interna, resumo e datas da carga permanecem intactos. Sem marca concluída, retorna `eligible=false` e não altera nada.

## Alcance limitado

- Produtos pelos SKUs originais exatos `DEMO-*`; serviços por `DEMO-SERV-*` ou registro legado com nome/preço/custo exatos da carga. Nomes alterados manualmente são preservados.
- Grupos pelos sete nomes exatos originais com `(Demo)`.
- Clientes e fornecedores pela combinação exata de nome original e e-mail `example.test`. Combinações ambíguas são ignoradas e reportadas.
- Compras, vendas e documentos pelas chaves UUIDv5 originais e limitadas da carga. Só limpa notas/referências que ainda são exatamente as originais.
- Descrições históricas são comparadas por bytes, não por collation sem distinção de maiúsculas/acentos. Nas compras, a alteração continua limitada às compras identificadas. Em vendas posteriores do usuário (incluindo a 17ª venda), a descrição exata antiga pode ser limpa somente se `product_id`/`service_id` pertencer à coorte comprovada da carga. O mesmo vale para itens JSON de novos documentos; notas/status e demais campos de documentos do usuário não são alterados.
- A coorte é comprovada pelos SKUs originais e/ou pelos IDs de itens das compras/vendas com UUIDv5 originais, com descrição original ou já normalizada; serviços legados também usam o vínculo `legacy_service_id`. Isso mantém a operação idempotente mesmo depois de nomes/SKUs terem sido normalizados. Descrição customizada e item de fora da coorte permanecem intactos.
- Contas manuais pelos códigos UUIDv5 originais de oito contas e descrição exata.

SKUs ficam `INF-<código>` para físicos e `SERV-<código>` para serviços. Se houver colisão com cadastro existente, usa sufixo determinístico pelo ID e, se necessário, contador. Grupos também recebem sufixo quando o nome limpo já existe; nenhum grupo é mesclado e nenhum vínculo é trocado.

Não altera valores monetários, quantidades, IDs, vínculos, status, datas, pagamentos, revisões de preços nem `CashClosure`/snapshots imutáveis. As atualizações são feitas sem atualizar timestamps. Endereços que eram exatamente as frases fictícias da carga ficam vazios; não são inventados dados reais. E-mails de exemplo não são convertidos em contatos reais.

O retorno contém `eligible`, `code`, `updated`, contagens por tabela e `skipped`. Uma segunda execução deve retornar `updated=0`. Operação é transacional, com trava da marca da carga; colisão concorrente em identificador único falha/retrocede em vez de substituir outro cadastro.

Fonte criada sem execução na VPS. Antes de aplicar, QA deve conferir idempotência, empresa sem marcador, preservação de edição manual, colisão de SKU/grupo e igualdade integral de valores/datas/IDs/vínculos e snapshots antes/depois. Este serviço não remove os registros demonstrativos nem os mistura a uma carga nova.
