<?php

namespace common\jobs;

use common\drivers\m2mDrivers\MTSm2mDriver;
use common\models\RefLeakageSensors;
use common\models\RefModems;
use common\models\RefSimCards;
use common\models\RefTelecomOperatorAccounts;
use common\models\RegLbsHistory;
use Yii;
use yii\base\BaseObject;
use yii\db\Expression;
use yii\queue\JobInterface;

/**
 * Работа с МТС LBS
 */
class MtsLbsJob extends BaseObject implements JobInterface {

    public $imei;

    public function execute($queue) {

        echo PHP_EOL;
        echo date('Y-m-d H:i:s ') . 'Begin LBS job for IMEI: ' . $this->imei . PHP_EOL;

        $devId = null;
        
        /** @var RefModems $device */
        $device = RefModems::find()->where(['IMEI' => $this->imei])->one();
        
        if(!$device){
            $device = RefLeakageSensors::find()->where(['IMEI' => $this->imei])->one();
        }
        
        if ($device) {
            $devId = $device->ID;
            $simCard = $device->simCard;
        }

        echo 'ICCID for '.$this->imei.': '. ($simCard->ICCID ?? 'not found') . PHP_EOL;
        
        ///////////////////////////////////////////

        if ($devId && $simCard && $this->canMakeRequest($simCard)) {
            $this->getLbsRequest($devId, $simCard);
        } else {
            echo 'Can`t do LBS request' . PHP_EOL;
        }
    }

    /**
     * 
     * @param int $devId
     * @param RefSimCards $simCard
     */
    private function getLbsRequest(string $devId, RefSimCards $simCard) {

        echo 'getLbsRequest() for DevID '.$devId . PHP_EOL;
        
        $account = RefTelecomOperatorAccounts::findBySimCardIccid($simCard->ICCID);

        echo 'Finded account: '.$account->USERNAME . PHP_EOL;

        $driver = new MTSm2mDriver($account);

        $location = $driver->getSimCardLocationById($simCard->DATA['id']);

        switch ($location['status'] ?? ''){
            case 'ok':
                $data = $location['data']['parameters'];

                $lbs            = new RegLbsHistory();
                $lbs->DEV_ID    = $devId;
                $lbs->ICCID     = $simCard->ICCID;
                $lbs->DATE_TIME = $data['dt'] . '+00';
                $lbs->LATITUDE  = $data['latitude'];
                $lbs->LONGITUDE = $data['longitude'];
                $lbs->PRECISION = $data['precision'];

                echo 'Coordinates for DevID '.$devId.': '.$data['latitude'].','.$data['longitude'] . PHP_EOL;

                if (!$lbs->save()) {
                    Yii::error($location);
                    Yii::error($lbs->getErrors());
                }
                break;
            case 'service_not_attached':
                echo 'Service not attached!'.PHP_EOL;
                break;
            default:
                echo 'Bad response from MTS. Read app.log for details' . PHP_EOL;
                Yii::error($location);
        }
    }

    /**
     * Проверка, можно ли делать LBS запрос для указанной сим-карты
     * @param RefSimCards $simCard
     */
    private function canMakeRequest(RefSimCards $simCard) {
        
        // отказываем в услуге, если с прошлого запроса по этой сим-карте прошло менее 1 часа
        
        $historyHoursCount = RegLbsHistory::find()
                ->where(['ICCID' => $simCard->ICCID])
                ->andWhere(['>','DATEON',new Expression('now() - interval \'40 minutes\'')])
                ->count();
        
        return $historyHoursCount == 0;
    }

}
