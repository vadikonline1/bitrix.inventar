<?php
namespace Bitrix\Inventar;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

/**
 * Sincronizare cu API-ul extern de active (ex: http://10.130.10.232:8081/api/v1/assets/<ASSET_UUID>).
 *
 * Config (Option bitrix.inventar):
 *  - external_api_url    baza URL, ex: http://10.130.10.232:8081/api/v1/assets/
 *  - external_api_field  campul local folosit ca si cheie: ASSET_UUID | COD_INVENTAR | SERIAL_NR
 *  - external_api_token  optional, trimis ca Authorization: Bearer <token>
 *  - external_ssl_skip   Y/N — sare peste verificarea certificatului SSL
 *                        (necesar pentru hosturi intranet cu certificat self-signed)
 *  - external_sync_days  X zile pentru reimprospatare automata (cron)
 *  - external_sync_enabled Y/N — activeaza agentul Bitrix (cron)
 *
 * Datele brute (JSON) se pastreaza in b_bitrix_inventar_equipment.EXTERNAL_API
 * si se afiseaza in pagina publica de detalii /inventar/?id=...
 */
class ExternalSync
{
    const MODULE_ID = 'bitrix.inventar';
    const AGENT_CALLBACK = '\Bitrix\Inventar\ExternalSync::cronSync();';

    public static function getConfig()
    {
        return [
            'url' => trim((string)Option::get(self::MODULE_ID, 'external_api_url', '')),
            'field' => strtoupper(trim((string)Option::get(self::MODULE_ID, 'external_api_field', 'ASSET_UUID'))),
            'token' => trim((string)Option::get(self::MODULE_ID, 'external_api_token', '')),
            'ssl_skip' => Option::get(self::MODULE_ID, 'external_ssl_skip', 'N') === 'Y',
            'days' => max(1, (int)Option::get(self::MODULE_ID, 'external_sync_days', 7)),
            'enabled' => Option::get(self::MODULE_ID, 'external_sync_enabled', 'N') === 'Y',
        ];
    }

    public static function getKeyValue(array $equipment, $field = null)
    {
        $cfg = self::getConfig();
        $field = strtoupper(trim((string)($field ?: $cfg['field'])));
        if (!in_array($field, ['ASSET_UUID', 'COD_INVENTAR', 'SERIAL_NR'], true)) {
            $field = 'ASSET_UUID';
        }
        return ['field' => $field, 'value' => trim((string)($equipment[$field] ?? ''))];
    }

    public static function buildItemUrl($keyValue)
    {
        $cfg = self::getConfig();
        if ($cfg['url'] === '') return null;
        return rtrim($cfg['url'], '/') . '/' . rawurlencode($keyValue);
    }

