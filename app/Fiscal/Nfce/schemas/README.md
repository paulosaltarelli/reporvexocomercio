# Schemas de homologação da NFC-e

O perfil de revenda interna do Simples de 2026 valida o XML de cada nova NFC-e após a assinatura e antes de gravar a numeração e a fila. O documento guarda `PL_010h_v1.03` no snapshot para validar o XML processado quando o autorizador retornar. Consultas a documentos anteriores utilizam o pacote já registrado. O assinador e a verificação de integridade e de correspondência com o protocolo continuam no fluxo habitual.

## Composição e origem

| Arquivo | Origem oficial |
| --- | --- |
| `nfe_v4.00.xsd`, `leiauteNFe_v4.00.xsd`, `tiposBasico_v4.00.xsd`, `DFeTiposBasicos_v1.00.xsd` e `xmldsig-core-schema_v1.01.xsd` | Pasta `PL_010h_v1.03/NFe/` do pacote publicado em 13/11/2026. ZIP de 52.038 bytes, SHA-256 `bcbf6b277f79a6983d69016dfe0430f09b8a191997a4706a24938acddc18dc81`. [Portal Nacional da NF-e](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=YhWikFoTxUo%3D) |
| `procNFe_v4.00.xsd` | O pacote 010h é incremental. O arquivo principal do documento processado vem do pacote oficial `PL_009g_V4_00_NT_2020_005_v120`. Ele declara `nfeProc` do tipo `TNfeProc` e inclui o arquivo `leiauteNFe_v4.00.xsd` do mesmo diretório, que define integralmente o protocolo e a nota na versão 010h. SHA-256 do ZIP de origem `2a16fb8250b7453810391f15ebeb41122e91ca13a4a2ae6ce2d5e28312b3d17e`. [SVRS](https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=2&nomeArquivo=PL_009g_V4_00_NT_2020_005_v120.zip) |

Os seis XSD ocupam 461.816 bytes. Os arquivos conservam o conteúdo original, inclusive os cabeçalhos e a identificação do Projeto NF-e e da PROCERGS, onde presentes. Os dois ZIPs oficiais consultados não contêm arquivo de licença ou declaração de licença específica para os XSD. As cópias locais são identificadas por `PL_010h_v1.03/SHA256SUMS`.

Na raiz deste diretório, a conferência dos seis arquivos é feita com

```sh
(cd PL_010h_v1.03 && sha256sum -c SHA256SUMS)
```
