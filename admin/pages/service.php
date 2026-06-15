<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Inventar\ServiceTable;
use Bitrix\Inventar\EquipmentTable;

Loader::includeModule('bitrix.inventar');

$APPLICATION->SetTitle("Service and Repairs");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

if ($APPLICATION->GetGroupRight("bitrix.inventar") < "R") {
    $APPLICATION->AuthForm("Access denied");
}

// Safe date conversion function
function safeDate($dateString) {
    if (empty($dateString)) return null;
    $dateString = trim($dateString);
    
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
        $parts = explode('-', $dateString);
        if (checkdate($parts[1], $parts[2], $parts[0])) {
            return new Date($dateString, 'Y-m-d');
        }
    }
    
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateString)) {
        $parts = explode('/', $dateString);
        if (checkdate($parts[0], $parts[1], $parts[2])) {
            return new Date($parts[2] . '-' . $parts[0] . '-' . $parts[1], 'Y-m-d');
        }
    }
    
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $dateString)) {
        $parts = explode('.', $dateString);
        if (checkdate($parts[1], $parts[0], $parts[2])) {
            return new Date($parts[2] . '-' . $parts[1] . '-' . $parts[0], 'Y-m-d');
        }
    }
    
    return null;
}

// Process add service
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_service'])) {
    $equipmentId = intval($_POST['equipment_id']);
    $dataIntrare = safeDate($_POST['data_intrare']);
    $problema = $_POST['problema'];
    
    if ($equipmentId && $dataIntrare && $problema) {
        $result = ServiceTable::add([
            'EQUIPMENT_ID' => $equipmentId,
            'DATA_INTRARE' => $dataIntrare,
            'PROBLEMA' => $problema,
            'STATUS_ENUM' => 'in_service'
        ]);
        if ($result->isSuccess()) {
            EquipmentTable::update($equipmentId, ['STARE_ENUM' => 'repair']);
            CAdminMessage::ShowMessage("Service record added successfully!", "OK");
            LocalRedirect($APPLICATION->GetCurPage());
        } else {
            CAdminMessage::ShowMessage("Error: " . implode(", ", $result->getErrorMessages()));
        }
    } else {
        CAdminMessage::ShowMessage("All fields are required!");
    }
}

// Process complete service
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_service'])) {
    $serviceId = intval($_POST['service_id']);
    $service = ServiceTable::getById($serviceId)->fetch();
    if ($service) {
        $result = ServiceTable::update($serviceId, [
            'DATA_IESIRE' => new Date(),
            'SOLUTIE' => $_POST['solutie'],
            'COST_SERVICE' => floatval($_POST['cost_service']),
            'STATUS_ENUM' => 'repaired'
        ]);
        if ($result->isSuccess()) {
            EquipmentTable::update($service['EQUIPMENT_ID'], ['STARE_ENUM' => 'in_stock']);
            CAdminMessage::ShowMessage("Service completed successfully!", "OK");
            LocalRedirect($APPLICATION->GetCurPage());
        } else {
            CAdminMessage::ShowMessage("Error: " . implode(", ", $result->getErrorMessages()));
        }
    }
}

// Process delete service
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_service'])) {
    $serviceId = intval($_POST['service_id']);
    ServiceTable::delete($serviceId);
    CAdminMessage::ShowMessage("Service record deleted successfully!", "OK");
    LocalRedirect($APPLICATION->GetCurPage());
}

// Get all services
$list = ServiceTable::getList(['order' => ['ID' => 'DESC']])->fetchAll();

// Get all equipment for dropdown
$equipments = EquipmentTable::getList(['order' => ['DENUMIRE' => 'ASC']])->fetchAll();
?>

