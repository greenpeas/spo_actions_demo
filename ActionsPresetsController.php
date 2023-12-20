<?php

namespace app\modules\api\controllers;

use backend\models\PresetActionsForm;
use common\components\Reference;
use common\components\SInnerAPIController;
use common\models\RefLeakageSensors;
use common\models\RefSensors;
use common\models\RefValvesNbiot;
use common\models\RegAbonentActionsPresets;
use common\models\RegAbonentActionsPresetsLeakageSensors;
use common\models\RegAbonentActionsPresetsSensors;
use common\models\RegAbonentActionsPresetsValvesNbiot;
use PhpOffice\PhpSpreadsheet\Calculation\MathTrig\Exp;
use Yii;
use yii\db\Expression;
use yii\db\Query;
use yii\helpers\Json;

class ActionsPresetsController extends SInnerAPIController
{

    public function actionIndex()
    {

        $get = \Yii::$app->request->get();

        $q = new Query();

        $q->select([
            'p.ID',
            'p.DATA',
            'p.DATEON',
            'p.SEVERITY',
            'TIME_START' => 'TO_CHAR(p."TIME_START"::time,\'HH24:MI\')',
            'TIME_STOP'  => 'TO_CHAR(p."TIME_STOP"::time,\'HH24:MI\')'
        ]);

        $q->from(['p' => 'REG_ABONENT_ACTIONS_PRESETS']);

        $q->orderBy(['p.DATEON' => SORT_ASC]);

        if ($this->isSuperAdmin()) {
            $q->innerJoin(['a' => 'REF_ABONENTS'], 'a."ID" = p."ABONENT"')
                ->addSelect([
                    'LOGIN' => new Expression('case when a."ID" = :userId then null else a."LOGIN" end')
                ])
                ->addParams([':userId' => Yii::$app->user->id])
                ->where(['p.ABONENT' => Yii::$app->user->id])
                ->orderBy(new Expression('case when a."ID" = :userId then null else a."LOGIN" end nulls first, p."DATEON"'));
        } else {
            $q->where(['p.ABONENT' => Yii::$app->user->id]);
        }

        $sQuery   = new Query();
        $services = null;

        // фильтр по устройству
        if (isset($get['devType'])) {
            switch ($get['devType']) {
                case 'sensors':
                    $q->addSelect(['LINK_ID' => 'reg.ID']);
                    $q->leftJoin(['reg' => 'REG_ABONENT_ACTIONS_PRESETS_SENSORS'],
                        'reg."PRESET" = p."ID" and reg."SENSOR" = :sensor',
                        [':sensor' => $get['devId']]);
                    $sQuery = (new Query())
                        ->from(['cr' => 'REG_CONTRACTS_SENSORS'])
                        ->innerJoin(['c' => 'REF_CONTRACTS'], 'c."ID" = cr."CONTRACT_ID" and c."DATE_SUPPORT_END" >= now()')
                        ->where(['cr.SENSOR_ID' => $get['devId']]);
                    break;
                case 'leakageSensors':
                    $q->addSelect(['LINK_ID' => 'reg.ID']);
                    $q->leftJoin(['reg' => 'REG_ABONENT_ACTIONS_PRESETS_LEAKAGE_SENSORS'],
                        'reg."PRESET" = p."ID" and reg."LEAKAGE_SENSOR" = :sensor',
                        [':sensor' => $get['devId']]);
                    $sQuery = (new Query())
                        ->from(['cr' => 'REG_CONTRACTS_LEAKAGE_SENSORS'])
                        ->innerJoin(['c' => 'REF_CONTRACTS'], 'c."ID" = cr."CONTRACT_ID" and c."DATE_SUPPORT_END" >= now()')
                        ->where(['cr.SENSOR_ID' => $get['devId']]);
                    break;
                case 'valvesNbiot':
                    $q->addSelect(['LINK_ID' => 'reg.ID']);
                    $q->leftJoin(['reg' => 'REG_ABONENT_ACTIONS_PRESETS_VALVES_NBIOT'],
                        'reg."PRESET" = p."ID" and reg."VALVE" = :valve',
                        [':valve' => $get['devId']]);
                    $sQuery = (new Query())
                        ->from(['cr' => 'REG_CONTRACTS_VALVES_NBIOT'])
                        ->innerJoin(['c' => 'REF_CONTRACTS'], 'c."ID" = cr."CONTRACT_ID" and c."DATE_SUPPORT_END" >= now()')
                        ->where(['cr.VALVE_ID' => $get['devId']]);
                    break;
            }

            if ($this->isSuperAdmin()) {
                $q->orWhere(['not', ['reg.ID' => null]]);
            }

            $services = $sQuery
                ->select(['list' => 'to_jsonb(c."SERVICES")'])
                ->orderBy(['c."DATE_SUPPORT_END"' => SORT_ASC])
                ->limit(1)
                ->one();
        }

        $q->andWhere(['p.ORG_PROFILE' => self::is_guid($this->getOrg()['ID'])]);

        $presets = $q->all();

        // Количество использований
        $inSensors        = $this->getCounts('REG_ABONENT_ACTIONS_PRESETS_SENSORS');
        $inLeakageSensors = $this->getCounts('REG_ABONENT_ACTIONS_PRESETS_LEAKAGE_SENSORS');
        $inValvesNbiot    = $this->getCounts('REG_ABONENT_ACTIONS_PRESETS_VALVES_NBIOT');

        array_walk($presets, function (&$el) use ($inSensors, $inLeakageSensors, $inValvesNbiot, $services) {
            $el['DATA']             = Json::decode($el['DATA']);
            $el['inSensors']        = $inSensors[$el['ID']] ?? 0;
            $el['inLeakageSensors'] = $inLeakageSensors[$el['ID']] ?? 0;
            $el['inValvesNbiot']    = $inValvesNbiot[$el['ID']] ?? 0;
        });

        return [
            'status'        => 'ok',
            'items'         => $presets,
            'services'      => json_decode($services['list'] ?? '[]'),
            'severity_list' => Reference::severity()
        ];
    }

