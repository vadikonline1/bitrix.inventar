<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Config\Option;
use Bitrix\Inventar\AllocationTable;
use Bitrix\Inventar\EquipmentTable;

Loader::includeModule('bitrix.inventar');

$APPLICATION->SetTitle("Equipment Allocations");
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

// Process add manual allocation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_allocation'])) {
    $equipmentId = intval($_POST['equipment_id']);
    $userId = intval($_POST['user_id']);
    $dataPredare = safeDate($_POST['data_predare']);
    
    if ($equipmentId && $userId && $dataPredare) {
        // Close old allocation
        $currentAlloc = AllocationTable::getList([
            'filter' => ['=EQUIPMENT_ID' => $equipmentId, '=DATA_RETURNARE' => null],
            'select' => ['ID']
        ])->fetch();
        if ($currentAlloc) {
            AllocationTable::update($currentAlloc['ID'], ['DATA_RETURNARE' => new Date()]);
        }
        
        // Add new allocation (notification is sent automatically from onAfterAdd)
        $result = AllocationTable::add([
            'EQUIPMENT_ID' => $equipmentId,
            'USER_ID' => $userId,
            'DATA_PREDARE' => $dataPredare
        ]);
        
        if ($result->isSuccess()) {
            CAdminMessage::ShowMessage("Allocation added successfully!", "OK");
            LocalRedirect($APPLICATION->GetCurPage());
        } else {
            CAdminMessage::ShowMessage("Error: " . implode(", ", $result->getErrorMessages()));
        }
    } else {
        CAdminMessage::ShowMessage("All fields are required!");
    }
}

// Process close allocation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['close_alloc'])) {
    $allocId = intval($_POST['alloc_id']);
    $motiv = $_POST['motiv_returnare'] ?? '';
    $allocation = AllocationTable::getById($allocId)->fetch();
    if ($allocation) {
        AllocationTable::update($allocId, [
            'DATA_RETURNARE' => new Date(),
            'MOTIV_RETURNARE' => $motiv
        ]);
        EquipmentTable::update($allocation['EQUIPMENT_ID'], ['STARE_ENUM' => 'in_stock']);
        CAdminMessage::ShowMessage("Allocation closed successfully!", "OK");
        LocalRedirect($APPLICATION->GetCurPage());
    }
}

// Get all allocations
$list = AllocationTable::getList([
    'order' => ['ID' => 'DESC'],
    'limit' => 100
])->fetchAll();

// Get equipment for dropdown
$equipments = EquipmentTable::getList(['order' => ['DENUMIRE' => 'ASC']])->fetchAll();

// Get users from selected group
$responsibleGroupId = Option::get('bitrix.inventar', 'responsible_group_id', 0);
$arUsers = [];
if ($responsibleGroupId) {
    $dbUsers = CUser::GetList('id', 'asc', ['GROUPS_ID' => [$responsibleGroupId], 'ACTIVE' => 'Y']);
    while ($user = $dbUsers->Fetch()) {
        $arUsers[$user['ID']] = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
        if (empty($arUsers[$user['ID']])) $arUsers[$user['ID']] = $user['LOGIN'];
    }
}

// If no group selected, show all active users
if (empty($arUsers)) {
    $dbUsers = CUser::GetList('id', 'asc', ['ACTIVE' => 'Y']);
    while ($user = $dbUsers->Fetch()) {
        $arUsers[$user['ID']] = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
        if (empty($arUsers[$user['ID']])) $arUsers[$user['ID']] = $user['LOGIN'];
    }
}
?>

