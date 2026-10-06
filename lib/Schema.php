<?php
namespace Bitrix\Inventar;

/**
 * Migrari idempotente de schema — ruleaza FARA reinstall (reinstall sterge tabelele!).
 * Apelat din:
 *  - install/index.php (DoInstall, doar la instalare fresh)
 *  - admin/pages/types_status.php (butonul "Check / update DB schema")
 *
 * v1.2.0:
 *  - DROP BARCODE_TEXT, QR_CODE_TEXT
 *  - ADD ASSET_UUID (unique), EXTERNAL_API, EXTERNAL_SYNC_AT
 *  - sterge stub-ul legacy /bitrix/admin/bitrix_inventar_allocations.php
 */
class Schema
{
    const VERSION = '1.2.1';

    public static function adminPages()
    {
        return [
            'dashboard' => 'bitrix_inventar_dashboard.php',
            'equipment_list' => 'bitrix_inventar_equipment_list.php',
            'equipment_edit' => 'bitrix_inventar_equipment_edit.php',
            'service' => 'bitrix_inventar_service.php',
            'import_export' => 'bitrix_inventar_import_export.php',
            'types_status' => 'bitrix_inventar_types_status.php',
            'types_info' => 'bitrix_inventar_types_info.php',
        ];
    }

    /**
     * Template stub /bitrix/admin/bitrix_inventar_*.php.
     * Modulul poate sta in /local/modules (custom, standard Bitrix) sau
     * /bitrix/modules (legacy) — stub-ul detecteaza singur locatia.
     */
    public static function buildAdminStub($pageName)
    {
        return '<?php
// Auto-generated stub - Bitrix Inventar module (do not edit manually).
// Module location: /local/modules (custom) or /bitrix/modules (legacy).
$__inventar_root = is_dir($_SERVER["DOCUMENT_ROOT"]."/local/modules/bitrix.inventar")
    ? $_SERVER["DOCUMENT_ROOT"]."/local/modules/bitrix.inventar"
    : $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/bitrix.inventar";
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");
require_once($__inventar_root."/admin/pages/' . $pageName . '.php");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
?>';
    }

    /**
     * Rescrie stub-urile admin (repara bypass-ul de login pentru API).
     * Se apeleaza din UI (buton), fara reinstall si fara pierdere de date.
     */
    public static function refreshAdminStubs()
    {
        $report = [];
        $adminDir = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin';
        if (!is_dir($adminDir) || !is_writable($adminDir)) {
            return ['ok' => false, 'report' => ['Admin dir is not writable: ' . $adminDir]];
        }
        foreach (self::adminPages() as $pageName => $fileName) {
            $stubFile = $adminDir . '/' . $fileName;
            if (@file_put_contents($stubFile, self::buildAdminStub($pageName)) !== false) {
                @chmod($stubFile, 0644);
                $report[] = 'OK: ' . $fileName;
            } else {
                $report[] = 'ERR: could not write ' . $fileName;
            }
        }
        // Sterge stub-ul legacy al paginii allocations eliminate
        $legacy = $adminDir . '/bitrix_inventar_allocations.php';
        if (file_exists($legacy)) {
            if (@unlink($legacy)) $report[] = 'OK: removed legacy bitrix_inventar_allocations.php';
            else $report[] = 'WARN: could not remove legacy bitrix_inventar_allocations.php';
        }
        return ['ok' => true, 'report' => $report];
    }

    public static function ensureUpToDate()
    {
        $report = [];
        try {
            $connection = \Bitrix\Main\Application::getConnection();
            $table = EquipmentTable::getTableName();

            $columns = [];
            try {
                $res = $connection->query("SHOW COLUMNS FROM `{$table}`");
                while ($row = $res->fetch()) {
                    $columns[strtoupper($row['Field'])] = $row;
                }
            } catch (\Exception $e) {
                return ['ok' => false, 'report' => ['Table ' . $table . ' not found: ' . $e->getMessage()]];
            }

            $exec = function ($sql, $label) use ($connection, &$report) {
                try {
                    $connection->queryExecute($sql);
                    $report[] = 'OK: ' . $label;
                } catch (\Exception $e) {
                    $report[] = 'SKIP/ERR (' . $label . '): ' . $e->getMessage();
                }
            };

            if (isset($columns['BARCODE_TEXT'])) {
                $exec("ALTER TABLE `{$table}` DROP COLUMN `BARCODE_TEXT`", 'dropped BARCODE_TEXT');
            } else {
                $report[] = 'OK: BARCODE_TEXT already absent';
            }

            if (isset($columns['QR_CODE_TEXT'])) {
                $exec("ALTER TABLE `{$table}` DROP COLUMN `QR_CODE_TEXT`", 'dropped QR_CODE_TEXT');
            } else {
                $report[] = 'OK: QR_CODE_TEXT already absent';
            }

            if (!isset($columns['ASSET_UUID'])) {
                $exec("ALTER TABLE `{$table}` ADD COLUMN `ASSET_UUID` VARCHAR(100) NULL COMMENT 'External asset UUID' AFTER `SERIAL_NR`", 'added ASSET_UUID');
            } else {
                $report[] = 'OK: ASSET_UUID already exists';
            }

            if (!isset($columns['EXTERNAL_API'])) {
                $exec("ALTER TABLE `{$table}` ADD COLUMN `EXTERNAL_API` MEDIUMTEXT NULL COMMENT 'Raw JSON synced from external asset API' AFTER `OTHERS_INFO`", 'added EXTERNAL_API');
            } else {
                $report[] = 'OK: EXTERNAL_API already exists';
            }

            if (!isset($columns['EXTERNAL_SYNC_AT'])) {
                $exec("ALTER TABLE `{$table}` ADD COLUMN `EXTERNAL_SYNC_AT` DATETIME NULL COMMENT 'Last successful external API sync' AFTER `EXTERNAL_API`", 'added EXTERNAL_SYNC_AT');
            } else {
                $report[] = 'OK: EXTERNAL_SYNC_AT already exists';
            }

            // Unique key pe ASSET_UUID (daca lipseste)
            $hasUk = false;
            try {
                $kRes = $connection->query("SHOW INDEX FROM `{$table}` WHERE Key_name = 'uk_asset_uuid'");
                if ($kRes->fetch()) $hasUk = true;
            } catch (\Exception $e) {}
            if (!$hasUk && isset($columns['ASSET_UUID'])) {
                $exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `uk_asset_uuid` (`ASSET_UUID`)", 'added unique uk_asset_uuid');
            } else {
                $report[] = 'OK: uk_asset_uuid already exists (or column missing)';
            }

            // Curata stub-ul legacy al paginii allocations eliminate
            $legacyStub = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/bitrix_inventar_allocations.php';
            if (file_exists($legacyStub)) {
                if (@unlink($legacyStub)) {
                    $report[] = 'OK: removed legacy stub bitrix_inventar_allocations.php';
                } else {
                    $report[] = 'WARN: could not remove legacy stub bitrix_inventar_allocations.php (delete manually)';
                }
            } else {
                $report[] = 'OK: legacy allocations stub already absent';
            }

            return ['ok' => true, 'report' => $report];
        } catch (\Exception $e) {
            $report[] = 'FATAL: ' . $e->getMessage();
            return ['ok' => false, 'report' => $report];
        }
    }
}
