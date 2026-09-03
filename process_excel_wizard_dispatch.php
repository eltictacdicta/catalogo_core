<?php
/**
 * Shared dispatch for catalogo_core Excel import wizard (standalone + embedded).
 */
declare(strict_types=1);

function catalogo_excel_wizard_bootstrap(): void
{
    chdir(FS_FOLDER);

    // Legacy core files use relative paths; CWD must be project root.
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    if (PHP_SAPI !== 'cli') {
        ini_set('session.cookie_path', '/');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $configFile = FS_FOLDER . '/config.php';
    if (file_exists($configFile)) {
        require_once $configFile;
    }
    $autoloadFile = FS_FOLDER . '/vendor/autoload.php';
    if (file_exists($autoloadFile)) {
        require_once $autoloadFile;
    }

    require_once FS_FOLDER . '/base/fs_secret_migrator.php';
    fs_secret_migrator::ensure();
    require_once FS_FOLDER . '/base/config2.php';
    require_once FS_FOLDER . '/base/fs_core_log.php';
    require_once FS_FOLDER . '/base/fs_db2.php';
    require_once FS_FOLDER . '/base/fs_model.php';
    require_once FS_FOLDER . '/base/fs_model_autoloader.php';
    \FSFramework\Core\Kernel::boot();
    fs_model_autoloader::register();

    @set_time_limit(300);

    try {
        \FSFramework\Security\SessionManager::getInstance();
    } catch (\Throwable $e) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'process_excel_wizard.php cannot run in CLI: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => 'Session bootstrap failed: ' . $e->getMessage()]);
        exit;
    }
}

function catalogo_excel_wizard_run(bool $embedded): void
{
    $action = catalogo_excel_wizard_resolve_action($embedded);

    if (!$embedded) {
        if ($action === '') {
            http_response_code(400);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Missing action parameter. Expected: start, progress, status.']);
            exit;
        }

        if ($action === 'start' && !catalogo_excel_wizard_validate_csrf()) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode(['success' => false, 'error' => 'CSRF token missing or invalid.']);
            exit;
        }

        if (!catalogo_excel_wizard_require_login()) {
            if (!headers_sent()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode(['success' => false, 'error' => 'No authenticated session. Please log in again.']);
            exit;
        }

        // Access policy gate (R-CEXC-001/004): applies to ALL standalone
        // actions (start, progress, status) after login, before wizard
        // handling. Closes the direct-URL hole with an explicit 403 JSON.
        $policyDenial = catalogo_excel_wizard_policy_denial();
        if ($policyDenial !== null) {
            catalogo_excel_wizard_emit_denial($policyDenial);
        }
    }

    @set_time_limit(300);

    $sessionId = session_id() ?: bin2hex(random_bytes(16));

    if ($action === 'start') {
        $ctx = \FSFramework\Core\ProgressStream::init('fs_catalogo_excel_wizard', $sessionId);
        $progressFile = $ctx['progress_file'];
    } else {
        $progressFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'fs_catalogo_excel_wizard_'
            . preg_replace('/[^A-Za-z0-9_.-]/', '', $sessionId)
            . '.json';
    }

    switch ($action) {
        case 'start':
            handleCatalogoStart($progressFile);
            break;
        case 'progress':
            handleCatalogoProgress($progressFile);
            break;
        case 'status':
            handleCatalogoStatus($progressFile);
            break;
        default:
            \FSFramework\Core\ProgressStream::sendEvent('error', [
                'message' => 'Unknown action: ' . $action,
                'percent' => 0,
            ]);
            break;
    }
    exit;
}

function catalogo_excel_wizard_resolve_action(bool $embedded): string
{
    if ($embedded) {
        return (string) ($_GET['wizard_action'] ?? 'start');
    }

    return (string) ($_GET['action'] ?? '');
}

