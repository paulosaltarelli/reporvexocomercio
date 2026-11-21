# Imagens privadas do catálogo

O cadastro existente permite enviar PNG, JPEG ou WebP de até 2 MB, entre 48 e 2048 pixels. A imagem é normalizada para PNG de até 800 pixels, preservando transparência. Não há novas colunas nem migrações. O armazenamento fica no disco local privado, separado por empresa e produto. Atualizações usam trava e gravação atômica. Imagens individuais têm prioridade sobre folhas ilustrativas.

A leitura exige sessão da empresa e permissão de catálogo ou painel operacional habilitado. A imagem individual verifica o produto na conexão corrente e não pode ser compartilhada por URL pública. Folhas ilustrativas usam uma URL autenticada por hash compartilhada entre os cartões da mesma empresa, com cache privado, revalidação obrigatória e ETag, evitando baixar o mesmo arquivo para cada produto. Somente hashes registrados no mapa privado da empresa são servidos.

## Importação das lojas de teste

O comando aceita exclusivamente as lojas 3 e 4, não altera produtos, preços, estoque, logo ou usuários. Os SKUs são correlacionados com a ordem `products` do JSON; todos devem existir de forma única na loja antes de gravar. A folha PNG original permanece intacta; a interface seleciona suas células por CSS.

```sh
php artisan vendra:test-shop-images RESTAURANT_SLUG /run/restaurante.json /run/restaurante.png 6 6 --confirm-test-shop
php artisan vendra:test-shop-images SUPERMARKET_SLUG /run/supermercado.json /run/supermercado.png 8 5 --confirm-test-shop
```

Execute com a identidade do processo da aplicação e arquivos de entrada somente leitura. As referências originais usadas para gerar as folhas têm 33 produtos de restaurante (as três células extras são ignoradas) e 40 produtos de supermercado, em ordem da esquerda para a direita, de cima para baixo. Não se assume que o ID do produto seja igual à posição. O comando preserva imagens individuais já enviadas pelo cliente. As imagens são ilustrativas geradas, sem garantia de embalagem ou marca oficial.

API interna: `ProductImages::storeSheet(int $tenantId, array $productIdToIndex, UploadedFile $file, int $columns, int $rows)`, com índices a partir de zero. Não há rota HTTP de importação de folhas.