    /**
     * Face requestul HTTP catre API-ul extern. Intoarce array cu cheile:
     * ok(bool), http(int), data(mixed, json decodat), raw(string), error(string)
     * $override permite testarea cu valori nesalvate (butonul Test connection).
     */
    public static function fetchRaw($keyValue, $override = [])
    {
        $cfg = self::getConfig();
        foreach (['url', 'token'] as $k) {
            if (array_key_exists($k, $override)) $cfg[$k] = trim((string)$override[$k]);
        }
        if (array_key_exists('ssl_skip', $override)) $cfg['ssl_skip'] = (bool)$override['ssl_skip'];

        $base = rtrim($cfg['url'], '/');
        $url = $base !== '' ? $base . '/' . rawurlencode($keyValue) : null;
        if (!$url) {
            return ['ok' => false, 'http' => 0, 'data' => null, 'raw' => '', 'error' => 'External API URL is not configured', 'url' => ''];
        }

        if (!Loader::includeModule('main')) {
            return ['ok' => false, 'http' => 0, 'data' => null, 'raw' => '', 'error' => 'Main module unavailable', 'url' => $url];
        }

        try {
            $http = new \Bitrix\Main\Web\HttpClient([
                'socketTimeout' => 15,
                'streamTimeout' => 30,
                'redirect' => true,
                'redirectMax' => 3,
                'version' => \Bitrix\Main\Web\HttpClient::HTTP_1_1,
            ]);
            $http->setHeader('Accept', 'application/json');
            $http->setHeader('User-Agent', 'BitrixInventar-ExternalSync/1.0');
            if ($cfg['token'] !== '') {
                $http->setHeader('Authorization', 'Bearer ' . $cfg['token']);
            }
            // Intranet cu certificat self-signed: sare peste verificarea SSL
            // (doar daca optiunea e activata explicit in setari).
            if (!empty($cfg['ssl_skip']) && method_exists($http, 'disableSslVerification')) {
                $http->disableSslVerification();
            }

            $body = $http->get($url);
            $status = (int)$http->getStatus();

            if ($body === false) {
                $errors = $http->getError();
                $errText = $errors ? implode('; ', (array)$errors) : 'no details';
                $error = 'HTTP request failed: ' . $errText;
                if (preg_match('/ssl|certificate|handshake/i', $errText) && empty($cfg['ssl_skip'])) {
                    $error .= ' — enable "Skip SSL verification" if the intranet host uses a self-signed certificate';
                }
                return ['ok' => false, 'http' => $status, 'data' => null, 'raw' => '', 'error' => $error, 'url' => $url];
            }
            if ($status < 200 || $status >= 300) {
                return ['ok' => false, 'http' => $status, 'data' => null, 'raw' => (string)$body, 'error' => 'External API returned HTTP ' . $status, 'url' => $url];
            }

            $decoded = json_decode((string)$body, true);
            if (!is_array($decoded) && trim((string)$body) !== '') {
                // API-ul a intors non-JSON — pastram raw-ul oricum
                return ['ok' => true, 'http' => $status, 'data' => null, 'raw' => (string)$body, 'error' => '', 'url' => $url];
            }

            return ['ok' => true, 'http' => $status, 'data' => $decoded, 'raw' => (string)$body, 'error' => '', 'url' => $url];
        } catch (\Exception $e) {
            return ['ok' => false, 'http' => 0, 'data' => null, 'raw' => '', 'error' => $e->getMessage(), 'url' => $url];
        }
    }

    /**
     * Test de conexiune pentru butonul "Test connection" din setari.
     * Nu salveaza nimic — doar incearca un GET si raporteaza.
     */
    public static function testConnection($url, $keyValue, $token = '', $sslSkip = false)
    {
        if (trim((string)$keyValue) === '') {
            return ['ok' => false, 'http' => 0, 'error' => 'Empty test key (UUID). Complete an Asset UUID first.', 'url' => ''];
        }
        $res = self::fetchRaw($keyValue, ['url' => $url, 'token' => $token, 'ssl_skip' => $sslSkip]);
        $res['bytes'] = strlen((string)($res['raw'] ?? ''));
        unset($res['raw'], $res['data']);
        return $res;
    }

    /**
     * Sincronizeaza un singur echipament. Intoarce ['ok'=>bool,'skipped'=>bool,'error'=>string].
     */
    public static function syncOne($equipmentId, $force = false)
    {
        $equipmentId = (int)$equipmentId;
        if ($equipmentId <= 0) return ['ok' => false, 'skipped' => false, 'error' => 'Invalid equipment id'];

        $item = EquipmentTable::getById($equipmentId)->fetch();
        if (!$item) return ['ok' => false, 'skipped' => false, 'error' => 'Equipment not found'];

        $key = self::getKeyValue($item);
        if ($key['value'] === '') {
            return ['ok' => false, 'skipped' => true, 'error' => 'Empty key field ' . $key['field'] . ' — nothing to sync'];
        }

        if (!$force) {
            $cfg = self::getConfig();
            if (!empty($item['EXTERNAL_SYNC_AT'])) {
                $ts = is_object($item['EXTERNAL_SYNC_AT']) && method_exists($item['EXTERNAL_SYNC_AT'], 'getTimestamp')
                    ? $item['EXTERNAL_SYNC_AT']->getTimestamp()
                    : strtotime((string)$item['EXTERNAL_SYNC_AT']);
                if ($ts && (time() - $ts) < $cfg['days'] * 86400 && !empty($item['EXTERNAL_API'])) {
                    return ['ok' => true, 'skipped' => true, 'error' => 'Fresh (synced less than ' . $cfg['days'] . ' days ago)'];
                }
            }
        }

        $res = self::fetchRaw($key['value']);
        if (!$res['ok']) {
            return ['ok' => false, 'skipped' => false, 'error' => $res['error']];
        }

        try {
            EquipmentTable::update($equipmentId, [
                'EXTERNAL_API' => $res['raw'],
                'EXTERNAL_SYNC_AT' => new DateTime(),
            ]);
        } catch (\Exception $e) {
            return ['ok' => false, 'skipped' => false, 'error' => 'DB save failed: ' . $e->getMessage()];
        }

        return ['ok' => true, 'skipped' => false, 'error' => ''];
    }

