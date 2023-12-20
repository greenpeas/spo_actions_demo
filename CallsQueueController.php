<?php

namespace console\modules\daemons\controllers;

use common\drivers\VoiceInformant\VoiceInformantInterface;
use Yii;
use console\components\DaemonsController;
use yii\helpers\Json;

/**
 * Работа с очередью голосовых вызовов REG_CALLS_QUEUE
 */
class CallsQueueController extends DaemonsController {
    
    public function actionIndex() {
        
        while ($this->checkActive()) {
            foreach (Yii::$app->db->createCommand('select * from "REG_CALLS_QUEUE" limit 10')->queryAll() as $item) {
                
                /** @var VoiceInformantInterface $executor Драйвер */
                $executor = new $item['EXECUTOR'];
                
                $executor->setParams(Json::decode($item['PARAMS']));
                
                $executor->sendCall();
                
                Yii::$app->db->createCommand('delete from "REG_CALLS_QUEUE" where "ID" = :id',[':id' => $item['ID']])->execute();
                
                usleep(200000);
            }

            usleep(5050235);
        }
    }
}
