#!/usr/bin/php
<?php
/**
 * AWS RDS DBEvents取得
 *
 * オプションを指定することで、特定のDBインスタンスの固有イベントを取得する
 * オプションを指定ない場合、過去14日間のDBインスタンス、DBのセキュリティグループ、
 * DBスナップショットとDBパラメータグループに関連したイベントを取得する
 *
 * スクリプト実行時にコマンドラインで引数を指定する
 * ex) # php rds_getevent.php -i mydbname -t db-instance -d 9999
 *
 * config.inc.php内でproduction/develope環境の切り替え、
 * アクセスキーとシークレットキー等の情報を指定
 *
 * リファレンス
 * http://docs.aws.amazon.com/AWSSDKforPHP/latest/#m=AmazonRDS/describe_events
 */

require_once(dirname(__FILE__) . "/../sdk.class.php");
require_once(dirname(__FILE__) . "/config.inc.php");

// コマンドライン引数からパラメータを取得
$option = getopt("i:t:d:");

// パラメータの設定
$source_identifire   = $option["i"];
$source_type         = $option["t"];
$duration            = $option["d"];

// パラメータの設定
$opt = array(
     "SourceIdentifier" => $source_identifire
     ,"SourceType"      => $source_type
     ,"Duration"        => $duration
);
var_dump($opt);

// Instantiate the class
$rds = new AmazonRDS();
// リージョンを設定(東京)
$rds->set_region(AmazonRDS::REGION_APAC_NE1);
date_default_timezone_set("Asia/Tokyo");

// RDS DB Eventsを取得
$response = $rds->describe_events($opt);
if(!$response->isOK())
{
    // output_log("[Error] Failed to describe_events() -" . $response->body->Errors->Error->Code . "- " . $response->body->Errors->Error->Message, LOG_FILE);
  echo "[Error] -" . $response->body->Errors->Error->Code . "] " . $response->body->Errors->Error->Message;
  exit(1);
}

$data = $response->body->DescribeEventsResult->Events->Event;

// zabbix2.0以降でしか改行を含む値は正しく表示されない(zabbix 1.8系は改行には対応していない)
// zabbix2.0以降
// $event_value = "";
// foreach ($data as $event) {
//     $event_value .= $event->Date." ".$event->Message.PHP_EOL;
// }
// zabbix1.8系
$event_value = 0;
foreach ($data as $event)
{
    if (strpos($event->Message,'failover'))
    {
        $event_value = 1;
        break;
    }
}
print($event_value);

exit(0);