function catalogo_excel_wizard_validate_csrf(): bool
{
    $csrfToken = (string) ($_GET['_csrf_token'] ?? '');
    if ($csrfToken === '' || !class_exists('\FSFramework\Security\CsrfManager')) {
        return false;
    }

    try {
        return \FSFramework\Security\CsrfManager::isValid($csrfToken);
    } catch (\Throwable $e) {
        return false;
    }
}

function catalogo_excel_wizard_require_login(): bool
{
    try {
        return \FSFramework\Security\SessionManager::getInstance()->isLoggedIn();
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Central access-policy verdict for the Excel wizard (R-CEXC-001/004).
 *
 * @param \FSFramework\Plugins\catalogo_core\Services\ArticleExcelAccessPolicy|null $policy
 *        Injectable for tests; production resolves the session user.
 *
 * @return array|null null = allowed | ['success' => false, 'error' => ...] denial payload
 */
function catalogo_excel_wizard_policy_denial(?\FSFramework\Plugins\catalogo_core\Services\ArticleExcelAccessPolicy $policy = null): ?array
{
    $policy = $policy ?? new \FSFramework\Plugins\catalogo_core\Services\ArticleExcelAccessPolicy();
    if ($policy->isAllowed()) {
        return null;
    }

    return [
        'success' => false,
        'error' => $policy->denialMessage(),
    ];
}

/**
 * SSE per-event policy re-check (R-CEXC-005, AD-4).
 *
 * A verdict flip to denied throws CatalogoExcelAccessDeniedException, which
 * the existing catch in handleCatalogoStart() turns into the SSE 'error'
 * event and ends the stream. Each call evaluates a FRESH policy instance, so
 * DB-backed revocations (role/user changes) are detected mid-stream; the
 * per-instance memoization in AD-3 only spans a single evaluation.
 *
 * @param \FSFramework\Plugins\catalogo_core\Services\ArticleExcelAccessPolicy|null $policy
 *        Injectable for tests; production resolves the session user.
 *
 * @throws \FSFramework\Plugins\catalogo_core\Services\CatalogoExcelAccessDeniedException
 */
function catalogo_excel_wizard_assert_policy(?\FSFramework\Plugins\catalogo_core\Services\ArticleExcelAccessPolicy $policy = null): void
{
    $denial = catalogo_excel_wizard_policy_denial($policy);
    if ($denial !== null) {
        throw new \FSFramework\Plugins\catalogo_core\Services\CatalogoExcelAccessDeniedException((string) $denial['error']);
    }
}

/**
 * Emits the standalone denied response (403 JSON) and terminates before any
 * wizard handling. Shape matches what the wizard client already parses
 * (json.error); mirrors the existing CSRF denial response.
 */
function catalogo_excel_wizard_emit_denial(array $payload): void
{
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload);
    exit;
}

function handleCatalogoStart(string $progressFile): void
{
    $token = (string) ($_GET['token'] ?? '');
    $sheet = (string) ($_GET['sheet'] ?? '');
    $defaultAction = (string) ($_GET['default_action'] ?? 'create_if_missing');
    $mappingRaw = $_GET['mapping'] ?? [];
    $roundPrice = filter_var($_GET['round_price'] ?? '0', FILTER_VALIDATE_BOOLEAN);
    $defaultCodimpuesto = trim((string) ($_GET['default_codimpuesto'] ?? \FSFramework\Plugins\catalogo_core\Services\ArticuloExcelImportWizardService::DEFAULT_CODIMPUESTO));
    if ($defaultCodimpuesto === '' || !preg_match('/^[A-Za-z0-9._-]{1,10}$/', $defaultCodimpuesto)) {
        $defaultCodimpuesto = \FSFramework\Plugins\catalogo_core\Services\ArticuloExcelImportWizardService::DEFAULT_CODIMPUESTO;
    }

    if (!in_array($defaultAction, ['create_if_missing', 'update_only'], true)) {
        $defaultAction = 'create_if_missing';
    }

    if ($token === '' || $sheet === '') {
        \FSFramework\Core\ProgressStream::sendEvent('error', [
            'message' => 'Faltan parámetros requeridos (token, sheet).',
            'percent' => 0,
        ]);
        return;
    }

    if (!preg_match('/^[A-Fa-f0-9]{8,64}$/', $token)) {
        \FSFramework\Core\ProgressStream::sendEvent('error', [
            'message' => 'Token inválido.',
            'percent' => 0,
        ]);
        return;
    }

    $filePath = rtrim(FS_FOLDER, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'tmp' . DIRECTORY_SEPARATOR
        . 'catalogo_excel_wizard_' . $token . '.xlsx';
    if (!is_file($filePath)) {
        \FSFramework\Core\ProgressStream::sendEvent('error', [
            'message' => 'El archivo del wizard expiró o no existe. Vuelve a subirlo.',
            'percent' => 0,
        ]);
        return;
    }

    $mapping = is_array($mappingRaw) ? $mappingRaw : [];
    foreach ($mapping as $k => $v) {
        $mapping[$k] = is_string($v) ? $v : '';
    }

    $hasNonIgnore = false;
    foreach ($mapping as $fieldName) {
        if ($fieldName !== '__ignorar__' && $fieldName !== '') {
            $hasNonIgnore = true;
            break;
        }
    }
    if (!$hasNonIgnore) {
        \FSFramework\Core\ProgressStream::sendEvent('error', [
            'message' => 'Mapea al menos una columna para continuar.',
            'percent' => 0,
        ]);
        return;
    }

    $stamp = 'catalogo_import_' . date('Ymd_His');
    $tmpDir = rtrim(FS_FOLDER, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0700, true);
    }
    $descartadasPath = $tmpDir . $stamp . '_descartadas.csv';
    $hDesc = fopen($descartadasPath, 'w');
    if ($hDesc === false) {
        \FSFramework\Core\ProgressStream::sendEvent('error', [
            'message' => 'No se pudo crear el CSV de auditoría.',
            'percent' => 0,
        ]);
        return;
    }
    @chmod($descartadasPath, 0600);
    fwrite($hDesc, "\xEF\xBB\xBF");
    catalogoFputcsvSafe($hDesc, ['motivo_descarte', 'referencia', 'detalle'], ';');

    $stats = [
        'creados' => 0,
        'actualizados' => 0,
        'sin_cambios' => 0,
        'no_encontrados' => 0,
        'errores' => 0,
        'descartadas' => 0,
        'detalles_errores' => [],
    ];

    $lastEventTime = time();
    $articuloModel = new \FSFramework\model\articulo();

    // Per-run policy context for the row gate (AD-3: one instance per import
    // run; the session nick feeds the event payload).
    $rowPolicy = new \FSFramework\Plugins\catalogo_core\Services\ArticleExcelAccessPolicy();
    $rowNick = '';
    try {
        $rowNick = (string) (\FSFramework\Security\SessionManager::getInstance()->getCurrentUserNick() ?? '');
    } catch (\Throwable) {
        $rowNick = '';
    }
    $rowIsAdmin = $rowPolicy->isAdmin();

    $progressCallback = static function (string $step, string $message, int $percent) use ($progressFile, &$lastEventTime) {
        // SSE per-event policy re-check (R-CEXC-005): a mid-stream revocation
        // flips the verdict; the throw is caught by the existing catch below,
        // which emits the 'error' event and ends the stream.
        catalogo_excel_wizard_assert_policy();

        if (time() - $lastEventTime > 10) {
            \FSFramework\Core\ProgressStream::sendKeepalive(false);
        }
        $data = \FSFramework\Core\ProgressStream::saveProgress($progressFile, $step, $message, $percent);
        \FSFramework\Core\ProgressStream::sendEvent('progress', $data, false);
        $lastEventTime = time();
        usleep(10000);
    };

    $rowHook = static function (array $mappedRow, int $rowIdx) use (
        &$stats,
        $hDesc,
        $defaultAction,
        $articuloModel,
        $roundPrice,
        $defaultCodimpuesto,
        $rowNick,
        $rowIsAdmin
    ): void {
        $referencia = trim((string) ($mappedRow['referencia'] ?? ''));
        $rowSig = ['motivo_descarte' => '', 'referencia' => $referencia, 'detalle' => ''];

        // Per-row permission gate (R-CEXC-003): fires BEFORE every create/update
        // branch so a denial makes any pvp mutation and save() unreachable
        // (scenario 19). Admin rows skip the dispatch entirely (AD-5); only
        // pvp-mapped non-admin rows dispatch the frozen ACTION_EDIT_ARTICLE
        // (AD-2). Denied rows are skipped and reported (scenario 18).
        $denialReason = \FSFramework\Plugins\catalogo_core\Services\ArticuloExcelPermissionGate::check(
            $mappedRow,
            $referencia,
            $rowNick,
            $rowIsAdmin
        );
        if ($denialReason !== null) {
            catalogo_excel_wizard_deny_row($hDesc, $referencia, $denialReason, $stats);
            return;
        }

        if ($defaultAction === 'create_if_missing') {
            if ($referencia !== '') {
                $existing = $articuloModel->get($referencia);
                if ($existing) {
                    catalogoApplyWizardFields($mappedRow, $existing, $stats, $rowIdx, true, $roundPrice);
                    return;
                }
            }

            if (trim((string) ($mappedRow['descripcion'] ?? '')) === '') {
                $rowSig['motivo_descarte'] = 'descripcion_obligatoria';
                catalogoFputcsvSafe($hDesc, array_values($rowSig), ';');
                $stats['descartadas']++;
                $stats['errores']++;
                return;
            }

            $newArt = \FSFramework\Plugins\catalogo_core\Services\ArticuloExcelImportWizardService::createArticuloFromRow(
                $mappedRow,
                $roundPrice,
                $defaultCodimpuesto
            );
            if ($referencia !== '' && $articuloModel->get($newArt->referencia)) {
                $rowSig['motivo_descarte'] = 'referencia_duplicada';
                catalogoFputcsvSafe($hDesc, array_values($rowSig), ';');
                $stats['descartadas']++;
                $stats['errores']++;
                return;
            }

            if ($newArt->save()) {
                $stats['creados']++;
            } else {
                $rowSig['motivo_descarte'] = 'create_failed';
                catalogoFputcsvSafe($hDesc, array_values($rowSig), ';');
                $stats['descartadas']++;
                $stats['errores']++;
            }

            return;
        }

        if ($referencia === '') {
            $rowSig['motivo_descarte'] = 'referencia_ausente';
            catalogoFputcsvSafe($hDesc, array_values($rowSig), ';');
            $stats['descartadas']++;
            $stats['no_encontrados']++;
            return;
        }

        $art = $articuloModel->get($referencia);
        if (!$art) {
            $rowSig['motivo_descarte'] = 'no_encontrado';
            catalogoFputcsvSafe($hDesc, array_values($rowSig), ';');
            $stats['descartadas']++;
            $stats['no_encontrados']++;
            return;
        }

        catalogoApplyWizardFields($mappedRow, $art, $stats, $rowIdx, false, $roundPrice);
    };

    \FSFramework\Core\ProgressStream::sendEvent('start', [
        'message' => 'Importación iniciada...',
        'percent' => 0,
    ]);
    \FSFramework\Core\ProgressStream::saveProgress($progressFile, 'init', 'Iniciando importación...', 0);

    try {
        $service = new \FSFramework\Plugins\catalogo_core\Services\ArticuloExcelImportWizardService();
        $service->apply($filePath, $sheet, $mapping, $rowHook, $progressCallback);

        $cacheKeys = \FSFramework\Plugins\catalogo_core\Services\ArticuloExcelImportWizardService::CACHE_KEYS_TO_INVALIDATE;
        try {
            \FSFramework\Cache\CacheManager::getInstance()->deleteMultiple($cacheKeys);
        } catch (\Throwable $e) {
            // best-effort
        }

        @unlink($filePath);

        // Final re-check before 'complete' (R-CEXC-005, design flowchart b):
        // the stream must not report success after a mid-run revocation.
        catalogo_excel_wizard_assert_policy();

        \FSFramework\Core\ProgressStream::saveProgress($progressFile, 'complete', 'Importación completada.', 100);
        \FSFramework\Core\ProgressStream::sendEvent('complete', [
            'message' => 'Importación completada.',
            'percent' => 100,
            'stats' => $stats,
            'csv_urls' => [
                'descartadas_url' => 'index.php?page=ventas_articulos&action=download_import_log&file=' . basename($descartadasPath),
            ],
        ]);
    } catch (\Throwable $e) {
        $errorMsg = 'Excepción durante la importación: ' . $e->getMessage();
        \FSFramework\Core\ProgressStream::saveProgress($progressFile, 'error', $errorMsg, 0, $errorMsg);
        \FSFramework\Core\ProgressStream::sendEvent('error', [
            'message' => $errorMsg,
            'percent' => 0,
        ]);
    } finally {
        if (is_resource($hDesc)) {
            fclose($hDesc);
        }
        \FSFramework\Core\ProgressStream::cleanup($progressFile);
    }
}

function catalogoApplyWizardFields(
    array $mappedRow,
    \FSFramework\model\articulo $art,
    array &$stats,
    int $rowIdx,
    bool $isCreatePathUpdate,
    bool $roundPrice = false
): void {
    unset($rowIdx, $isCreatePathUpdate);
    $updateRow = $mappedRow;
    unset($updateRow['referencia']);

    $changed = \FSFramework\Plugins\catalogo_core\Services\ArticuloExcelRowUpdater::applyMappedFields($updateRow, $art, $roundPrice);
    if ($changed && $art->save()) {
        $stats['actualizados']++;
    } elseif ($changed) {
        $stats['errores']++;
    } else {
        $stats['sin_cambios']++;
    }
}

function handleCatalogoProgress(string $progressFile): void
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $isEventSource = strpos($accept, 'text/event-stream') !== false;

    if (!$isEventSource) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode([
            'success' => true,
            'progress' => \FSFramework\Core\ProgressStream::readProgress($progressFile),
        ]);
        return;
    }

    \FSFramework\Core\ProgressStream::sendEvent('progress', [
        'data' => \FSFramework\Core\ProgressStream::readProgress($progressFile),
    ]);
}

