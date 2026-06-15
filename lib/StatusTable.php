<?php
namespace Bitrix\Inventar;

use Bitrix\Main\Entity;

class StatusTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'b_bitrix_inventar_status';
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
            ]),
            new Entity\StringField('COLOR', [
                'default_value' => '#666666'
            ])
        ];
    }

    public static function getAllStatus()
    {
        $status = [];
        $result = self::getList(['order' => ['SORT' => 'ASC']]);
        while ($row = $result->fetch()) {
            $status[$row['CODE']] = [
                'name' => $row['NAME'],
                'color' => $row['COLOR']
            ];
        }
        return $status;
    }
}
?>