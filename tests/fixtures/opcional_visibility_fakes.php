<?php
/**
 * Shared DB-free doubles for the D12 opcional-visibility tests (CAR-12).
 *
 * The plugin suite runs with `processIsolation="true"`, so the doubles cannot
 * live in one test file and be borrowed by another; they are declared here and
 * explicitly required by each test file.
 *
 * Namespace: Tests\CatalogoCore (same as the consuming tests).
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

/**
 * Spy `db()` for the D12 parent discovery. Answers the four parent queries
 * from in-memory maps, filters them by the SQL `IN (...)` list (so the fake is
 * faithful to the batched contract) and records every statement so a test can
 * assert the query count.
 */
final class OpcionalVisibilitySpyDb
{
    /** @var list<string> */
    public array $selectStatements = [];

    /**
     * @param array<int, list<string>> $articles      id_opcional => referencias
     * @param array<int, int>          $groups        id_opcional => id_grupo
     * @param array<int, list<string>> $groupArticles id_grupo => referencias
     * @param array<int, list<string>> $families      id_opcional => codfamilias
     */
    public function __construct(
        public array $articles = [],
        public array $groups = [],
        public array $groupArticles = [],
        public array $families = []
    ) {
    }

    public function var2str($val): string
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? '1' : '0';
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function escape_string(string $str): string
    {
        return addslashes($str);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function select($sql, $params = []): array
    {
        $this->selectStatements[] = trim((string) $sql);
        $ids = $this->inInts((string) $sql);

        if (str_contains((string) $sql, 'FROM catalogo_articulo_opcional_grupo')) {
            $rows = [];
            foreach ($this->groupArticles as $grupo => $refs) {
                if (!in_array((int) $grupo, $ids, true)) {
                    continue;
                }
                foreach ($refs as $referencia) {
                    $rows[] = ['id_grupo' => (int) $grupo, 'referencia' => $referencia];
                }
            }

            return $rows;
        }

        if (str_contains((string) $sql, 'FROM catalogo_articulo_opcional')) {
            $rows = [];
            foreach ($this->articles as $id => $refs) {
                if (!in_array((int) $id, $ids, true)) {
                    continue;
                }
                foreach ($refs as $referencia) {
                    $rows[] = ['id_opcional' => (int) $id, 'referencia' => $referencia];
                }
            }

            return $rows;
        }

        if (str_contains((string) $sql, 'FROM catalogo_opcional_familias')) {
            $rows = [];
            foreach ($this->families as $id => $codfamilias) {
                if (!in_array((int) $id, $ids, true)) {
                    continue;
                }
                foreach ($codfamilias as $codfamilia) {
                    $rows[] = ['id_opcional' => (int) $id, 'codfamilia' => $codfamilia];
                }
            }

            return $rows;
        }

        if (str_contains((string) $sql, 'FROM catalogo_opcionales')) {
            $rows = [];
            foreach ($this->groups as $id => $grupo) {
                if (!in_array((int) $id, $ids, true)) {
                    continue;
                }
                $rows[] = ['id' => (int) $id, 'id_grupo' => (int) $grupo];
            }

            return $rows;
        }

        return [];
    }

    /**
     * Extracts the integer ids of the first `IN (...)` list of the SQL.
     *
     * @return list<int>
     */
    private function inInts(string $sql): array
    {
        if (!preg_match('/IN\s*\(([^)]*)\)/i', $sql, $matches)) {
            return [];
        }

        $ids = [];
        foreach (explode(',', $matches[1]) as $part) {
            $value = trim($part, " \t\n\r\0\x0B'\"");
            if ($value !== '' && ctype_digit($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}

final class OpcionalVisibilityFakeDefinitionModel
{
    /** @param array<int, array<string, mixed>> $definitions */
    public function __construct(private array $definitions)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(bool $onlyActive = false): array
    {
        if (!$onlyActive) {
            return $this->definitions;
        }

        return array_values(array_filter(
            $this->definitions,
            static fn (array $def): bool => (bool) ($def['activo'] ?? true)
        ));
    }
}

final class OpcionalVisibilityFakeScopeModel
{
    public function __construct(private array &$rows, private string $scope)
    {
    }

    public function get($codtarifa, array $key = [], $idCaracteristica = null)
    {
        if ($this->scope === 'global') {
            return $this->rows['global'][(string) $codtarifa][(int) $idCaracteristica] ?? false;
        }

        $keyColumn = $this->scope === 'familia' ? 'codfamilia' : 'referencia';
        $keyValue = (string) ($key[$keyColumn] ?? '');

        return $this->rows[$this->scope][(string) $codtarifa][$keyValue][(int) $idCaracteristica] ?? false;
    }
}