    private function getCounts($table)
    {
        $q = new Query();
        $q->select([
            't.PRESET',
            'COUNT' => 'count(*)'
        ]);
        $q->from(['t' => $table]);
        $q->innerJoin(['p' => 'REG_ABONENT_ACTIONS_PRESETS'], 'p."ID" = t."PRESET"');
        $q->groupBy(['t.PRESET']);

        $result = [];

        foreach ($q->all() as $item) {
            $result[$item['PRESET']] = $item['COUNT'];
        }

        return $result;
    }

    public function actionGet($id)
    {

        if (empty($id)) {
            return [
                'SEVERITY_LIST' => Reference::severity()
            ];
        }

        $preset = RegAbonentActionsPresets::find()
            ->where(['ID' => $id, 'ABONENT' => Yii::$app->user->id])
            ->asArray()
            ->one();

        if ($preset) {
            $preset['SEVERITY_LIST']    = Reference::severity();
            $preset['DATA']             = Json::decode($preset['DATA']);
        }

        return $preset;
    }

    public function actionSave($id)
    {

        if (empty($id)) {
            $id = '00000000-0000-0000-0000-000000000000';
        }

        $post = \Yii::$app->request->post();

        $presetActionsForm = new PresetActionsForm();

        $presetActionsForm->load(['PresetActionsForm' => $post]);

        if (!$presetActionsForm->validate()) {
            return ['status' => 'err', 'message' => ['summary' => [$presetActionsForm->getErrors()]]];
        }

        $preset = RegAbonentActionsPresets::find()
            ->where(['ID' => $id, 'ABONENT' => Yii::$app->user->id])
            ->one();

        if (!$preset) {
            $preset              = new RegAbonentActionsPresets();
            $preset->ABONENT     = Yii::$app->user->id;
            $preset->ORG_PROFILE = $this->getOrg()['ID'];
        }

        $preset->SEVERITY   = $post['severity'];
        $preset->TIME_START = $post['time_start'];
        $preset->TIME_STOP  = $post['time_stop'];

        $preset->DATA = [
            'actions' => $presetActionsForm->getNotNullAttributes()
        ];

        if (!$preset->save()) {
            return ['status' => 'err', 'message' => $preset->getErrors()];
        }

        return ['status' => 'ok'];
    }