    /**
     * Sincronizeaza toate echipamentele care au cheia completata.
     * $force=true => re-sincronizeaza tot (butonul "Sync now"), altfel doar cele expirate.
     */
    public static function syncAll($force = false, $limit = 0)
    {
        $cfg = self::getConfig();
        if ($cfg['url'] === '') {
            return ['ok' => false, 'total' => 0, 'synced' => 0, 'skipped' => 0, 'errors' => 0, 'error' => 'External API URL is not configured'];
        }

        $field = $cfg['field'];
        if (!in_array($field, ['ASSET_UUID', 'COD_INVENTAR', 'SERIAL_NR'], true)) {
            $field = 'ASSET_UUID';
        }

        @set_time_limit(300);

        $filter = ['!=' . $field => ''];
        $all = EquipmentTable::getList([
            'filter' => $filter,
            'select' => ['ID', 'ASSET_UUID', 'COD_INVENTAR', 'SERIAL_NR', 'EXTERNAL_API', 'EXTERNAL_SYNC_AT'],
            'order' => ['ID' => 'ASC'],
        ])->fetchAll();

        $total = count($all);
        $synced = 0;
        $skipped = 0;
        $errors = 0;
        $errorSamples = [];

        $n = 0;
        foreach ($all as $eq) {
            if ($limit > 0 && $n >= $limit) break;
            $n++;
            $r = self::syncOne($eq['ID'], $force);
            if ($r['ok'] && empty($r['skipped'])) $synced++;
            elseif (!empty($r['skipped'])) $skipped++;
            else {
                $errors++;
                if (count($errorSamples) < 5) $errorSamples[] = 'ID ' . $eq['ID'] . ': ' . $r['error'];
            }
        }

        return [
            'ok' => true,
            'total' => $total,
            'synced' => $synced,
            'skipped' => $skipped,
            'errors' => $errors,
            'error_samples' => $errorSamples,
            'error' => '',
        ];
    }

    /**
     * Callback pentru agentul Bitrix (cron). Sincronizeaza doar intrarile expirate.
     * Trebuie sa intoarca stringul de reprogramare.
     */
    public static function cronSync()
    {
        try {
            Loader::includeModule('bitrix.inventar');
            $cfg = self::getConfig();
            if ($cfg['enabled'] && $cfg['url'] !== '') {
                self::syncAll(false);
            }
        } catch (\Exception $e) {
            // nu oprim agentul la erori
        }
        return self::AGENT_CALLBACK;
    }

    /**
     * (Re)creeaza agentul de sync conform setarilor. Se apeleaza la salvarea setarilor.
     */
    public static function refreshAgent()
    {
        try {
            \CAgent::RemoveAgent(self::AGENT_CALLBACK, self::MODULE_ID);
            $cfg = self::getConfig();
            if ($cfg['enabled'] && $cfg['url'] !== '' && $cfg['days'] > 0) {
                $interval = $cfg['days'] * 86400;
                \CAgent::AddAgent(
                    self::AGENT_CALLBACK,
                    self::MODULE_ID,
                    'N',
                    $interval,
                    date('d.m.Y H:i:s', time() + $interval),
                    'Y',
                    date('d.m.Y H:i:s', time() + $interval)
                );
                return 'Agent created: every ' . $cfg['days'] . ' day(s).';
            }
            return 'Agent removed (auto sync disabled).';
        } catch (\Exception $e) {
            return 'Agent error: ' . $e->getMessage();
        }
    }

