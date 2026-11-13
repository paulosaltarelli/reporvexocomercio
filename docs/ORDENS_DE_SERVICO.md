# Ordens de serviço

O painel de operação permite iniciar venda, orçamento ou OS. A OS atende à entrada de equipamentos e veículos: cliente, bem atendido (modelo, número de série ou placa), responsável técnico, previsão e relato/diagnóstico. Técnico e vendedor são campos distintos; o vendedor creditado continua sujeito às regras de atribuição e comissão do sistema.

## Atendimento

1. No painel, selecione o cliente e clique em **Abrir nova OS**.
2. Registre o bem e o relato. É possível receber o atendimento sem definir peças, serviços ou preços.
3. Enquanto a OS estiver aberta, complete o diagnóstico e seus produtos/serviços. Os preços e descontos passam pelas mesmas validações comerciais das vendas. A edição preserva o vendedor creditado.
4. Depois de definir os itens, registre a aprovação, inicie a execução e conclua o serviço.
5. Na OS concluída, gere a venda e informe pagamento ou parcelamento. A operação preserva o vínculo com a OS e não duplica a venda em uma repetição.

A lista de documentos permite filtrar OS e orçamentos, etapa, vendedor e buscar atendimentos. O comprovante interno pode ser impresso na página da OS usando a identificação da empresa configurada no sistema.

## Efeitos e limites

- Abrir, editar, aprovar ou concluir uma OS não reserva nem movimenta estoque, não cria contas financeiras e não emite nota fiscal. A baixa e os lançamentos ocorrem ao gerar a venda; o saldo é validado nessa etapa.
- A edição de itens e diagnóstico fica disponível somente na OS aberta. Aprovação e etapas posteriores bloqueiam essa edição. Atualizações baseadas em uma versão antiga devem ser recarregadas.
- Aprovar uma OS exige itens. Um orçamento continua exigindo itens desde a criação.
- A aprovação é registrada manualmente pela equipe; esta versão não coleta assinatura digital ou aceite remoto do cliente.
- Emitir NFC-e continua sendo uma operação fiscal separada na venda, com suas próprias regras. Uma OS com serviços não transforma os serviços em mercadorias nem habilita NFS-e.
- Nesta entrega não há reserva antecipada de peças, checklists específicos por segmento, anexos/fotos, assinatura ou histórico individual das mudanças de etapa.

## Implantação

Use o overlay revisado de `ops/deploy-work-orders.sh`, com manifesto e baseline da VPS. Não há nova migração ou dependência. Preserve as configurações fiscais por empresa, certificados, chave da aplicação e todos os arquivos fora do manifesto. A validação ocorre em bancos de teste isolados antes da implantação; nunca use dados operacionais para testar abertura ou faturamento.
