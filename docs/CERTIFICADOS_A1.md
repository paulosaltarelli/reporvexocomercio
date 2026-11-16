# Compatibilidade de certificados A1

Arquivos PKCS#12 (`.pfx`/`.p12`) modernos são lidos diretamente. Quando o OpenSSL informa algoritmo não suportado, o importador tenta abrir o arquivo em um processo PHP isolado com os provedores `default` e `legacy`, conforme a configuração exclusiva `config/openssl-pfx.cnf`.

A entrada e a saída do conversor usam pipes em memória, sem senha nos argumentos ou no ambiente, sem arquivos temporários de chave privada e sem transmissão externa. Há limite de tamanho e prazo de dez segundos. A cópia interna é reexportada em PKCS#12 compatível com o processo principal, preservando senha, certificado, chave e cadeia adicional. O certificado não é renovado ou substituído; sua impressão digital permanece igual.

NFC-e, NF-e e NFS-e normalizam o arquivo antes de persistir os segredos criptografados. O inspetor e o transporte SEFAZ também reconhecem o formato antigo ao ler credenciais existentes. Nenhum provedor legado é habilitado no processo web, no worker fiscal ou no TLS global. O PHP CLI do conversor usa `/usr/bin/php` e `/opt/reporvexo-tools/php/cli.ini`, caminhos já presentes na VPS; sua disponibilidade faz parte da conferência de publicação.

Senha incorreta, arquivo inválido, CNPJ divergente, certificado vencido e ausência de uso de assinatura continuam sujeitos às validações existentes. Não se ignora MAC, correspondência de chave privada ou identidade para aceitar arquivos antigos. Mensagens de falha não expõem a senha ou o conteúdo do PFX.

Referência técnica: https://docs.openssl.org/3.0/man1/openssl-pkcs12/ — suporte `-legacy`/provedor legado para leitura de arquivos antigos. Testes usam somente certificados sintéticos, nunca autorizados pela SEFAZ.