    /**
     * Randeaza EXTERNAL_API (JSON) ca HTML pentru pagina de detalii.
     *
     * GENERIC — nu depinde de schema JSON-ului: functioneaza cu orice structura
     * (obiecte imbricate, liste de obiecte/scalari, orice adancime rezonabila).
     * - fara tabele: doar div-uri in stilul details-grid (grid-item/grid-label/grid-value);
     * - valorile goale (null, '', []) NU se afiseaza deloc (0 si false se afiseaza);
     * - stilurile vin inline cu prefix propriu, deci arata identic si in admin, si in public.
     */
    public static function renderExternalHtml($raw, $syncAt = null)
    {
        if (empty($raw)) {
            return '<div style="color:#999;">No external data synced yet.</div>';
        }
        $data = json_decode((string)$raw, true);

        $style = '<style>'
            . '.inv-ext-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:15px;margin-bottom:15px;background:#f9f9f9;border-radius:10px;padding:10px;}'
            . '.inv-ext-item{display:flex;padding:10px;background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.05);}'
            . '.inv-ext-label{font-weight:bold;width:140px;flex-shrink:0;color:#555;}'
            . '.inv-ext-value{flex:1;color:#333;word-break:break-word;}'
            . '.inv-ext-subtitle{font-size:15px;font-weight:bold;margin:18px 0 10px;padding-bottom:6px;border-bottom:2px solid #2c7ed6;color:#2c3e50;}'
            . '.inv-ext-card{background:#fff;border-radius:10px;padding:12px;margin-bottom:12px;border-left:3px solid #2c7ed6;box-shadow:0 1px 3px rgba(0,0,0,0.05);}'
            . '.inv-ext-note{color:#888;font-size:12px;margin-top:4px;}'
            . '@media (max-width:768px){.inv-ext-grid{grid-template-columns:1fr;gap:10px;}.inv-ext-item{flex-direction:column;}.inv-ext-label{width:100%;margin-bottom:5px;font-size:12px;}}'
            . '</style>';

        $syncLabel = '';
        if (!empty($syncAt)) {
            $ts = is_object($syncAt) && method_exists($syncAt, 'getTimestamp') ? $syncAt->getTimestamp() : strtotime((string)$syncAt);
            if ($ts) $syncLabel = '<div style="color:#888;font-size:12px;margin-bottom:10px;">Last sync: ' . date('d.m.Y H:i:s', $ts) . '</div>';
        }

        if (!is_array($data) || empty($data)) {
            return $style . $syncLabel . '<pre style="background:#f5f5f5;padding:12px;border-radius:6px;overflow:auto;">' . htmlspecialchars((string)$raw) . '</pre>';
        }

        // Radacina poate fi obiect sau lista — ambele tratate generic.
        if (self::isAssocArray($data)) {
            return $style . $syncLabel . self::renderExtLevel($data, 0);
        }
        return $style . $syncLabel . self::renderExtList('Items', array_values($data), 0);
    }

    /** Un nivel de obiect: grid cu scalari + subsectiuni pentru structuri. */
    protected static function renderExtLevel(array $data, $depth)
    {
        $rows = '';
        $subs = '';
        foreach ($data as $k => $v) {
            if (self::isEmptyExtValue($v)) continue;
            if (!is_array($v)) {
                $rows .= self::extItem($k, self::formatExtScalar($v, $k));
                continue;
            }
            // Prea adanc sau structura plata → valoare compacta pe un rand.
            if ($depth >= 3) {
                $rows .= self::extItem($k, self::compactExtValue($v));
                continue;
            }
            if (self::isAssocArray($v)) {
                $sub = self::renderExtLevel($v, $depth + 1);
                if ($sub !== '') $subs .= self::extSubtitle($k) . $sub;
            } else {
                $subs .= self::renderExtList($k, array_values($v), $depth);
            }
        }
        $html = '';
        if ($rows !== '') $html .= '<div class="inv-ext-grid">' . $rows . '</div>';
        return $html . $subs;
    }