function handleCatalogoStatus(string $progressFile): void
{
    $data = \FSFramework\Core\ProgressStream::readProgress($progressFile);
    \FSFramework\Core\ProgressStream::sendEvent('status', [
        'active' => $data !== null && (int) ($data['percent'] ?? 0) < 100,
        'data' => $data,
    ]);
}

/**
 * Records a permission-denied row into the existing discarded-rows CSV
 * (AD-8: motivo_descarte;referencia;detalle — same file served by the
 * policy-gated download_import_log) and bumps the import stats. The import
 * loop continues with the remaining rows.
 *
 * @param resource $handle
 * @param array<string,int|array<int,string>> $stats
 */
function catalogo_excel_wizard_deny_row($handle, string $referencia, string $reason, array &$stats): void
{
    catalogoFputcsvSafe($handle, ['permiso_denegado', $referencia, $reason], ';');
    $stats['descartadas']++;
    $stats['errores']++;
}

/**
 * @param resource $handle
 * @param array<int,string> $row
 */
function catalogoFputcsvSafe($handle, array $row, string $delimiter = ';'): int|false
{
    $sanitized = array_map(static function ($cell) {
        $cell = (string) $cell;
        if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $cell;
        }
        return $cell;
    }, $row);

    return fputcsv($handle, $sanitized, $delimiter);
}
