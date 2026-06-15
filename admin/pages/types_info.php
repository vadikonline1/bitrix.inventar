<?php
use Bitrix\Main\Loader;
use Bitrix\Inventar\CustomFieldsTable;
use Bitrix\Inventar\TypesTable;

Loader::includeModule('bitrix.inventar');

$APPLICATION->SetTitle("Custom Fields by Type");
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

if ($APPLICATION->GetGroupRight("bitrix.inventar") < "W") {
    $APPLICATION->AuthForm("Access denied");
}

// Save custom fields
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_fields'])) {
    // Delete all existing fields
    $existing = CustomFieldsTable::getList()->fetchAll();
    foreach ($existing as $item) {
        CustomFieldsTable::delete($item['ID']);
    }
    
    // Add new fields
    foreach ($_POST['fields'] as $tipCode => $fieldsData) {
        if (is_array($fieldsData)) {
            $fieldSort = 10;
            foreach ($fieldsData as $idx => $field) {
                if (!empty($field['label'])) {
                    $customField = [
                        'TYPE_CODE' => $tipCode,
                        'FIELD_LABEL' => $field['label'],
                        'FIELD_TYPE' => $field['type'],
                        'SORT' => $fieldSort
                    ];
                    if ($field['type'] == 'select' && !empty($field['options'])) {
                        $customField['FIELD_OPTIONS'] = $field['options'];
                    }
                    CustomFieldsTable::add($customField);
                    $fieldSort += 10;
                }
            }
        }
    }
    CAdminMessage::ShowMessage("Custom fields saved successfully!", "OK");
    LocalRedirect($APPLICATION->GetCurPage());
}

// Get all types
$tipuri = TypesTable::getList(['order' => ['SORT' => 'ASC']])->fetchAll();

// Get all custom fields grouped by type
$savedFields = [];
$allFields = CustomFieldsTable::getList(['order' => ['SORT' => 'ASC']])->fetchAll();
foreach ($allFields as $field) {
    $savedFields[$field['TYPE_CODE']][] = $field;
}
?>

<style>
.type-fields-box {
    background: #fff;
    padding: 20px;
    margin-bottom: 30px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.type-title {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #2c7ed6;
    color: #2c3e50;
}
.fields-list {
    margin-top: 10px;
}
.field-row {
    margin: 10px 0;
    padding: 10px;
    background: #f9f9f9;
    border-radius: 4px;
}
.field-row input, .field-row select {
    margin-right: 10px;
}
.field-row .options-input {
    margin-top: 5px;
    margin-left: 20px;
}
.btn-add-field {
    background: #4CAF50;
    color: white;
    border: none;
    padding: 5px 10px;
    cursor: pointer;
    margin-top: 10px;
}
.btn-remove-field {
    background: #f44336;
    color: white;
    border: none;
    padding: 2px 8px;
    cursor: pointer;
    margin-left: 10px;
}
</style>

<form method="POST">
    <?php foreach ($tipuri as $tip): ?>
    <div class="type-fields-box">
        <div class="type-title">📦 <?= htmlspecialchars($tip['NAME']) ?> (<?= htmlspecialchars($tip['CODE']) ?>)</div>
        <div class="fields-list" id="fields-<?= $tip['CODE'] ?>">
            <?php 
            $currentFields = isset($savedFields[$tip['CODE']]) ? $savedFields[$tip['CODE']] : [];
            foreach ($currentFields as $idx => $field): 
            ?>
            <div class="field-row" data-idx="<?= $idx ?>">
                <input type="text" name="fields[<?= $tip['CODE'] ?>][<?= $idx ?>][label]" value="<?= htmlspecialchars($field['FIELD_LABEL'] ?? '') ?>" placeholder="Field name" style="width:200px">
                <select name="fields[<?= $tip['CODE'] ?>][<?= $idx ?>][type]" class="field-type" onchange="toggleOptions(this)">
                    <option value="text" <?= ($field['FIELD_TYPE']??'')=='text'?'selected':'' ?>>Text</option>
                    <option value="textarea" <?= ($field['FIELD_TYPE']??'')=='textarea'?'selected':'' ?>>Textarea</option>
                    <option value="date" <?= ($field['FIELD_TYPE']??'')=='date'?'selected':'' ?>>Date</option>
                    <option value="number" <?= ($field['FIELD_TYPE']??'')=='number'?'selected':'' ?>>Number</option>
                    <option value="select" <?= ($field['FIELD_TYPE']??'')=='select'?'selected':'' ?>>Select (dropdown)</option>
                </select>
                <button type="button" class="btn-remove-field" onclick="this.closest('.field-row').remove()">Delete</button>
                <?php if (($field['FIELD_TYPE']??'') == 'select'): ?>
                <div class="options-input">
                    <label>Options (comma separated):</label><br>
                    <input type="text" name="fields[<?= $tip['CODE'] ?>][<?= $idx ?>][options]" value="<?= htmlspecialchars($field['FIELD_OPTIONS'] ?? '') ?>" style="width:300px" placeholder="Option1, Option2, Option3">
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn-add-field" onclick="addField('<?= $tip['CODE'] ?>')">+ Add field</button>
    </div>
    <?php endforeach; ?>
    
    <input type="submit" name="save_fields" value="💾 Save all fields" class="adm-btn-save">
</form>

<script>
function addField(tipCode) {
    const container = document.getElementById('fields-' + tipCode);
    const idx = container.children.length;
    const div = document.createElement('div');
    div.className = 'field-row';
    div.setAttribute('data-idx', idx);
    div.innerHTML = `
        <input type="text" name="fields[${tipCode}][${idx}][label]" placeholder="Field name" style="width:200px">
        <select name="fields[${tipCode}][${idx}][type]" class="field-type" onchange="toggleOptions(this)">
            <option value="text">Text</option>
            <option value="textarea">Textarea</option>
            <option value="date">Date</option>
            <option value="number">Number</option>
            <option value="select">Select (dropdown)</option>
        </select>
        <button type="button" class="btn-remove-field" onclick="this.closest('.field-row').remove()">Delete</button>
        <div class="options-input" style="display:none; margin-top:5px; margin-left:20px;">
            <label>Options (comma separated):</label><br>
            <input type="text" name="fields[${tipCode}][${idx}][options]" style="width:300px" placeholder="Option1, Option2, Option3">
        </div>
    `;
    container.appendChild(div);
}

function toggleOptions(select) {
    const row = select.closest('.field-row');
    let optionsDiv = row.querySelector('.options-input');
    if (select.value === 'select') {
        if (!optionsDiv) {
            optionsDiv = document.createElement('div');
            optionsDiv.className = 'options-input';
            optionsDiv.style.marginTop = '5px';
            optionsDiv.style.marginLeft = '20px';
            optionsDiv.innerHTML = `
                <label>Options (comma separated):</label><br>
                <input type="text" name="${select.getAttribute('name').replace('[type]', '[options]')}" style="width:300px" placeholder="Option1, Option2, Option3">
            `;
            row.appendChild(optionsDiv);
        } else {
            optionsDiv.style.display = 'block';
        }
    } else {
        if (optionsDiv) optionsDiv.style.display = 'none';
    }
}

// Initialize all existing rows
document.querySelectorAll('.field-type').forEach(select => {
    toggleOptions(select);
});
</script>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php"); ?>