    /**
     * Удаление пресета
     * @return type
     */
    public function actionDelete()
    {

        $post = \Yii::$app->request->post();

        $preset = RegAbonentActionsPresets::find()
            ->where(['ID' => $post['id'], 'ABONENT' => Yii::$app->user->id])
            ->one();
        if (!$preset) {
            return ['status' => 'err', 'message' => 'Пресет не найден или нет прав на удаление выбранного пресета'];
        }

        $preset->delete();

        return ['status' => 'ok'];
    }

    /**
     * Создание связки
     * @return type
     */
    public function actionCreateLink()
    {
        $post = \Yii::$app->request->post();

        $preset = RegAbonentActionsPresets::find()
            ->where(['ID' => $post['presetId'], 'ABONENT' => Yii::$app->user->id])
            ->one();
        if (!$preset) {
            return ['status' => 'err', 'message' => 'Пресет не найден или нет прав на удаление выбранного пресета'];
        }

        switch ($post['devType']) {
            case 'sensors' :
                $link         = new RegAbonentActionsPresetsSensors();
                $link->PRESET = $post['presetId'];
                $link->SENSOR = $post['devId'];
                break;
            case 'leakageSensors' :
                $link                 = new RegAbonentActionsPresetsLeakageSensors();
                $link->PRESET         = $post['presetId'];
                $link->LEAKAGE_SENSOR = $post['devId'];
                break;
            case 'valvesNbiot' :
                $link         = new RegAbonentActionsPresetsValvesNbiot();
                $link->PRESET = $post['presetId'];
                $link->VALVE  = $post['devId'];
                break;
        }

        if (!isset($link) || !$link->save()) {
            return ['status' => 'err', 'message' => 'Ошибка создания связки: ' . $link->ersToStr()];
        }

        return ['status' => 'ok'];
    }

    /**
     * Удаление связки
     * @return type
     */
    public function actionDeleteLink()
    {

        $post = \Yii::$app->request->post();

        switch ($post['devType']) {
            case 'sensors' :
                $link = RegAbonentActionsPresetsSensors::find()
                    ->joinWith(['preset'])
                    ->where(['REG_ABONENT_ACTIONS_PRESETS_SENSORS.ID' => $post['linkId'], 'REG_ABONENT_ACTIONS_PRESETS.ABONENT' => Yii::$app->user->id])
                    ->one();
                break;
            case 'leakageSensors' :
                $link = RegAbonentActionsPresetsLeakageSensors::find()
                    ->joinWith(['preset'])
                    ->where(['REG_ABONENT_ACTIONS_PRESETS_LEAKAGE_SENSORS.ID' => $post['linkId'], 'REG_ABONENT_ACTIONS_PRESETS.ABONENT' => Yii::$app->user->id])
                    ->one();
                break;
            case 'valvesNbiot' :
                $link = RegAbonentActionsPresetsValvesNbiot::find()
                    ->joinWith(['preset'])
                    ->where(['REG_ABONENT_ACTIONS_PRESETS_VALVES_NBIOT.ID' => $post['linkId'], 'REG_ABONENT_ACTIONS_PRESETS.ABONENT' => Yii::$app->user->id])
                    ->one();
                break;
        }

        if (!$link) {
            return ['status' => 'err', 'message' => 'Привязка не найдена или нет прав на ее удаление'];
        }

        $link->delete();

        return ['status' => 'ok'];
    }

