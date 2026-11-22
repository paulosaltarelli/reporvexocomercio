<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Private tenant artwork, with optional original sprite sheets for seeded catalogues. */
final class ProductImages
{
    private array $maps = [];

    public function relativePath(int $tenantId, int $productId): string
    {
        if ($tenantId < 1 || $productId < 1) throw new \InvalidArgumentException('Produto ou empresa inválidos.');
        return 'tenants/'.$tenantId.'/catalog/products/'.$productId.'.png';
    }

    private function directory(int $tenantId, bool $create = false): string
    {
        if ($tenantId < 1) throw new \InvalidArgumentException('Empresa inválida.');
        $relative = 'tenants/'.$tenantId.'/catalog/products';
        $path = Storage::disk('local')->path($relative);
        if ($create && !is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new \RuntimeException('Não foi possível preparar a pasta das imagens.');
        if (is_dir($path)) {
            $root = realpath(Storage::disk('local')->path(''));
            if ($root === false || realpath($path) !== $root.'/'.$relative || is_link($path)) throw new \RuntimeException('Pasta de imagens inválida.');
        }
        return $path;
    }

    private function mapping(int $tenantId): array
    {
        if (isset($this->maps[$tenantId])) return $this->maps[$tenantId];
        $path = $this->directory($tenantId).'/images.json';
        if (!is_file($path)) return $this->maps[$tenantId] = [];
        if (is_link($path)) throw new \RuntimeException('Mapa de imagens inválido.');
        $map = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($map)) throw new \RuntimeException('Mapa de imagens inválido.');
        foreach ($map as $id => $tile) {
            if (!ctype_digit((string)$id) || (int)$id < 1 || !is_array($tile)
                || !is_string($tile['sheet'] ?? null) || !preg_match('/^[a-f0-9]{64}\.png$/D', $tile['sheet'])
                || !is_int($tile['columns'] ?? null) || !is_int($tile['rows'] ?? null)
                || $tile['columns'] < 1 || $tile['columns'] > 12 || $tile['rows'] < 1 || $tile['rows'] > 12
                || !is_int($tile['index'] ?? null) || $tile['index'] < 0 || $tile['index'] >= $tile['columns'] * $tile['rows']
                || !is_int($tile['width'] ?? null) || !is_int($tile['height'] ?? null)
                || $tile['width'] < 1 || $tile['height'] < 1 || $tile['width'] > 8192 || $tile['height'] > 8192) {
                throw new \RuntimeException('Mapa de imagens inválido.');
            }
        }
        return $this->maps[$tenantId] = $map;
    }

    public function file(int $tenantId, int $productId): ?string
    {
        $this->relativePath($tenantId, $productId);
        $directory = $this->directory($tenantId);
        $single = $directory.'/'.$productId.'.png';
        clearstatcache(true, $single);
        if (is_link($single)) throw new \RuntimeException('Imagem inválida.');
        if (is_file($single)) return $single;
        $tile = $this->mapping($tenantId)[$productId] ?? null;
        if (!$tile) return null;
        $sheet = $directory.'/'.$tile['sheet'];
        if (is_link($sheet)) throw new \RuntimeException('Imagem inválida.');
        return is_file($sheet) ? $sheet : null;
    }

    public function exists(int $tenantId, int $productId): bool
    {
        return $this->file($tenantId, $productId) !== null;
    }

    public function presentation(string $company, int $tenantId, int $productId): ?array
    {
        try { $file = $this->file($tenantId, $productId); }
        catch (\RuntimeException|\JsonException $error) {
            Log::warning('Product artwork unavailable.', ['tenant_id'=>$tenantId]);
            return null;
        }
        if ($file === null) return null;
        $single = basename($file) === $productId.'.png';
        $tile = $single ? null : $this->mapping($tenantId)[$productId];
        $version = $single ? hash_file('sha256', $file) : $tile['sheet'].'-'.$tile['index'];
        $result = ['url'=>$single ? route('product.image', ['company'=>$company, 'id'=>$productId, 'v'=>$version]) : route('product.image.sheet', ['company'=>$company, 'digest'=>substr($tile['sheet'], 0, 64)]), 'sheet'=>!$single];
        if ($tile) {
            $column = $tile['index'] % $tile['columns'];
            $row = intdiv($tile['index'], $tile['columns']);
            $result += ['size_x'=>$tile['columns'] * 100, 'size_y'=>$tile['rows'] * 100,
                'position_x'=>$tile['columns'] === 1 ? 0 : 100 * $column / ($tile['columns'] - 1),
                'position_y'=>$tile['rows'] === 1 ? 0 : 100 * $row / ($tile['rows'] - 1),
                'aspect_width'=>$tile['width'] * $tile['rows'], 'aspect_height'=>$tile['height'] * $tile['columns']];
        }
        return $result;
    }

