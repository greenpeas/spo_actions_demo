<?php

namespace common\generators\reports;

use Yii;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use common\models\RefZones;
use yii\db\Query;
use yii\db\Expression;
use yii\helpers\ArrayHelper;

/**
 * Генератор отчёта по расходу зон
 * @throws \Exception
 */
class ReportGeneratorSensors42 extends ReportGeneratorSensors implements ReportScheduleInterface {
    
    protected $range;
    
    protected $time;
    
    /** @var array Дополнительные поля */
    protected $additionalFields;
    
    protected $sheet;
    
    protected $spreadsheet;
    
    /** @var integer Текущая строка */
    protected $curRow;
    
    public static function title() {
        return 'Журнал учета температурного режима холодильного оборудования';
    }
            
    function __construct($task_id, $params) {
        parent::__construct($task_id, $params);
        $this->additionalFields = $params['additionalFields'] ?? [];
        $this->range            = $params['range'];
        $this->time             = $params['time'];
        $this->curRow           = 0;
    }

    public function getTitle($fileName = true){
        $title = static::title();
        
        return $fileName ? 
                ($title . ' c ' . $this->leftDate->format('d-m-Y') . ' по ' . $this->rightDate->format('d-m-Y') . ' ('.$this->format.')') :
                $title;
    }

    public function run() {
        $rawData = $this->getRawData($this->zid);
        
        switch ($this->format) {
            case 'xlsx':
                $this->generateXls($rawData);
                break;
        }
    }
    
    /**
     * Генерация XLS документа
     */
    protected function generateXls($data) {
        $this->spreadsheet = new Spreadsheet();
        $this->sheet       = $this->spreadsheet->getActiveSheet();
        
        $this->sheet->setTitle('Данные');

        $this->spreadsheet->getProperties()
                ->setCreator(Yii::$app->name)
                ->setLastModifiedBy(Yii::$app->name)
                ->setTitle($this->getTitle())
                ->setSubject($this->getTitle())
                ->setDescription($this->getTitle());
                
        $this->spreadsheet->setActiveSheetIndex(0);
        
        $this->renderPart($data);
        
        $this->spreadsheet->getActiveSheet()->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
        $this->spreadsheet->getActiveSheet()->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $this->spreadsheet->getActiveSheet()->getPageSetup()->setFitToWidth(1);
        $this->spreadsheet->getActiveSheet()->getPageSetup()->setFitToHeight(0);
        
        // Поля
        $this->sheet->getPageMargins()->setTop(0.2);
        $this->sheet->getPageMargins()->setRight(0.2);
        $this->sheet->getPageMargins()->setLeft(1);
        $this->sheet->getPageMargins()->setBottom(0.2);
        $this->sheet->getHeaderFooter()->setOddFooter('&L ' . 'Зона: ' . $this->zoneTitle . '. Дата и время: ' . date("d.m.Y H:i") . ' &R Страница &P из &N'); // Нижний колонтитул
        //$this->sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($dataStartRow - 3, $dataStartRow - 1);

        $writer = new Xlsx($this->spreadsheet);
        try {
            $writer->save($this->getFilePath('@backend/runtime/reports'));
        } catch (\PhpOffice\PhpSpreadsheet\Writer\Exception $ex) {
            throw new \Exception($ex->getMessage());
        }
    }
    