<style>
.alloc-table { width: 100%; border-collapse: collapse; background: white; margin-top: 20px; }
.alloc-table th, .alloc-table td { padding: 10px; border: 1px solid #ddd; text-align: left; }
.alloc-table th { background: #f5f5f5; }
.status-active { color: green; font-weight: bold; }
.status-closed { color: gray; }
.form-box { background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.form-group { margin-bottom: 15px; }
.form-group label { display: inline-block; width: 150px; font-weight: bold; }
.form-group input, .form-group select { width: 300px; padding: 5px; }
.btn-close { background: #ff9800; color: white; border: none; padding: 3px 8px; cursor: pointer; border-radius: 3px; }
</style>

<div class="form-box">
    <h3>➕ Add manual allocation</h3>
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
            <label>Responsible user:</label>
            <select name="user_id" required>
                <option value="">- Select user -</option>
                <?php foreach ($arUsers as $uid => $uname): ?>
                <option value="<?= $uid ?>"><?= htmlspecialchars($uname) ?></option>
                <?php endforeach; ?>
            </select>
            <small>Users from the responsible group</small>
        </div>
        <div class="form-group">
            <label>Handover date:</label>
            <input type="text" name="data_predare" required value="<?= date('Y-m-d') ?>" placeholder="YYYY-MM-DD">
            <small>Format: YYYY-MM-DD (ex: 2024-01-15)</small>
        </div>
        <input type="submit" name="add_allocation" value="➕ Add allocation" class="adm-btn-save">
    </form>
</div>

<h3>📋 Allocation history</h3>

<?php if (count($list) > 0): ?>
<table class="alloc-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Equipment</th>
            <th>Inventory code</th>
            <th>User</th>
            <th>Handover date</th>
            <th>Return date</th>
            <th>Return reason</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($list as $item):
            $equipment = EquipmentTable::getById($item['EQUIPMENT_ID'])->fetch();
            $user = \Bitrix\Main\UserTable::getById($item['USER_ID'])->fetch();
            $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']) ?: $user['LOGIN'];
            $isActive = empty($item['DATA_RETURNARE']);
        ?>
        <tr>
            <td><?= $item['ID'] ?></td>
            <td><?= htmlspecialchars($equipment['DENUMIRE'] ?? 'N/A') ?></td>
            <td><strong><?= htmlspecialchars($equipment['COD_INVENTAR'] ?? 'N/A') ?></strong></td>
            <td><?= htmlspecialchars($userName) ?></td>
            <td><?= $item['DATA_PREDARE'] instanceof Date ? $item['DATA_PREDARE']->format('d.m.Y') : date('d.m.Y', strtotime($item['DATA_PREDARE'])) ?></td>
            <td><?= $item['DATA_RETURNARE'] ? ($item['DATA_RETURNARE'] instanceof Date ? $item['DATA_RETURNARE']->format('d.m.Y') : date('d.m.Y', strtotime($item['DATA_RETURNARE']))) : '-' ?></td>
            <td><?= htmlspecialchars($item['MOTIV_RETURNARE'] ?: '-') ?></td>
            <td><?php if ($isActive): ?><span class="status-active">● Active</span><?php else: ?><span class="status-closed">Closed</span><?php endif; ?></td>
            <td>
                <?php if ($isActive): ?>
                <button class="btn-close" onclick="showCloseForm(<?= $item['ID'] ?>)">Close</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div id="closeForm" style="display:none; margin-top:20px; padding:15px; background:#f9f9f9; border:1px solid #ddd;">
    <form method="POST">
        <input type="hidden" name="alloc_id" id="close_alloc_id">
        <div class="form-group">
            <label>Return reason:</label>
            <textarea name="motiv_returnare" rows="3" cols="50" class="form-control"></textarea>
        </div>
        <input type="submit" name="close_alloc" value="Confirm close" class="adm-btn-save">
        <button type="button" onclick="hideCloseForm()" class="adm-btn">Cancel</button>
    </form>
</div>

<script>
function showCloseForm(allocId) {
    document.getElementById('close_alloc_id').value = allocId;
    document.getElementById('closeForm').style.display = 'block';
}
function hideCloseForm() {
    document.getElementById('closeForm').style.display = 'none';
}
</script>

<?php else: ?>
<p>No allocations recorded.</p>
<?php endif; ?>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>