    /** A public image must contain only the selected product, even on seeded sprite sheets. */
    public function catalogPhoto(int $tenantId, int $productId): ?string
    {
        $source = $this->file($tenantId, $productId);
        if (!$source || basename($source) === $productId.'.png') return $source;
        $tile = $this->mapping($tenantId)[$productId];
        $key = hash('sha256', json_encode($tile, JSON_THROW_ON_ERROR));
        $output = $this->directory($tenantId).'/thumb-'.$key.'.png';
        if (is_link($output)) throw new \RuntimeException('Imagem de catálogo inválida.');
        if (is_file($output)) return $output;
        $sheet = @imagecreatefrompng($source);
        if (!$sheet) return null;
        $column = $tile['index'] % $tile['columns'];
        $row = intdiv($tile['index'], $tile['columns']);
        $left = intdiv(imagesx($sheet) * $column, $tile['columns']);
        $top = intdiv(imagesy($sheet) * $row, $tile['rows']);
        $width = intdiv(imagesx($sheet) * ($column + 1), $tile['columns']) - $left;
        $height = intdiv(imagesy($sheet) * ($row + 1), $tile['rows']) - $top;
        if ($width < 1 || $height < 1) return null;
        $scale = min(1, 480 / max($width, $height));
        $photo = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagealphablending($photo, false);
        imagesavealpha($photo, true);
        imagecopyresampled($photo, $sheet, 0, 0, $left, $top, imagesx($photo), imagesy($photo), $width, $height);
        ob_start();
        try {
            if (!imagepng($photo)) return null;
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->atomicWrite($output, $contents);
        return $output;
    }

    public function sheetProductIds(int $tenantId, string $digest): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) return [];
        return array_keys(array_filter($this->mapping($tenantId), fn($tile)=>$tile['sheet'] === $digest.'.png'));
    }

    public function sheetFile(int $tenantId, string $digest): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) return null;
        $filename = $digest.'.png';
        if (!$this->sheetProductIds($tenantId, $digest)) return null;
        $path = $this->directory($tenantId).'/'.$filename;
        return is_file($path) && !is_link($path) ? $path : null;
    }

    private function atomicWrite(string $path, string $contents): void
    {
        if (is_link($path)) throw new \RuntimeException('Imagem inválida.');
        $temporary = tempnam(dirname($path), '.product-');
        if ($temporary === false) throw new \RuntimeException('Não foi possível preparar a imagem.');
        try {
            if (!chmod($temporary, 0600) || file_put_contents($temporary, $contents) !== strlen($contents) || !rename($temporary, $path)) throw new \RuntimeException('Não foi possível salvar a imagem do produto.');
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }

    private function updateMapping(int $tenantId, callable $change, ?callable $rollback = null): void
    {
        $directory = $this->directory($tenantId, true);
        $lockPath = $directory.'/images.lock';
        if (is_link($lockPath)) throw new \RuntimeException('Mapa de imagens inválido.');
        $previousMask = umask(0077);
        try { $lock = fopen($lockPath, 'c'); } finally { umask($previousMask); }
        if (!$lock) throw new \RuntimeException('Não foi possível atualizar o mapa de imagens.');
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Não foi possível atualizar o mapa de imagens.');
            unset($this->maps[$tenantId]);
            try { $previous = $this->mapping($tenantId); }
            catch (\RuntimeException|\JsonException $error) {
                Log::warning('Product artwork map cannot be updated.', ['tenant_id'=>$tenantId]);
                throw ValidationException::withMessages(['product_image'=>'As imagens do catálogo precisam de revisão pelo suporte antes de uma atualização.']);
            }
            $map = $change($previous);
            try {
                $this->atomicWrite($directory.'/images.json', json_encode($map, JSON_THROW_ON_ERROR));
            } catch (\Throwable $error) {
                if ($rollback) $rollback();
                $this->maps[$tenantId] = $previous;
                throw $error;
            }
            $this->maps[$tenantId] = $map;
        } finally {
            fclose($lock);
        }
    }

    public function store(int $tenantId, int $productId, UploadedFile $file): void
    {
        $this->relativePath($tenantId, $productId);
        $image = @imagecreatefromstring($file->get());
        if (!$image) throw ValidationException::withMessages(['product_image'=>'Não foi possível ler a imagem do produto.']);
        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, 800 / $width, 800 / $height);
            $target = imagecreatetruecolor(max(1, (int)round($width * $scale)), max(1, (int)round($height * $scale)));
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
            imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
            ob_start();
            $encoded = imagepng($target);
            $contents = ob_get_clean();
            imagedestroy($target);
        } finally {
            imagedestroy($image);
        }
        if (!$encoded || !is_string($contents)) throw ValidationException::withMessages(['product_image'=>'Não foi possível preparar a imagem do produto.']);
        $directory = $this->directory($tenantId, true);
        $path = $directory.'/'.$productId.'.png';
        $previous = null;
        $this->updateMapping($tenantId, function (array $map) use ($path, $productId, $contents, &$previous) {
            if (is_link($path)) throw new \RuntimeException('Imagem inválida.');
            $previous = is_file($path) ? file_get_contents($path) : null;
            if ($previous === false) throw new \RuntimeException('Não foi possível ler a imagem atual.');
            $this->atomicWrite($path, $contents);
            unset($map[$productId]);
            return $map;
        }, function () use ($path, &$previous) {
            if ($previous !== null) $this->atomicWrite($path, $previous);
            elseif (is_file($path)) unlink($path);
        });
    }

    /** Private administrative seeding API. The original PNG sheet is stored without cropping or resizing. */
    public function storeSheet(int $tenantId, array $productIdToIndex, UploadedFile $file, int $columns, int $rows): void
    {
        $contents = $file->get();
        $size = @getimagesizefromstring($contents);
        if (!$size || ($size['mime'] ?? '') !== 'image/png' || strlen($contents) > 32 * 1024 * 1024
            || $size[0] > 8192 || $size[1] > 8192 || $columns < 1 || $columns > 12 || $rows < 1 || $rows > 12) {
            throw new \InvalidArgumentException('A folha de imagens precisa ser um PNG de até 32 MB e 8192 pixels.');
        }
        foreach ($productIdToIndex as $id=>$index) {
            if (!ctype_digit((string)$id)) throw new \InvalidArgumentException('Produto inválido.');
            $this->relativePath($tenantId, (int)$id);
            if (!is_int($index) || $index < 0 || $index >= $columns * $rows) throw new \InvalidArgumentException('Posição da imagem inválida.');
        }
        $sheet = hash('sha256', $contents).'.png';
        $this->updateMapping($tenantId, function (array $map) use ($tenantId, $productIdToIndex, $contents, $sheet, $columns, $rows, $size) {
            $this->atomicWrite($this->directory($tenantId).'/'.$sheet, $contents);
            foreach ($productIdToIndex as $id=>$index) {
                $map[$id] = ['sheet'=>$sheet, 'index'=>$index, 'columns'=>$columns, 'rows'=>$rows, 'width'=>$size[0], 'height'=>$size[1]];
            }
            return $map;
        });
    }

    public function remove(int $tenantId, int $productId): void
    {
        $this->relativePath($tenantId, $productId);
        $previous = null;
        $path = $this->directory($tenantId, true).'/'.$productId.'.png';
        $this->updateMapping($tenantId, function (array $map) use ($path, $productId, &$previous) {
            $previous = is_file($path) && !is_link($path) ? file_get_contents($path) : null;
            if ($previous === false) throw new \RuntimeException('Não foi possível ler a imagem atual.');
            if (is_link($path)) throw new \RuntimeException('Imagem inválida.');
            if (is_file($path) && !unlink($path)) throw new \RuntimeException('Não foi possível remover a imagem do produto.');
            unset($map[$productId]);
            return $map;
        }, function () use ($path, &$previous) {
            if ($previous !== null) $this->atomicWrite($path, $previous);
        });
    }
}
