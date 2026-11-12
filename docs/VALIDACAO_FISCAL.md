# Validação fiscal direta

A integração está sendo validada em ambiente isolado, com bancos exclusivos de teste. As respostas SOAP e protocolos das fixtures são sintéticos, os certificados são autogerados e nenhum desses resultados constitui autorização oficial da SEFAZ ou certificado ICP-Brasil.

A suíte cobre configuração privada por empresa, bloqueio de produção, snapshots, reserva e idempotência, emissão assinada, consulta por chave, reconciliação após resposta incerta, cancelamento correlacionado, rejeição de XML alterado, arquivos privados e isolamento entre empresas. Resultados concretos da execução devem ser registrados após a suíte, sem converter mocks em evidência oficial.

Homologação oficial pendente: completar dados reais, credenciamento MG, PFX e CSC no formulário seguro; revisar regras com contador; emitir/consultar/cancelar documento de homologação e guardar XML/protocolos reais. Não enviar certificado, senha ou CSC pelo chat. A implantação da interface não significa que uma nota oficial já foi emitida.

## Resultados desta implementação

- Regressão comercial e unitária: 86 casos aprovados na execução consolidada, sem testes ignorados.
- Fluxos fiscais exercitados com assinatura e schema reais: emissão idempotente, autorização/XML/DANFE privados, consulta após timeout, processamento pendente, rejeição, cancelamento, concorrência MySQL, permissões e isolamento. Duas falhas de fixture foram corrigidas antes da implantação (singleton com ID protegido e raiz da assinatura do evento).
- Rodada fiscal posterior: 28 casos aprovados; a asserção restante foi ajustada para a mensagem pública do cancelamento rejeitado. A rodada direcionada final aprovou 2 casos/40 asserções, incluindo essa rejeição e a validação de evidência/rotação de certificado. Certificado de terceiro não pinado continua rejeitado, mesmo com assinatura matematicamente válida.
- Smoke adicional sem banco/rede: XML de MG e SP assinados e válidos no schema; envelopes SOAP 1.2 de autorização, consulta e cancelamento processados; desconto zero omitido corretamente; rejeição de cancelamento correlacionada encerra a tentativa e duplicidade mantém reconciliação.
- Auditoria das dependências fixadas: sem avisos de vulnerabilidade encontrados na execução do Composer audit.

Todos os retornos e protocolos desses testes são sintéticos. Nenhuma nota oficial foi emitida nesta validação.
