#!/usr/bin/php
<?php
/**
 * AWS CloudWatch メトリクス取得
 *
 * スクリプト実行時にコマンドラインで引数を指定する
 * ex) # php cloudwatch_getmetric.php -n AWS/RDS -m CPUUtilization -s Average -u Percent -d DBInstanceIdentifier -v mydbname
 * コマンドライン引数
 * -n namespace
 * -m metric_name
 * -s statistics
 * -u unit
 * -d name
 * -v value
 *
 * config.inc.php内でproduction/develope環境の切り替えとアクセスキーとシークレットキー等の情報を指定
 *
 * パラメータに指定するメトリクス名等はAWSコンソールのCloudWatchを参照
 *
 * SDK仕様は以下
 * http://docs.aws.amazon.com/AWSSDKforPHP/latest/#m=AmazonCloudWatch/get_metric_statistics
 */

require_once(dirname(__FILE__) . "/../sdk.class.php");
require_once(dirname(__FILE__) . "/config.inc.php");

// コマンドライン引数からパラメータを取得
$option = getopt("n:m:s:u:d:v:");

// パラメータの設定
$namespace      = $option["n"];
$metric_name    = $option["m"];
$statistics     = $option["s"];
$unit           = $option["u"];
$name           = $option["d"];
$value          = $option["v"];
$start_time     = "-10 minutes";
$end_time       = "now";
$period         = 300;
$opt            = array("Dimensions" => array(array("Name" => $name,"Value" => $value)));

// Instantiate the class
$cw = new AmazonCloudWatch();
// リージョンを設定(東京)
$cw->set_region(AmazonCloudWatch::REGION_APAC_NE1);
date_default_timezone_set("Asia/Tokyo");

// CloudWatchメトリクスを取得
$response = $cw->get_metric_statistics($namespace,$metric_name,$start_time,$end_time,$period,$statistics,$unit,$opt);
$data = (float)$response->body->GetMetricStatisticsResult->Datapoints->member->$statistics;
print $data;
exit(0);