<style>
.service-table { width: 100%; border-collapse: collapse; background: white; margin-top: 20px; }
.service-table th, .service-table td { padding: 10px; border: 1px solid #ddd; text-align: left; }
.service-table th { background: #f5f5f5; }
.status-in-service { color: orange; font-weight: bold; }
.status-repaired { color: green; }
.form-box { background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.form-group { margin-bottom: 15px; }
.form-group label { display: inline-block; width: 150px; font-weight: bold; }
.form-group input, .form-group textarea, .form-group select { width: 300px; padding: 5px; }
.btn-complete { background: #4CAF50; color: white; border: none; padding: 3px 8px; cursor: pointer; border-radius: 3px; }
.btn-delete { background: #f44336; color: white; border: none; padding: 3px 8px; cursor: pointer; border-radius: 3px; }
</style>

<div class="form-box">
    <h3>➕ Add new service</h3>
    <form method="POST">
        <div class="form-group">
            <label>Equipment:</label>
            <select name="equipment_id" required>
                <option value="">- Select equipment -</option>
                <?php foreach ($equipments as $eq): ?>
                <option value="<?= $eq['ID'] ?>">[<?= htmlspecialchars($eq['COD_INVENTAR']) ?>] <?= htmlspecialchars($eq['DENUMIRE']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Service entry date:</label>
            <input type="text" name="data_intrare" required value="<?= date('Y-m-d') ?>" placeholder="YYYY-MM-DD">
            <small>Format: YYYY-MM-DD (ex: 2024-01-15)</small>
        </div>
        <div class="form-group">
            <label>Problem / Defect:</label>
            <textarea name="problema" rows="3" cols="50" required></textarea>
        </div>
        <input type="submit" name="add_service" value="➕ Add service" class="adm-btn-save">
    </form>
</div>

<h3>📋 Service history</h3>

<?php if (count($list) > 0): ?>
<table class="service-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Equipment</th>
            <th>Inventory code</th>
            <th>Entry date</th>
            <th>Exit date</th>
            <th>Problem</th>
            <th>Solution</th>
            <th>Cost</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($list as $item):
            $equipment = EquipmentTable::getById($item['EQUIPMENT_ID'])->fetch();
            $isActive = ($item['STATUS_ENUM'] == 'in_service');
        ?>
        <tr>
            <td><?= $item['ID'] ?></td>
            <td><?= htmlspecialchars($equipment['DENUMIRE'] ?? 'N/A') ?></td>
            <td><strong><?= htmlspecialchars($equipment['COD_INVENTAR'] ?? 'N/A') ?></strong></td>
            <td><?= $item['DATA_INTRARE'] instanceof Date ? $item['DATA_INTRARE']->format('d.m.Y') : date('d.m.Y', strtotime($item['DATA_INTRARE'])) ?></td>
            <td><?= $item['DATA_IESIRE'] ? ($item['DATA_IESIRE'] instanceof Date ? $item['DATA_IESIRE']->format('d.m.Y') : date('d.m.Y', strtotime($item['DATA_IESIRE']))) : '-' ?></td>
            <td><?= htmlspecialchars(substr($item['PROBLEMA'] ?? '', 0, 80)) ?>...</td>
            <td><?= htmlspecialchars(substr($item['SOLUTIE'] ?? '', 0, 80)) ?>...</td>
            <td><?= $item['COST_SERVICE'] ? number_format($item['COST_SERVICE'], 2) . ' lei' : '-' ?></td>
            <td><?php if ($isActive): ?><span class="status-in-service">● In service</span><?php else: ?><span class="status-repaired">Repaired</span><?php endif; ?></td>
            <td>
                <?php if ($isActive): ?>
                <button class="btn-complete" onclick="showCompleteForm(<?= $item['ID'] ?>)">Complete</button>
                <?php endif; ?>
                <button class="btn-delete" onclick="if(confirm('Are you sure?')) showDeleteForm(<?= $item['ID'] ?>)">Delete</button>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div id="completeForm" style="display:none; margin-top:20px; padding:15px; background:#f9f9f9; border:1px solid #ddd;">
    <form method="POST">
        <input type="hidden" name="service_id" id="complete_service_id">
        <div class="form-group">
            <label>Solution applied:</label>
            <textarea name="solutie" rows="3" cols="50" required></textarea>
        </div>
        <div class="form-group">
            <label>Service cost (lei):</label>
            <input type="number" step="0.01" name="cost_service" value="0.00">
        </div>
        <input type="submit" name="complete_service" value="✅ Complete service" class="adm-btn-save">
        <button type="button" onclick="hideCompleteForm()" class="adm-btn">Cancel</button>
    </form>
</div>

<div id="deleteForm" style="display:none;">
    <form method="POST">
        <input type="hidden" name="service_id" id="delete_service_id">
        <input type="submit" name="delete_service" value="Delete">
    </form>
</div>

<script>
function showCompleteForm(serviceId) {
    document.getElementById('complete_service_id').value = serviceId;
    document.getElementById('completeForm').style.display = 'block';
}
function hideCompleteForm() {
    document.getElementById('completeForm').style.display = 'none';
}
function showDeleteForm(serviceId) {
    document.getElementById('delete_service_id').value = serviceId;
    document.getElementById('deleteForm').submit();
}
</script>

<?php else: ?>
<p>No service records found.</p>
<?php endif; ?>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>