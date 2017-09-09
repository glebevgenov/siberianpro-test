<?php

namespace app\commands;

use Yii;
use yii\console\Controller;

class DigestsController extends Controller
{
    public function actionParse()
    {
        Yii::$app->digestsParser->parse();
        echo "Digests were successfully parsed and loaded into the database.\n";
    }
}
