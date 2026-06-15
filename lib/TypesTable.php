<?php
namespace Bitrix\Inventar;

use Bitrix\Main\Entity;

class TypesTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'b_bitrix_inventar_types';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true
            ]),
            new Entity\StringField('CODE', [
                'required' => true,
                'unique' => true
            ]),
            new Entity\StringField('NAME', [
                'required' => true
            ]),
            new Entity\IntegerField('SORT', [
                'default_value' => 100
            ])
        ];
    }

    public static function getAllTypes()
    {
        $types = [];
        $result = self::getList(['order' => ['SORT' => 'ASC']]);
        while ($row = $result->fetch()) {
            $types[$row['CODE']] = $row['NAME'];
        }
        return $types;
    }
}
?>