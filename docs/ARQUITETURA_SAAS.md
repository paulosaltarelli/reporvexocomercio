# Arquitetura SaaS Vendra

## Pacote e controle central

Um pacote atende todas as empresas. O banco central mantém tenants, administradores
da plataforma, solicitações de implantação e auditorias. O painel `/vexo-admin`
tem autenticação própria; usuários das empresas não são administradores centrais.
Solicitações HTTP não recebem credenciais de banco nem executam provisionamento.
O worker administrativo consome a fila pelo mesmo control plane do comando interativo. O modo automático envia um convite de ativação para o e-mail do primeiro administrador. Consulte [Provisionamento de empresas em subdomínios](PROVISIONAMENTO_SUBDOMINIOS.md).

## Dados operacionais compartilhados

O modo compartilhado usa MySQL com tabelas físicas `_p_*`, coluna obrigatória
`_scope_tenant_id`, índices únicos e chaves estrangeiras compostos por empresa.
Cada empresa recebe uma identidade SQL própria, com permissões somente nas views
operacionais. Views e triggers vinculam o escopo à identidade autenticada, sem
aceitar um identificador de empresa fornecido pelo formulário. O runtime verifica
identidade, hash do manifesto e política de isolamento antes de abrir a conexão.
IDs históricos são preservados no namespace da empresa. SQL direto e validações
Laravel continuam sujeitos à mesma identidade SQL. Sem contexto, a conexão falha.

As credenciais administrativas ficam fora da aplicação, em
`/var/lib/vendra/controlplane/shared.json`, com acesso exclusivo do root.
O manifesto certificado fica em `schema.json` no mesmo diretório. Nenhum desses
arquivos pertence ao Git. Alterações futuras de schema exigem procedimento próprio;
`tenants:migrate` não aplica migrações legadas sobre views compartilhadas.

## Primeira empresa e novos clientes

Tenant 1, slug `vexo`, é a demonstração existente: preservar IDs, dados, usuários,
APP_KEY, certificados e arquivos fiscais. Não executar seeds nem recriar a loja.
Clientes novos recebem apenas perfil vazio, configuração inicial, grupo básico,
lock de caixa e administrador com troca obrigatória de senha. Produtos, clientes,
vendas e histórico demonstrativo não são copiados.

## Publicação e corte

`ops/deploy-company-platform.sh` aplica somente a primeira fase: configuração por
empresa, wizard, painel central e schema aditivo. Valida manifests, protege landing
e código fora do overlay, usa o lock compartilhado, drena o worker, verifica backup
e compara projeções completas dos dados e arquivos privados antes de reabrir HTTPS.
Não cria sozinho a estrutura compartilhada nem migra a demonstração.

Depois da primeira fase: validar template exclusivo de testes e manifesto; criar
somente destino novo; ensaiar importação em QA; pausar apenas Vendra/timer com worker
drenado; obter backup verificado recente; executar dry-run e importação privilegiada.
O importador reconcilia todas as linhas por hashes e quantidades, mantém checks FK e
unique ativos e só então troca a conexão central, com journal e auditoria.
Falhas ambíguas exigem reconciliação; não repetir nem restaurar cegamente.

Após o corte, verificar a identidade SQL, contagens, hashes e HTTPS da demonstração
antes de retomar o serviço. A base isolada anterior permanece preservada; não voltar
a ela depois de gravações no compartilhado sem reconciliar os dados posteriores.
Backups podem conter `controlplane.tar.gz` além dos arquivos de aplicação e bancos.
O comando `platform:restore-shared-access` recupera identidades a partir do material
privado validado, sem rotacionar credenciais existentes nem alterar dados.

## Limites desta entrega

Não inclui trabalhos paralelos de PDV/OS nem a migração OS10. A landing e os demais
sistemas, Apache global e portas de outros serviços ficam fora da implantação.
Testes fiscais usam simulação/homologação e não transmitem documentos reais.
