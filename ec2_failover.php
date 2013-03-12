#!/usr/bin/php
<?php
/**
 * AWS EC2インスタンスフェイルオーバー
 *
 * Zabbixにて障害検知を行い、アクションのオペレーションでこのスクリプトを実行する
 * 障害が発生したEC2インスタンスをterminateし、バックアップしておいたAMIをもとに
 * 新たにEC2インスタンスを作成後、tag付けを行なう
 *
 * 作成するインスタンスはterminateと同様のものを作成(セキュリティグループ,プライベートIP,インスタンスタイプ等)
 *
 * terminate対象
 * ・Zabbixにより障害と判定されたインスタンスかつ状態が[running]のもの
 *
 * 考慮してないこと
 * ・インスタンス作成に失敗した場合
 * ・障害発生したインスタンスのステータスが[running]以外の場合は、フェイルオーバー対象外となってしまう
 *
 * config.inc.php内でproduction/develope環境の切り替えとアクセスキーとシークレットキーの情報を指定、
 * output_log関数の定義
 *
 */

require_once(dirname(__FILE__) . "/../sdk.class.php");
require_once(dirname(__FILE__) . "/config.inc.php");
define("LOG_FILE", "ec2_failover.log");

$instance_name = $argv[1]; // 障害が発生したインスタンス名
date_default_timezone_set("Asia/Tokyo");

$ec2 = new AmazonEC2();
$ec2->set_region(AmazonEC2::REGION_APAC_NE1); // 東京リージョン

output_log("[Info] Begin Failover ec2. " . $instance_name, LOG_FILE);
// EC2の情報取得
$response = $ec2->describe_instances(array(
    "Filter" => array(
        array(
            "Name" => "tag:Name"
            ,"Value" => $instance_name
        )
        ,array(
            "Name" => "instance-state-name"
            ,"Value" => "running"
        )
    )
));

if(!$response->isOK())
{
    output_log("[Error] Failed to describe_instances() -" . $response->body->Errors->Error->Code . "- " . $response->body->Errors->Error->Message, LOG_FILE);
    exit(1);
}

// terminate対象インスタンスIDが取得できない場合は処理を終了
if(!isset($response->body->reservationSet->item->instancesSet))
{
    output_log("[Error] Failed to describe_instances() instance does not exist", LOG_FILE);
    exit(1);
}

$instance_id = $response->body->reservationSet->item->instancesSet->item->instanceId;

$array_security_group_id = array();
foreach ($response->body->reservationSet->item->instancesSet->item->groupSet->item as $item) {
    array_push($array_security_group_id, $item->groupId);
}
// インスタンス作成用のオプションを定義
$options = array(
    "KeyName"           => $response->body->reservationSet->item->instancesSet->item->keyName
    ,"SecurityGroupId"  => $array_security_group_id
    ,"InstanceType"     => $response->body->reservationSet->item->instancesSet->item->instanceType
    ,"SubnetId"         => $response->body->reservationSet->item->instancesSet->item->subnetId
    ,"PrivateIpAddress" => $response->body->reservationSet->item->instancesSet->item->privateIpAddress
);

// 事前にバックアップしてある、terminate対象インスタンスのAMI取得
$response = $ec2->describe_images(array(
    "Owner"     => "self"
    ,"Filter"   => array(array("Name" => "tag:Name", "Value"=>$instance_name))
));
if(!$response->isOK())
{
    output_log("[Error] Failed to describe_images() -" . $response->body->Errors->Error->Code . "- " . $response->body->Errors->Error->Message, LOG_FILE);
    exit(1);
}

if(empty($response->body->imagesSet))
{
    output_log("[Error] Failed to describe_images() ami does not exist", LOG_FILE);
    exit(1);
}

$i = 0;
foreach ($response->body->imagesSet->item as $item) {
    // 一世代前(最新)のAMIを取得
    // 世代管理を行なっているAMI名は[インスタンス名 + -(ハイフン) + DATE["YmdHis"](AMI作成日時)]
    $create_date = substr($item->name, strlen($instance_name) + 1);
    if ($i < $create_date)
    {
        $i = $create_date;
        $image_id = $item->imageId;
    }
}

// Terminate instances
_terminate_instances($ec2, $instance_id);

// インスタンスのステータスが[terminate]になるまで待機
// 5分間ループしたら処理を終了する
// $loop_cnt = 0;
do
{
    echo '.'; // Output something so we don't get a processing timeout from the test runner
    sleep(10);

    // Update the status of the instance
    $status_check = $ec2->describe_instances(array(
        'InstanceId' => $instance_id
    ));
    // if($loop_cnt++ > 29){
    //     exit(1);
    // }
}
while ((int) $status_check->body->reservationSet->item->instancesSet->item->instanceState->code !== AmazonEC2::STATE_TERMINATED);

// terminate直後だと↓のようなエラーが発生するので、30秒待機
// InvalidIPAddress.InUse
sleep(30);

// VPC内にterminateしたインスタンスと同等のインスタンスを作成
$_instance_id = _run_instances($ec2 , $image_id, $options);

// インスタンスのステータスが[running]になるまで待機
do
{
    echo '.'; // Output something so we don't get a processing timeout from the test runner
    sleep(10);

    // Update the status of the instance
    $status_check = $ec2->describe_instances(array(
        'InstanceId' => $_instance_id
    ));
}
while ((int) $status_check->body->reservationSet->item->instancesSet->item->instanceState->code !== AmazonEC2::STATE_RUNNING);

// インスタンスにタグを設定
_create_tags($ec2, $_instance_id, $instance_name);

output_log("[Info] End failover ec2. " . $instance_name, LOG_FILE);

function _terminate_instances($ec2, $instance_id) {
    output_log("[Info] Begin terminate_instances. " . $instance_id, LOG_FILE);
    $response = $ec2->terminate_instances($instance_id);

    if(!$response->isOK())
    {
        output_log("[Error] Failed to terminate_instances -" . $response->body->Errors->Error->Code . "- " . $response->body->Errors->Error->Message, LOG_FILE);
        exit(1);
    }
    output_log("[Info] End terminate_instances. " . $instance_id, LOG_FILE);
}

function _run_instances($ec2, $image_id ,$options) {
    output_log("[Info] Begin run_instances. " . $image_id, LOG_FILE);
    $response = $ec2->run_instances($image_id, 1, 1, $options);
    $instance_id = $response->body->instancesSet->item->instanceId;

    if(!$response->isOK())
    {
        output_log("[Error] Failed to run_instances() -" . $response->body->Errors->Error->Code . "- " . $response->body->Errors->Error->Message, LOG_FILE);
        exit(1);
    }
    if(!isset($instance_id))
    {
        output_log("[Error] Failed to run_instances() instance_id does not exist", LOG_FILE);
        exit(1);
    }
    output_log(" [ Info ] End run_instances. " . $image_id, LOG_FILE);
    return $instance_id;
}

function _create_tags($ec2, $instance_id, $instance_name) {
    output_log("[Info] Begin create_tags. " . $instance_id . " " . $instance_name, LOG_FILE);
    $response = $ec2->create_tags($instance_id, array(
        array(
            "Key" => "Name"
            ,"Value" => $instance_name
        ),
        array(
            "Key" => "Backup-Generation"
            ,"Value" => "2"
        ),
    ));
    if(!$response->isOK())
    {
        output_log("[Error] Failed to create_tags() -" . $response->body->Errors->Error->Code . "- " . $response->body->Errors->Error->Message, LOG_FILE);
        exit(1);
    }
    output_log("[Info] End create_tags. " . $instance_id . " " . $instance_name, LOG_FILE);
}