    /**
     * Список устройст для пресета
     */
    public function actionDevices()
    {

        $get = Yii::$app->request->get();

        $answer = [
            'draw'            => $get['draw'],
            'recordsTotal'    => 0,
            'recordsFiltered' => 0,
            'data'            => []
        ];

        $preset = RegAbonentActionsPresets::find()
            ->where(['ID' => $get['presetId'], 'ABONENT' => Yii::$app->user->id])
            ->one();

        if (!$preset) {
            return $answer;
        }

        switch ($get['devType']) {
            case 'sensors' :
                $answer = $this->getSensors($get['presetId'], $get['search']['value'], $get['start'], $get['length']);
                break;
            case 'leakageSensors' :
                $answer = $this->getLeakageSensors($get['presetId'], $get['search']['value'], $get['start'], $get['length']);
                break;
            case 'valvesNbiot' :
                $answer = $this->getValvesNbiot($get['presetId'], $get['search']['value'], $get['start'], $get['length']);
                break;
        }

        return $answer;
    }

    /**
     * Выборка термогигрометров
     * @param type $presetId
     * @param type $search
     * @param type $start
     * @param type $length
     * @return type
     */
    private function getSensors($presetId, $search, $start, $length)
    {

        $get = Yii::$app->request->get();

        $answer = [
            'draw'            => $get['draw'],
            'recordsTotal'    => 0,
            'recordsFiltered' => 0,
            'data'            => []
        ];

        $q = new Query();

        $controller   = \Yii::$app->controller;
        $allowedZones = $controller->isSuperAdmin() ? null : $controller->zonesIds;
        $qZones       = RefSensors::queryGetZones('d."ID"', ['TITLE'], true, $allowedZones);

        $q->select([
            'ID'      => new Expression('distinct on (d."ID") d."ID"'),
            'TYPE'    => 'm."DESCRIPTION" || \' \' || m."TITLE"',
            'SERIAL'  => 'd.SERIAL_NUMBER',
            'ZONES'   => $qZones,
            'PRIM'    => 'd.PRIM',
            'LINK_ID' => 'r.ID'
        ]);
        $q->from(['r' => 'REG_ABONENT_ACTIONS_PRESETS_SENSORS']);
        $q->innerJoin(['d' => 'REF_SENSORS'], 'd."ID" = r."SENSOR"');
        $q->leftJoin(['m' => 'REF_SENSORS_MODELS'], 'm."ID" = d."MODEL"');
        $q->leftJoin(['z' => 'REG_ZONES_SENSORS'], 'z."SENSOR_ID" = d."ID"');

        $q->where(['r.PRESET' => $presetId]);
        $q->orderBy('d.ID');

        $answer['recordsTotal'] = $q->count();

        $q->andFilterWhere([
            'OR',
            ['ilike', 'd.SERIAL_NUMBER', $search],
            ['ilike', 'm.TITLE', $search]
        ]);

        $answer['recordsFiltered'] = $q->count();

        $q->offset($start)->limit($length);

        $answer['data'] = $q->all();

        array_walk($answer['data'], function (&$el) {
            $el['ZONES'] = Json::decode($el['ZONES']);
        });

        return $answer;
    }

