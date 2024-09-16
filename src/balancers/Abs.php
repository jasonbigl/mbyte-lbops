<?php

namespace Mtech\AwsDevops\Balancers;

abstract class Abs
{
    //获取当前发布的版本
    abstract public function getCurrentVersion();

    //获取上次部署的时间
    abstract public function getLastDeployDateTime();

    //部署完成后更新tag
    abstract public function updateTags($version);

    //当前部署的所有区域和机器, allInfo表示是否获取全部信息，默认aga只返回ins_id，route53只返回ip，allInfo表示同时返回ins_id和ip
    abstract public function getAllNodes($allInfo = false);

    //特定区域当前部署的机器, allInfo表示是否获取全部信息，默认aga只返回ins_id，route53只返回ip，allInfo表示同时返回ins_id和ip
    abstract public function getNodesByRegion($region, $allInfo = false);

    //部署（替换）指定机器
    abstract public function replaceNodes($region, $instances);

    //添加机器
    abstract public function addNodes($region, $instances);
}
