<?php
namespace Bitrix\Inventar;

use Bitrix\Main\Entity;

class CustomFieldsTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'b_bitrix_inventar_custom_fields';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true
            ]),
            new Entity\StringField('TYPE_CODE', [
                'required' => true
            ]),
            new Entity\StringField('FIELD_LABEL', [
                'required' => true
            ]),
            new Entity\StringField('FIELD_TYPE', [
                'required' => true,
                'default_value' => 'text'
            ]),
            new Entity\TextField('FIELD_OPTIONS'),
            new Entity\IntegerField('SORT', [
                'default_value' => 100
            ])
        ];
    }

    public static function getFieldsByType($typeCode)
    {
        $fields = [];
        $result = self::getList([
            'filter' => ['=TYPE_CODE' => $typeCode],
            'order' => ['SORT' => 'ASC']
        ]);
        while ($row = $result->fetch()) {
            $field = [
                'label' => $row['FIELD_LABEL'],
                'type' => $row['FIELD_TYPE']
            ];
            if ($row['FIELD_TYPE'] == 'select' && !empty($row['FIELD_OPTIONS'])) {
                $field['options'] = $row['FIELD_OPTIONS'];
            }
            $fields[] = $field;
        }
        return $fields;
    }
}
?>