<?php

namespace console\modules\service\controllers;

use common\jobs\MtsLbsJob;
use Yii;
use yii\console\Controller;
use yii\db\Exception;
use yii\helpers\Json;

/**
 * Слушаем сообщения Redis оперативного контура
 * ./yii service/ok-redis-listener
 *
 * redis-cli: PUBLISH incomingActivity '{"imei":861774059358752}'
 */
class OkRedisListenerController extends Controller {
    
    public function actionIndex() {
        
        ini_set("default_socket_timeout", -1);
        
        $params = \Yii::$app->params['redisOK'];

        try {
            
            $redis = new \Redis();
            $redis->connect($params['host'], $params['port']);
            
            if(!empty($params['password'])){
                $redis->auth($params['password']);
            }
            
            $redis->subscribe(['incomingActivity'], 'console\modules\service\controllers\OkRedisListenerController::getMessage');
            
        } catch (\RedisException $e) {
            \Yii::error($e->getMessage());
            sleep(5);
            $this->actionIndex();
        }
    }

    public static function getMessage($redis, $channel, $msg) {
        try {
            $msg = Json::decode($msg);
        } catch (yii\base\InvalidArgumentException $e) {
            \Yii::error($e->getMessage());
            return;
        }

        if(self::checkLbsRequestAllow($msg['imei'])){
            // Отправка задания в очередь
            Yii::$app->mtsLbsQueue->push(new MtsLbsJob($msg));
        }
    }

    /**
     * Проверка IMEI. Разрешено ли делать запросы LBS
     * @param string $imei
     * @return bool
     */
    public static function checkLbsRequestAllow( string $imei) : bool {
        $sql = <<<SQL
select count(*) from (
	select 1 from "REF_MODEMS" rm
	inner join "REG_CONTRACTS_MODEMS" rcm on rcm."MODEM_ID" = rm."ID"
	inner join "REF_CONTRACTS" rc on rc."ID" = rcm."CONTRACT_ID" and rc."DATE_SUPPORT_END" > now() and 'lbs' = ANY (rc."SERVICES")
	where rm."IMEI" = :imei and rm."IS_ACTIVE" = 1 and rm."ALLOW_LBS_REQUEST" is true
	union
	select 1 from "REF_LEAKAGE_SENSORS" rls
	inner join "REG_CONTRACTS_LEAKAGE_SENSORS" rcls on rcls."SENSOR_ID"  = rls."ID"
	inner join "REF_CONTRACTS" rc on rc."ID" = rcls."CONTRACT_ID" and rc."DATE_SUPPORT_END" > now() and 'lbs' = ANY (rc."SERVICES")
	where rls."IMEI" = :imei and rls."IS_ACTIVE" = 1 and rls."ALLOW_LBS_REQUEST" is true
) q
SQL;
        try {
            $count = Yii::$app->db->createCommand($sql, [':imei' => $imei])->queryScalar();
            return $count > 0;
        } catch (Exception $e) {
            Yii::error($e->getMessage());
            Yii::error($e->getTraceAsString());
        }

        return false;
    }

}