    /**
     * Рендеринг партии устройств
     */
    protected function renderPart($data){
        $devicesData = $data['devicesData'];
        $dates = $data['dates'];
        $devices = $data['devices'];

        $columnCount = count($dates) + 1;

        $this->generateHeader($columnCount);

        $this->generateTableHeader($dates);

        $dataStartRow = $this->curRow;

        $this->sheet->freezePaneByColumnAndRow(1, $this->curRow);

        foreach ($devicesData as $deviceId => $deviceData) {
            $device = $devices[$deviceId];
            $firstColumnData = [$device['ADDRESS'] ?? "", $device['PRIM'] ?? "", $device['SERIAL_NUMBER'] ?? ""];
            $firstColumnValue = implode("\n", array_filter($firstColumnData));
            $this->sheet->getCellByColumnAndRow(1, $this->curRow)->setValue($firstColumnValue);
            $this->sheet->getStyle('A' . $this->curRow)->getAlignment()->setWrapText(true);
            
            $this->renderRow($deviceData);
            $this->curRow++;
        }

        $totalCenterStyleArray = [
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                ],
                'inside' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_HAIR,
                ],
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ]
        ];
        
        $this->sheet->getStyleByColumnAndRow(1, $dataStartRow, $columnCount, $this->curRow - 1)->applyFromArray($totalCenterStyleArray);      
        
        $leftAligmentStyleArray = [
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ]
        ];
        
        $this->sheet->getStyleByColumnAndRow(1, $dataStartRow, 1, $this->curRow - 1)->applyFromArray($leftAligmentStyleArray);      

    }
    
    /**
     * Рендеринг строки
     * @param type $this->sheet
     * @param type $row
     * @param type $devices
     * @return type
     */
    protected function renderRow($deviceData) {
        $curColumn = 2;
        
        foreach ($deviceData as $columnData) {
            $this->sheet->getCellByColumnAndRow($curColumn, $this->curRow)->setValue($columnData['temperature'] ?? 'Нет данных');
            $curColumn++;
        }
    }
    
    /**
     * Генерим шапку
     * @return int
     */
    protected function generateHeader($columnCount) {
        $this->sheet->getCellByColumnAndRow(1, $this->curRow+1)->setValue($this->getTitle(false));
        $this->sheet->getCellByColumnAndRow(1, $this->curRow+2)->setValue('Зона: ' . $this->zoneTitle);
        $this->sheet->getCellByColumnAndRow(1, $this->curRow+3)->setValue('Период выборки: с ' . $this->leftDate->format('d.m.Y') . ' по ' . $this->rightDate->format('d.m.Y'));
        $time = $this->time;
        $range = $this->range;
        $this->sheet->getCellByColumnAndRow(1, $this->curRow+4)->setValue("Время измерения: $time (±$range ч)");
        $this->sheet->getCellByColumnAndRow(1, $this->curRow+5)->setValue('Дата и время формирования отчета: ' . date("d.m.Y H:i"));
        
        for ($i = 1; $i <= 4; $i++) {
            $this->sheet->mergeCellsByColumnAndRow(1, $this->curRow+$i, $columnCount, $this->curRow+$i);
        }
        
        $titlesStyleArray = [
            'font' => [
                'bold' => true,
                'size' => 14
            ],
        ];
        
        $this->sheet->getStyleByColumnAndRow(1, $this->curRow+1, $columnCount, $this->curRow+1)->applyFromArray($titlesStyleArray);
        
        $this->curRow += 6;
    }
    
    /**
     * Генерим заголовки таблицы
     * @param type $dates
     * @return type
     */
    protected function generateTableHeader($dates) {
        $curColumn = 1;
        
        $rowsForMerge = 2;
        
        $addRow = 1;
        
        $this->sheet->getCellByColumnAndRow($curColumn, $this->curRow)->setValue("Наименование производственного\nпомещения/оборудования");
        $this->sheet->getStyle('A' . $this->curRow)->getAlignment()->setWrapText(true);
        $this->sheet->mergeCellsByColumnAndRow($curColumn, $this->curRow, $curColumn, $this->curRow + $rowsForMerge);
        $curColumn++;

        $first = reset($dates);
        $last = end($dates);

        $this->sheet->getCellByColumnAndRow($curColumn, $this->curRow)->setValue('Температура в градусах Цельсия');
        $this->sheet->mergeCellsByColumnAndRow($curColumn, $this->curRow, $curColumn + count($dates) - 1, $this->curRow);
        $this->curRow++;

        $this->sheet->getCellByColumnAndRow($curColumn, $this->curRow)->setValue("$first по $last");
        $this->sheet->mergeCellsByColumnAndRow($curColumn, $this->curRow, $curColumn + count($dates)- 1, $this->curRow);

        foreach ($dates as $date) {
            $items = explode('.', $date);
            $title = (int) array_shift($items);
            
            $this->sheet->getCellByColumnAndRow($curColumn, $this->curRow + $addRow)->setValue($title);
            
            $curColumn ++;
        }

        for ($i = 1; $i <= $curColumn; $i++) {
            $this->sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        $headerStyleArray = [
            'font' => [
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                ],
                'inside' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_HAIR,
                ],
            ],
        ];

        $this->sheet->getStyleByColumnAndRow(1, $this->curRow - 1, $curColumn - 1, $this->curRow + $addRow)->applyFromArray($headerStyleArray);

        $additionalFieldsStyleArray = [
            'font' => [
                'bold' => false
            ]
        ];
        
        // Меняем стиль дополнительных полей
        $this->sheet->getStyleByColumnAndRow(2, $this->curRow + 1, $curColumn - 1, $this->curRow + $addRow)->applyFromArray($additionalFieldsStyleArray);
              
        $addRow++;
        return $this->curRow += $addRow;
    }
    
    /**
     * Выборка сырых данных из БД
     * @return type
     */
    protected function getRawData($zid) {
        $q = new \common\components\Query;
        
        $q->with(RefZones::getChildsIDsQ($zid,['ID','TITLE']));
        
        $q->select([
            's.ID',
            's.SERIAL_NUMBER'
        ]);
        $q->from(['rz' => 'REG_ZONES_SENSORS']);        
        $q->innerJoin('r','r."ID" = rz."ZONE_ID"');
        $q->innerJoin(['s' => 'REF_SENSORS'], 's."ID" = rz."SENSOR_ID" and s."IS_ACTIVE" = 1');
        $q->leftJoin(['ps' => 'REG_SENSORS_PLANS'], 'ps."SENSOR_ID" = s."ID"');
        $q->leftJoin(['p' => 'REF_PLANS'], 'p."ID" = ps."PLAN_ID" and p."IS_SHOW_SPO" = true');
        $q->orderBy('s.SERIAL_NUMBER');

        if (in_array('address', $this->additionalFields)) {
            $q->leftJoin(['adr' => 'REF_ADDRESSES'], 'adr."ID" = s."ADDRESS"');
            $q->addSelect(['ADDRESS' => 'adr.TITLE']);
        }
        
        if (in_array('prim', $this->additionalFields)) {
            $q->addSelect('s.PRIM');
        }
        
        $data = [];
        $dates = [];
        
        $interval = \DateInterval::createFromDateString('1 day');
        $period = new \DatePeriod($this->leftDate, $interval, $this->rightDate);
        
        $sensors = ArrayHelper::index($q->all(), 'ID');
        
        $ids = ArrayHelper::getColumn($sensors, "ID");
        
        foreach ($period as $dt) {
            $dates[] = $dt->format('d.m.Y');
            
            $qData = new Query();
            $qData->where(['s.ID' => $ids]);
            $from = ['s' => "REF_SENSORS"];
            $select = ['ID' => 's.ID'];

            $qData->leftJoin(["P" => $this->getLateralExpression($dt, $this->time)], "\"P\"." . '"SENSOR_ID" = s."ID"');
            $select["T"] = "P.TEMPERATURE";
            $select["H"] = "P.HUMIDITY";
            $select["D"] = "P.DEV_TIME";
            
            $qData->select($select);
            $qData->from($from);
            $qData->orderBy('s.SERIAL_NUMBER');

            foreach ($qData->all() as $row) {
                $id = $row['ID'];
                $devTime = $row["D"] ? (new \DateTime(date("Y-m-d H:i:s", strtotime( $row["D"] ))))->format('d.m.Y H:i:s') : '---';
                $date = $dt->format('Y-m-d');
                ArrayHelper::setValue($data, "$id.$date.temperature", $row["T"]);
                ArrayHelper::setValue($data, "$id.$date.humidity", $row["H"]);
                ArrayHelper::setValue($data, "$id.$date.time", $devTime);
            }
        }

        return [
                    'devices' => $sensors, 
                    'devicesData' => $data,
                    'dates' => $dates
                ];

    }
    
    /**
     * Получение кода подзапроса
     * @param type $date
     * @param type $time
     * @return Expression
     */
    protected function getLateralExpression($date, $time) {
        $left =  date("Y-m-d H:i:s.000 O", strtotime("-$this->range hours", strtotime($date->format("Y-m-d $time:s"))));
        $right =  date("Y-m-d H:i:s.999 O", strtotime("+$this->range hours", strtotime($date->format("Y-m-d $time:s"))));

        $sql = 'LATERAL (SELECT "DEV_TIME", "SENSOR_ID",
            ROUND("TEMPERATURE",1)::numeric(4,1) as "TEMPERATURE", ROUND("HUMIDITY",1)::numeric(4,1) as "HUMIDITY"
	FROM "REG_SENSORS_DATA" 
	WHERE "DEV_TIME" BETWEEN \'' . $left . '\' AND \'' . $right . '\'
	and "SENSOR_ID" = s."ID"
    ORDER BY "DEV_TIME" <-> \'' . $date->format("Y-m-d $time:s") . '\'
	LIMIT 1)';

        return new Expression($sql);
    }

}