    /**
     * Выборка датчиков протечки
     * @param type $presetId
     * @param type $search
     * @param type $start
     * @param type $length
     * @return type
     */
    private function getLeakageSensors($presetId, $search, $start, $length)
    {

        $get = Yii::$app->request->get();

        $answer = [
            'draw'            => $get['draw'],
            'recordsTotal'    => 0,
            'recordsFiltered' => 0,
            'data'            => []
        ];

        $q = new Query();

        $controller   = \Yii::$app->controller;
        $allowedZones = $controller->isSuperAdmin() ? null : $controller->zonesIds;
        $qZones       = RefLeakageSensors::queryGetZones('d."ID"', ['TITLE'], true, $allowedZones);

        $q->select([
            'ID'      => new Expression('distinct on (d."ID") d."ID"'),
            'TYPE'    => 'm."DESCRIPTION" || \' \' || m."TITLE"',
            'SERIAL'  => 'd.SERIAL_NUMBER',
            'ZONES'   => $qZones,
            'PRIM'    => 'd.PRIM',
            'LINK_ID' => 'r.ID'
        ]);
        $q->from(['r' => 'REG_ABONENT_ACTIONS_PRESETS_LEAKAGE_SENSORS']);
        $q->innerJoin(['d' => 'REF_LEAKAGE_SENSORS'], 'd."ID" = r."LEAKAGE_SENSOR"');
        $q->leftJoin(['m' => 'REF_SENSORS_MODELS'], 'm."ID" = d."MODEL_ID"');
        $q->leftJoin(['z' => 'REG_ZONES_SENSORS'], 'z."SENSOR_ID" = d."ID"');

        $q->where(['r.PRESET' => $presetId]);
        $q->orderBy('d.ID');

        $answer['recordsTotal'] = $q->count();

        $q->andFilterWhere([
            'OR',
            ['ilike', 'd.SERIAL_NUMBER', $search],
            ['ilike', 'm.TITLE', $search]
        ]);

        $answer['recordsFiltered'] = $q->count();

        $q->offset($start)->limit($length);

        $answer['data'] = $q->all();

        array_walk($answer['data'], function (&$el) {
            $el['ZONES'] = Json::decode($el['ZONES']);
        });

        return $answer;
    }

    /**
     * Выборка запорной арматуры NBIOT
     * @param type $presetId
     * @param type $search
     * @param type $start
     * @param type $length
     * @return type
     */
    private function getValvesNbiot($presetId, $search, $start, $length)
    {

        $get = Yii::$app->request->get();

        $answer = [
            'draw'            => $get['draw'],
            'recordsTotal'    => 0,
            'recordsFiltered' => 0,
            'data'            => []
        ];

        $q = new Query();

        $controller   = \Yii::$app->controller;
        $allowedZones = $controller->isSuperAdmin() ? null : $controller->zonesIds;
        $qZones       = RefValvesNbiot::queryGetZones('d."ID"', ['TITLE'], true, $allowedZones);

        $q->select([
            'ID'      => new Expression('distinct on (d."ID") d."ID"'),
            'TYPE'    => 'm.TITLE',
            'SERIAL'  => 'd.SERIAL_NUMBER',
            'ZONES'   => $qZones,
            'PRIM'    => 'd.PRIM',
            'LINK_ID' => 'r.ID'
        ]);
        $q->from(['r' => 'REG_ABONENT_ACTIONS_PRESETS_VALVES_NBIOT']);
        $q->innerJoin(['d' => 'REF_VALVES_NBIOT'], 'd."ID" = r."VALVE"');
        $q->leftJoin(['m' => 'REF_VALVES_MODELS'], 'm."ID" = d."MODEL_ID"');
        $q->leftJoin(['z' => 'REG_ZONES_VALVES_NBIOT'], 'z."VALVE_ID" = d."ID"');

        $q->where(['r.PRESET' => $presetId]);
        $q->orderBy('d.ID');

        $answer['recordsTotal'] = $q->count();

        $q->andFilterWhere([
            'OR',
            ['ilike', 'd.SERIAL_NUMBER', $search],
            ['ilike', 'm.TITLE', $search]
        ]);

        $answer['recordsFiltered'] = $q->count();

        $q->offset($start)->limit($length);

        $answer['data'] = $q->all();

        array_walk($answer['data'], function (&$el) {
            $el['ZONES'] = Json::decode($el['ZONES']);
        });

        return $answer;
    }

}
