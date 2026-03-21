<?php

namespace FSFramework\Plugins\catalogo_core\Model;

class CatalogoApiService
{
    /**
     * Returns public articles only.
     *
     * Supported filters (all optional):
     * - query: string
     * - codfamilia: string
     * - codfabricante: string
     * - con_stock: bool
     * - bloqueados: bool
     * - offset: int
     * - limit: int
     *
     * @param array<string, mixed> $filters
     * @param string|null $codcliente
     *
     * @return array<int, array<string, mixed>>
     */
    public function getArticulos(array $filters = [], ?string $codcliente = null): array
    {
        $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : FS_ITEM_LIMIT;

        $query = (string) ($filters['query'] ?? '');
        $codfamilia = (string) ($filters['codfamilia'] ?? '');
        $conStock = (bool) ($filters['con_stock'] ?? false);
        $codfabricante = (string) ($filters['codfabricante'] ?? '');
        $bloqueados = (bool) ($filters['bloqueados'] ?? false);

        // Nota: el modelo `articulo::search` no filtra por `publico`, por eso filtramos después.
        $artModel = new Articulo();
        $articulos = $artModel->search($query, $offset, $codfamilia, $conStock, $codfabricante, $bloqueados);
        if ($limit > 0) {
            $articulos = array_slice($articulos, 0, $limit);
        }

        $result = [];
        foreach ($articulos as $a) {
            if (empty($a->publico)) {
                continue;
            }

            $result[] = [
                'referencia' => $a->referencia,
                'descripcion' => $a->descripcion,
                'codfamilia' => $a->codfamilia,
                'codfabricante' => $a->codfabricante,
                'pvp' => (float) ($a->pvp ?? 0),
                'stockfis' => (float) ($a->stockfis ?? 0),
                'stockmin' => (float) ($a->stockmin ?? 0),
                'stockmax' => (float) ($a->stockmax ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFamilias(): array
    {
        $famModel = new Familia();
        $familias = $famModel->all();

        $result = [];
        foreach ($familias as $f) {
            $result[] = [
                'codfamilia' => $f->codfamilia,
                'descripcion' => $f->descripcion,
                'madre' => $f->madre,
                'nivel' => $f->nivel,
            ];
        }

        return $result;
    }

    /**
     * Returns raw stock lines for an article.
     *
     * @param string $referencia
     * @param string|null $codalmacen
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStock(string $referencia, ?string $codalmacen = null): array
    {
        $artModel = new Articulo();
        $articulo = $artModel->get($referencia);
        if (!$articulo) {
            return [];
        }

        $stockLines = $articulo->get_stock();
        if (empty($codalmacen)) {
            return array_values(array_map([$this, 'normalizeStockLine'], $stockLines));
        }

        // We try to filter by common field names, but fallback to returning all if structure is unknown.
        $filtered = [];
        foreach ($stockLines as $line) {
            $lineCod = null;
            if (isset($line->codalmacen)) {
                $lineCod = (string) $line->codalmacen;
            } else if (isset($line->almacen) && isset($line->almacen->codalmacen)) {
                $lineCod = (string) $line->almacen->codalmacen;
            }

            if (!empty($lineCod) && $lineCod === (string) $codalmacen) {
                $filtered[] = $line;
            }
        }

        if (empty($filtered)) {
            $filtered = $stockLines; // do not break clients if the field name differs
        }

        return array_values(array_map([$this, 'normalizeStockLine'], $filtered));
    }

    /**
     * @return array<string, mixed>
     */
    public function getPrecio(string $referencia, ?string $codcliente = null): array
    {
        $artModel = new Articulo();
        $articulo = $artModel->get($referencia);
        if (!$articulo) {
            return [];
        }

        $precioBase = (float) ($articulo->pvp ?? 0);
        $coddivisaCliente = null;

        if (!empty($codcliente)) {
            if (!class_exists('cliente')) {
                require_once FS_FOLDER . '/plugins/clientes_core/model/cliente.php';
            }

            $cli = new \cliente();
            $cliente = $cli->get($codcliente);
            if ($cliente) {
                $coddivisaCliente = $cliente->coddivisa;
            }
        }

        // For now we return base PVP converted from EUR -> client currency when possible.
        if (!empty($coddivisaCliente)) {
            if (!class_exists('fs_divisa_tools')) {
                require_once FS_FOLDER . '/plugins/catalogo_core/extras/fs_divisa_tools.php';
            }

            $divTools = new \fs_divisa_tools();
            $precioBase = (float) $divTools->euro_convert($precioBase, $coddivisaCliente);
        }

        return [
            'referencia' => $referencia,
            'precio' => $precioBase,
            'coddivisa' => $coddivisaCliente,
        ];
    }

    /**
     * @param mixed $line
     *
     * @return array<string, mixed>
     */
    private function normalizeStockLine($line): array
    {
        $codalmacen = null;
        if (isset($line->codalmacen)) {
            $codalmacen = $line->codalmacen;
        } else if (isset($line->almacen) && isset($line->almacen->codalmacen)) {
            $codalmacen = $line->almacen->codalmacen;
        }

        $stock = null;
        if (isset($line->stock)) {
            $stock = $line->stock;
        } else if (isset($line->stockfis)) {
            $stock = $line->stockfis;
        } else if (isset($line->cantidad)) {
            $stock = $line->cantidad;
        }

        return [
            'codalmacen' => $codalmacen,
            'stock' => $stock,
            'raw' => $line,
        ];
    }
}