    /** O lista: scalari intr-un grid, obiecte in carduri (limitate). */
    protected static function renderExtList($key, array $list, $depth)
    {
        $list = array_values(array_filter($list, function ($i) { return !self::isEmptyExtValue($i); }));
        if (empty($list)) return '';

        $cap = 30;
        $shown = array_slice($list, 0, $cap);

        $html = self::extSubtitle($key . ' (' . count($list) . ')');

        $scalarRows = '';
        foreach ($shown as $item) {
            if (is_array($item)) continue;
            $scalarRows .= self::extItem($key, self::formatExtScalar($item, $key));
        }
        if ($scalarRows !== '') $html .= '<div class="inv-ext-grid">' . $scalarRows . '</div>';

        foreach ($shown as $item) {
            if (!is_array($item)) continue;
            if (self::isAssocArray($item)) {
                $inner = self::renderExtLevel($item, $depth + 1);
            } else {
                // lista in lista — compact generic
                $inner = '<div class="inv-ext-grid">' . self::extItem($key, self::compactExtValue($item)) . '</div>';
            }
            if ($inner !== '') $html .= '<div class="inv-ext-card">' . $inner . '</div>';
        }

        if (count($list) > $cap) {
            $html .= '<div class="inv-ext-note">… and ' . (count($list) - $cap) . ' more.</div>';
        }
        return $html;
    }

    protected static function extItem($key, $formattedValue)
    {
        return '<div class="inv-ext-item"><span class="inv-ext-label">' . self::extLabel($key)
            . '</span><span class="inv-ext-value">' . $formattedValue . '</span></div>';
    }

    protected static function extSubtitle($key)
    {
        return '<div class="inv-ext-subtitle">🖥️ ' . self::extLabel($key) . '</div>';
    }

    protected static function extLabel($k)
    {
        return htmlspecialchars(is_string($k) ? ucwords(str_replace(['_', '-'], ' ', $k)) : (string)$k);
    }

    /** Gol = null, sir gol/whitespace, array gol. 0, '0' si false NU sunt goale. */
    protected static function isEmptyExtValue($v)
    {
        if ($v === null) return true;
        if (is_string($v) && trim($v) === '') return true;
        if (is_array($v) && empty($v)) return true;
        return false;
    }

    protected static function isAssocArray(array $a)
    {
        if (empty($a)) return false;
        return array_keys($a) !== range(0, count($a) - 1);
    }

    /** Formatare scalara generica: bool, octeti dupa cheie, date ISO, restul text. */
    protected static function formatExtScalar($v, $k = '')
    {
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_numeric($v) && preg_match('/bytes$/i', (string)$k) && $v >= 0) {
            return self::formatBytes((float)$v) . ' <span style="color:#999;">(' . number_format((float)$v, 0, '.', ' ') . ')</span>';
        }
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $v)) {
            $ts = strtotime($v);
            if ($ts) return date('d.m.Y H:i:s', $ts);
        }
        return htmlspecialchars((string)$v);
    }

    /** Valoare compacta pentru structuri plate sau prea adanci (o singura linie). */
    protected static function compactExtValue(array $v)
    {
        if (empty($v)) return '-';
        if (!self::isAssocArray($v)) {
            $parts = [];
            foreach ($v as $item) {
                if (self::isEmptyExtValue($item)) continue;
                $parts[] = is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : (string)$item;
            }
            return $parts ? htmlspecialchars(implode(', ', $parts)) : '-';
        }
        $parts = [];
        foreach ($v as $sk => $sv) {
            if (self::isEmptyExtValue($sv)) continue;
            $parts[] = self::extLabel($sk) . ': ' . (is_array($sv) ? json_encode($sv, JSON_UNESCAPED_UNICODE) : (string)$sv);
        }
        return $parts ? htmlspecialchars(implode(' · ', $parts)) : '-';
    }

    protected static function formatBytes($bytes)
    {
        if ($bytes < 1024) return round($bytes) . ' B';
        $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
        $i = (int)floor(log($bytes, 1024));
        $i = min($i, count($units));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i - 1];
    }
}
