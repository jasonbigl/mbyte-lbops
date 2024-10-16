<?php

namespace Mbyte\Lbops;

class Lbops extends Basic
{
    //竖向扩容机器路线
    public $verticalScaleInstypes = [
        't4g.small',
        'c6g.xlarge',
        'c6g.2xlarge',
        'c6g.4xlarge',
    ];

    /**
     * 新版本发布
     *
     * @return void
     */
    public function deploy($version, $allocateNewEIP = false, $targetRegion = null)
    {
        if ($this->opLocked()) {
            return;
        }

        $this->lockOp("deploy");

        //确定只有locked by self
        if (!$this->opLockedBy("deploy")) {
            if (file_exists($this->opLockFile)) {
                $lockedBy = file_get_contents($this->opLockFile);
                Log::info("locked by another op: {$lockedBy}, skip");
            }
            return;
        }

        $startTime = time();

        //当前版本和机器类型
        $insType = $currentVersion = null;
        if ($this->config['aga_arns']) {
            $currentVersion = $this->aga->getCurrentVersion();

            $region = reset($this->config['regions']);
            $regionNodes = $this->aga->getNodesByRegion($region);
            $node = reset($regionNodes);
            $insId = $node['ins_id'];
            $insData = $this->describeInstance($region, $insId);
            if ($insData) {
                $insType = $insData['InstanceType'];
            }
        } else {
            $currentVersion = $this->route53->getCurrentVersion();

            //也返回ins_id，方便关联旧eip
            $region = reset($this->config['regions']);
            $regionNodes = $this->route53->getNodesByRegion($region);

            $node = reset($regionNodes);
            $insIp = $node['ipv4'];
            $insData = $this->findInstanceByIP($region, $insIp);
            if ($insData) {
                $insType = $insData['InstanceType'];
            }
        }

        Log::info("### start deploy `{$this->config['module']}`, instance type `{$insType}`, new version: `{$version}`, current version: `{$currentVersion}` ###");
        sleep(5); //等待一段时间，方便观察信息是否正确

        $newRegionInsList = [];

        foreach ($this->config['regions'] as $region) {
            if ($targetRegion && $region != $targetRegion) {
                //只发布指定区域，非指定区域跳过
                continue;
            }

            //需要发布的服务器数
            $deployCount = 1;

            if ($this->config['r53_zones']) {
                //优先用route53
                $regionNodes = $this->route53->getNodesByRegion($region);
            } else {
                $regionNodes = $this->aga->getNodesByRegion($region);
            }

            if (!$regionNodes) {
                //该地区目前不存在，则发布一个
                Log::error("no current servers in {$region}, deploy new one");
                $deployCount = 1;
            } else {
                $deployCount = count($regionNodes);
            }

            Log::info("start launch {$deployCount} servers in {$region}");

            //启动ec2实例
            $newInsList = [];
            for ($i = 1; $i <= $deployCount; $i++) {
                Log::info("start launching #{$i} server in {$region}");

                //部署并等待app完成
                $newInsData = $this->launchNode($region, $version, $insType);
                if (!$newInsData) {
                    continue;
                }

                Log::info("end launching #{$i} server in {$region}");
                $newInsList[] = $newInsData;
            }

            Log::info("end launch {$deployCount} servers in {$region}: " . json_encode($newInsList, JSON_UNESCAPED_SLASHES));
            //启动实例完成

            //保存
            $newRegionInsList[$region] = $newInsList;
        }

        //将新机器部署
        foreach ($newRegionInsList as $region => $insList) {
            $insIdList = array_column($insList, 'ins_id');
            $ipv4List = array_column($insList, 'ipv4');

            //等待app ready
            $ret = $this->waitAppReady($ipv4List);
            if (!$ret) {
                continue;
            }

            //将新启动的ec2部署到route53中
            if ($this->config['r53_zones']) {

                $regionNodes = $this->route53->getNodesByRegion($region, true);

                if ($allocateNewEIP || !$regionNodes) {
                    //需要分配新eip
                    $newIpList = [];
                    foreach ($insList as $ins) {
                        $ipv4 =  $ins['ipv4'];
                        $insId =  $ins['ins_id'];

                        $newIp = null;

                        $ret = $this->allocateNewEIP($region, $version);
                        if (!$ret) {
                            Log::error("Failed to allocate new EIP");
                            $newIp = $ipv4; //用临时IP
                        } else {
                            list($allocateId, $newIp) = $ret;

                            //关联机器
                            $ret = $this->associateEIP($region, $insId, $allocateId);
                            if (!$ret) {
                                Log::error("Failed to associate EIP {$newIp} with instance {$insId}");
                                $newIp = $ipv4; //用临时IP
                            }
                        }

                        if ($newIp) {
                            $newIpList[] = $newIp;
                        }
                    }

                    $this->route53->replaceNodes($region, $newIpList);

                    //更改了node才更新date time
                    $this->route53->updateTags($version);
                } else {
                    //直接用旧的eip重新关联ec2即可，无需更改route53
                    //获取旧eip
                    foreach ($regionNodes as $idx => $rnode) {
                        $oldEIP = $rnode['ipv4'];
                        $newInsId = $insList[$idx] ?? null;
                        if (!$newInsId) {
                            Log::error("can not get new instance id according to route53 and inslist, region nodes: " . json_encode($regionNodes, JSON_UNESCAPED_SLASHES) . ', insList: ' . json_encode($insList, JSON_UNESCAPED_SLASHES) . ', idx: ' . $idx);
                            continue;
                        }
                        $newInsId = $newInsId['ins_id'];

                        $allocateId = $this->getAllocateID($region, $oldEIP);
                        if (!$allocateId) {
                            Log::error("Failed to get allocate id from EIP {$oldEIP}");
                            continue;
                        }

                        //关联新机器
                        $ret = $this->associateEIP($region, $newInsId, $allocateId);
                        if (!$ret) {
                            Log::error("Failed to associate EIP {$oldEIP} with instance {$newInsId}");
                        }
                    }
                }
            }

            //将新启动的ec2部署到aga中
            if ($this->config['aga_arns']) {
                //先全部添加
                foreach ($this->config['aga_arns'] as $agaArn) {
                    //找到第一个listener
                    $agaListeners = $this->aga->listListenerArns($agaArn);
                    $agaListenerArn = reset($agaListeners);

                    //1. 添加
                    $this->aga->addEndpoints($agaListenerArn, $region, $insIdList);
                }
            }
        }

        //有aga，之前只是全部添加，还未启用，开始检查healthy并启用
        if ($this->config['aga_arns']) {
            foreach ($newRegionInsList as $region => $insList) {
                $insIdList = array_column($insList, 'ins_id');

                foreach ($this->config['aga_arns'] as $agaArn) {
                    //找到第一个listener
                    $agaListeners = $this->aga->listListenerArns($agaArn);
                    $agaListenerArn = reset($agaListeners);

                    //2. 等待endpoint全部ready
                    $this->aga->waitEndpointsHealthy($agaListenerArn, $region, $insIdList);

                    //3.将endpoint启用，weight改成128
                    $this->aga->enableEndpoints($agaListenerArn, $region, $insIdList);
                }
            }
        }

        //有aga，删除旧节点
        if ($this->config['aga_arns']) {
            foreach ($this->config['aga_arns'] as $agaArn) {
                //4. 等待aga部署完成
                $ret = $this->aga->waitAgaDeployed($agaArn);
                if (!$ret) {
                    continue;
                }

                //找到第一个listener
                $agaListeners = $this->aga->listListenerArns($agaArn);
                $agaListenerArn = reset($agaListeners);

                foreach ($newRegionInsList as $region => $insList) {
                    $insIdList = array_column($insList, 'ins_id');

                    //5.删除旧节点 (仅保留新节点)
                    Log::info("remove old endpoints in {$region}");
                    $epgInfo = $this->aga->findEndpointGroupByRegion($agaListenerArn, $region);
                    $newEndpointsConf = [];
                    foreach ($epgInfo['EndpointDescriptions'] as $ep) {
                        if (in_array($ep['EndpointId'], $insIdList)) {
                            $newEndpointsConf[] = $ep;
                        }
                    }
                    $this->aga->client->updateEndpointGroup([
                        'EndpointConfigurations' => $newEndpointsConf,
                        'EndpointGroupArn' => $epgInfo['EndpointGroupArn'], // REQUIRED
                    ]);
                    Log::info("old endpoints removed in {$region}, remaining: " . implode(',', $insIdList));
                }
            }
        }

        //update tag
        $this->aga->updateTags($version);

        $timeUsed = time() - $startTime;
        Log::info("deploy finished, version: {$version}, time used: {$timeUsed}s");

        $this->unlockOp();
    }

    /**
     * 清理机器
     *
     * @param boolean $ignoreRoute53DeployTime 是否忽略route53的发布时间
     * @return void
     */
    public function clean($ignoreRoute53DeployTime = false, $exceptIpList = [], $exceptInsidList = [])
    {
        if ($this->config['r53_zones'] && !$ignoreRoute53DeployTime) {
            //wait at least 40 minutes if route53
            //获取上次发布时间
            $lastDeployDatetime = $this->route53->getLastDeployDateTime();
            if ($lastDeployDatetime) {
                $lastDeployTimestamp = \DateTime::createFromFormat('Y-m-d H:i:s', $lastDeployDatetime, new \DateTimeZone('Asia/Shanghai'))->getTimestamp();

                if (time() - $lastDeployTimestamp <= 2400) {
                    //less then 40 minutes
                    Log::error("the last route 53 deployment is less then 40 minutes, skip clean");
                    return;
                }
            }
        }

        if ($this->opLocked()) {
            return;
        }

        $this->lockOp("clean");

        //确定只有locked by clean
        if (!$this->opLockedBy("clean")) {
            if (file_exists($this->opLockFile)) {
                $lockedBy = file_get_contents($this->opLockFile);
                Log::info("locked by another op: {$lockedBy}, skip");
            }
            return;
        }

        Log::info("### start clean module `{$this->config['module']}` ###");
        sleep(5);

        //按地区清理
        foreach ($this->config['regions'] as $region) {
            $ec2Client = new \Aws\Ec2\Ec2Client(array_merge($this->defaultAwsConfig, [
                'region' => $region,
                'version' => '2016-11-15'
            ]));

            //记录在案的，不能清理
            $agaResvInsIds = $agaResvIps = $r53ResvInsIds = $r53ResvIps = [];

            //找到aga中的instance，这些是不能清理的
            if ($this->config['aga_arns']) {
                $agaResvInsList = $this->aga->getNodesByRegion($region, true);
                if ($agaResvInsList) {
                    $agaResvInsIds = array_column($agaResvInsList, 'ins_id');
                    $agaResvIps = array_column($agaResvInsList, 'ipv4');

                    Log::info("{$region} aga reserved instances: " . implode(',', $agaResvInsIds) . ', reserved ips: ' . implode(',', $agaResvIps));
                } else {
                    Log::info("{$region} aga reserved instances: <empty>, reserved ips: <empty>");
                }
            }

            if ($this->config['r53_zones']) {
                $r53ResvInsList = $this->route53->getNodesByRegion($region, true);
                if ($r53ResvInsList) {
                    $r53ResvInsIds = array_column($r53ResvInsList, 'ins_id');
                    $r53ResvIps = array_column($r53ResvInsList, 'ipv4');

                    Log::info("{$region} route53 reserved instances: " . implode(',', $r53ResvInsIds) . ', reserved ips: ' . implode(',', $r53ResvIps));
                } else {
                    Log::info("{$region} route53 reserved instances: <empty>, reserved ips: <empty>");
                }
            }

            if ($this->config['aga_arns'] && $this->config['r53_zones']) {
                //两者都有的时间，取交集，测试一下是否有不一样的配置
                $intersectInsIds = array_intersect($agaResvInsIds, $r53ResvInsIds);
                if (count($intersectInsIds) != count($agaResvInsIds) || count($intersectInsIds) != count($r53ResvInsIds)) {
                    Log::error("the instance id in aga and route 53 is different");
                }

                $intersectIps = array_intersect($agaResvIps, $r53ResvIps);
                if (count($intersectIps) != count($agaResvIps) || count($intersectIps) != count($r53ResvIps)) {
                    Log::error("the ip in aga and route 53 is different");
                }
            }

            if ($exceptIpList) {
                Log::info("except ip list:" . implode(',', $exceptIpList));
            }

            //清理eip
            //先找到所有的eip
            $ret = $ec2Client->describeAddresses([
                'Filters' => [
                    [
                        'Name' => 'tag:Module',
                        'Values' => [$this->config['module']]
                    ]
                ]
            ]);
            if ($ret && $ret['Addresses']) {
                //开始清理
                $cleanEIPs = [];

                foreach ($ret['Addresses'] as $eipAddr) {
                    if (
                        !in_array($eipAddr['PublicIp'], $agaResvIps)
                        && !in_array($eipAddr['PublicIp'], $r53ResvIps)
                        && !in_array($eipAddr['PublicIp'], $exceptIpList)
                    ) {
                        $cleanEIPs[] = $eipAddr['PublicIp'];
                    }
                }

                if ($cleanEIPs) {
                    $this->cleanEIPs($region, $cleanEIPs);
                }
            }

            if ($exceptInsidList) {
                Log::info("except instance id list:" . implode(',', $exceptInsidList));
            }

            //清理instance
            $ret = $ec2Client->describeInstances([
                'Filters' => [
                    [
                        'Name' => 'tag:Module',
                        'Values' => [$this->config['module']]
                    ],
                    [
                        'Name' => 'instance-state-name',
                        'Values' => ['running', 'stopped']
                    ],
                ]
            ]);

            if ($ret && $ret['Reservations']) {
                $cleanInsIds = [];

                foreach ($ret['Reservations'] as $rv) {
                    foreach ($rv['Instances'] as $ins) {
                        if (
                            !in_array($ins['InstanceId'], $agaResvInsIds)
                            && !in_array($ins['InstanceId'], $r53ResvInsIds)
                            && !in_array($ins['InstanceId'], $exceptInsidList)
                        )
                            $cleanInsIds[] = $ins['InstanceId'];
                    }
                }

                if ($cleanInsIds) {
                    $this->cleanInstances($region, $cleanInsIds);
                }
            }
        }

        $this->unlockOp();
    }

    /**
     * 横向扩容（增加机器）
     *
     * @param [type] $region 地区
     * @param integer $amount 服务器数目
     * @return void
     */
    function scaleOut($region, $amount = 1)
    {
        if ($amount < 1 || $amount > 50) {
            Log::error("invalid scale out amount, should between 1~50, current: {$amount}");
            return;
        }

        //从route 53查询现有版本
        if ($this->config['aga_arns']) {
            //优先用aga
            $currentVersion = $this->aga->getCurrentVersion();
        } else {
            $currentVersion = $this->route53->getCurrentVersion();
        }

        if ($this->opLocked()) {
            return;
        }

        $this->lockOp("scale-out");

        //确定只有locked by self
        if (!$this->opLockedBy("scale-out")) {
            if (file_exists($this->opLockFile)) {
                $lockedBy = file_get_contents($this->opLockFile);
                Log::info("locked by another op: {$lockedBy}, skip");
            }
            return;
        }

        Log::info("### scale out in region:{$region}, version: {$currentVersion}, amount: {$amount} ###");
        sleep(5);

        $insList = [];

        for ($i = 1; $i <= $amount; $i++) {
            //扩容，每次都分配新的EIP
            $insData = $this->launchNode($region, $currentVersion);
            if (!$insData) {
                Log::error("failed to launch collector");
                return false;
            }

            $insList[] = $insData;
        }

        //等待app ready
        $ret = $this->waitAppReady(array_column($insList, 'ipv4'));
        if (!$ret) {
            $this->unlockOp();
            return false;
        }

        //需要分配新eip
        $newIpList = [];
        $newInsIdList = [];
        foreach ($insList as $ins) {
            $ipv4 =  $ins['ipv4'];
            $insId =  $ins['ins_id'];

            $newInsIdList[] = $insId;

            if ($this->config['r53_zones']) {
                //有route53，需要分配新eip
                $newIp = null;

                $ret = $this->allocateNewEIP($region, $currentVersion);
                if (!$ret) {
                    Log::error("Failed to allocate new EIP");
                    $newIp = $ipv4; //用临时IP
                } else {
                    list($allocateId, $newIp) = $ret;

                    //关联机器
                    $ret = $this->associateEIP($region, $insId, $allocateId);
                    if (!$ret) {
                        Log::error("Failed to associate EIP {$newIp} with instance {$insId}");
                        $newIp = $ipv4; //用临时IP
                    }
                }

                if ($newIp) {
                    $newIpList[] = $newIp;
                }
            }
        }

        if ($this->config['r53_zones'] && $newIpList) {
            $this->route53->addNodes($region, $newIpList);
        }

        if ($this->config['aga_arns'] && $newInsIdList) {
            $this->aga->addNodes($region, $newInsIdList);
        }

        $this->unlockOp();
    }

    /**
     * 横向缩容（减少机器）
     *
     * @param [type] $region 地区
     * @param integer $amount 服务器数目
     * @return void
     */
    function scaleIn($region, $amount = 1)
    {
        if ($amount < 1 || $amount > 50) {
            Log::error("invalid scale in amount, should between 1~50, current: {$amount}");
            return;
        }

        Log::info("scale-in in region:{$region}, amount: {$amount}");

        $nodesList = [];
        if ($this->config['aga_arns']) {
            $nodesList = $this->aga->getNodesByRegion($region, true);
        } else {
            $nodesList = $this->route53->getNodesByRegion($region, true);
        }

        //删除，需要统一删除，保持route53个aga的一致性
        for ($i = 1; $i <= $amount; $i++) {
            $removeKey = array_rand($nodesList);
            unset($nodesList[$removeKey]);
        }

        if (!$nodesList) {
            Log::error("no nodes remains after removing {$amount} nodes in {$region}, skip");
            return;
        }

        if ($this->opLocked()) {
            return;
        }

        $this->lockOp("scale-in");

        //确定只有locked by self
        if (!$this->opLockedBy("scale-in")) {
            if (file_exists($this->opLockFile)) {
                $lockedBy = file_get_contents($this->opLockFile);
                Log::info("locked by another op: {$lockedBy}, skip");
            }
            return;
        }

        Log::info("remaining nodes in {$region}: " . json_encode($nodesList, JSON_UNESCAPED_SLASHES));

        if ($this->config['r53_zones']) {
            $this->route53->replaceNodes($region, array_column($nodesList, 'ipv4'));
        }

        if ($this->config['aga_arns']) {
            //直接替换，不需要等待healthy
            $insIdList = array_column($nodesList, 'ins_id');

            $newEndpointConfs = array_map(function ($tmpInsId) {
                return [
                    'ClientIPPreservationEnabled' => true,
                    'EndpointId' => $tmpInsId,
                    'Weight' => 128,
                ];
            }, $insIdList);

            //循环部署所有的aga
            foreach ($this->config['aga_arns'] as $agaArn) {
                //找到第一个listener
                $agaListeners = $this->aga->listListenerArns($agaArn);
                $agaListenerArn = reset($agaListeners);

                $epgInfo = $this->aga->findEndpointGroupByRegion($agaListenerArn, $region);

                $this->aga->client->updateEndpointGroup([
                    'EndpointConfigurations' => $newEndpointConfs,
                    'EndpointGroupArn' => $epgInfo['EndpointGroupArn'], // REQUIRED
                ]);

                Log::info("aga updated successfully in {$region} : " . implode(',', $insIdList));
            }
        }

        $this->unlockOp();
    }

    /**
     * 竖向扩容（增加机器资源如cpu等）
     *
     * @param [type] $region 地区
     * @param integer $amount 服务器数目
     * @return void
     */
    function scaleUp($region)
    {
        $amount = 0;

        $r53RegionalNodes = $agaRegionalNodes = [];

        //从route 53查询现有版本，当前机器类型
        $insType = null;
        if ($this->config['aga_arns']) {
            //优先用aga，因为route53不一定更新了tag
            $currentVersion = $this->aga->getCurrentVersion();

            $agaRegionalNodes = $this->aga->getNodesByRegion($region);
            $node = reset($agaRegionalNodes);
            $insId = $node['ins_id'];
            $insData = $this->describeInstance($region, $insId);
            if ($insData) {
                $insType = $insData['InstanceType'];

                Log::info("get current instance from aga: {$insType}");
            }

            $amount = count($agaRegionalNodes);
        } else {
            $currentVersion = $this->route53->getCurrentVersion();

            //也返回ins_id，方便关联旧eip
            $r53RegionalNodes = $this->route53->getNodesByRegion($region, true);

            $node = reset($r53RegionalNodes);
            $insIp = $node['ipv4'];
            $insData = $this->findInstanceByIP($region, $insIp);
            if ($insData) {
                $insType = $insData['InstanceType'];

                Log::info("get current instance from route53: {$insType}");
            }

            $amount = count($r53RegionalNodes);
        }

        if ($amount <= 0) {
            Log::error("invalid instance amount: {$amount}");
            return;
        }

        if (!$insType) {
            Log::error("unable to get current instance type in region {$region}");
            return;
        }

        $currentKey = array_search($insType, $this->verticalScaleInstypes);
        if ($currentKey === false) {
            Log::error("unable to find current instance type {$insType} location in types: " . implode(',', $this->verticalScaleInstypes));
            return;
        }

        $targetKey = $currentKey + 1;
        $targetInsType = $this->verticalScaleInstypes[$targetKey] ?? null;
        if (!$targetInsType) {
            //到顶了
            Log::error("unable to get target instance type based on current type {$insType}, types: " . implode(',', $this->verticalScaleInstypes));
            return;
        }

        if ($this->opLocked()) {
            return;
        }

        $this->lockOp("scale-up");

        //确定只有locked by self
        if (!$this->opLockedBy("scale-up")) {
            if (file_exists($this->opLockFile)) {
                $lockedBy = file_get_contents($this->opLockFile);
                Log::info("locked by another op: {$lockedBy}, skip");
            }
            return;
        }

        Log::info("### scale up in region:{$region}, amount: {$amount}, version: {$currentVersion}, current instance type: {$insType}, target instance type: {$targetInsType} ###");

        //启动新机器
        $insList = [];
        for ($i = 1; $i <= $amount; $i++) {
            $insData = $this->launchNode($region, $currentVersion, $targetInsType);
            if (!$insData) {
                Log::error("failed to deploy collector");
                return false;
            }

            $insList[] = $insData;
        }

        //等待app ready
        $ret = $this->waitAppReady(array_column($insList, 'ipv4'));
        if (!$ret) {
            $this->unlockOp();
            return false;
        }

        if ($this->config['r53_zones']) {
            //用旧eip
            if (!$r53RegionalNodes) {
                $r53RegionalNodes = $this->route53->getNodesByRegion($region, true);
            }

            foreach ($r53RegionalNodes as $idx => $rnode) {
                //直接用旧的eip重新关联ec2即可，无需更改route53
                $oldEIP = $rnode['ipv4'];
                $newInsId = $insList[$idx] ?? [];
                if (!$newInsId || !isset($newInsId['ins_id'])) {
                    Log::error("can not get new instance id according to route53 and inslist, region nodes: " . json_encode($r53RegionalNodes, JSON_UNESCAPED_SLASHES) . ', insList: ' . json_encode($insList, JSON_UNESCAPED_SLASHES) . ', idx: ' . $idx);
                    continue;
                }
                $newInsId = $newInsId['ins_id'];

                $allocateId = $this->getAllocateID($region, $oldEIP);
                if (!$allocateId) {
                    Log::error("Failed to get allocate id from EIP {$oldEIP}");
                    continue;
                }

                //关联新机器
                $ret = $this->associateEIP($region, $newInsId, $allocateId);
                if (!$ret) {
                    Log::error("Failed to associate EIP {$oldEIP} with instance {$newInsId}");
                }
            }
        }

        if ($this->config['aga_arns']) {
            $insIds = array_column($insList, 'ins_id');
            $this->aga->replaceNodes($region, $insIds);
        }

        $this->unlockOp();
    }

    /**
     * 竖向缩容（减少机器资源如cpu等）
     *
     * @param [type] $region 地区
     * @param integer $amount 服务器数目
     * @return void
     */
    function scaleDown($region)
    {
        $amount = 0;

        $r53RegionalNodes = $agaRegionalNodes = [];

        //从route 53查询现有版本，当前机器类型
        $insType = null;
        if ($this->config['aga_arns']) {
            //优先用aga
            $currentVersion = $this->aga->getCurrentVersion();

            $agaRegionalNodes = $this->aga->getNodesByRegion($region);
            $node = reset($agaRegionalNodes);
            $insId = $node['ins_id'];
            $insData = $this->describeInstance($region, $insId);
            if ($insData) {
                $insType = $insData['InstanceType'];

                Log::info("get current instance from aga: {$insType}");
            }

            $amount = count($agaRegionalNodes);
        } else {
            $currentVersion = $this->route53->getCurrentVersion();

            //也返回ins_id，方便关联旧eip
            $r53RegionalNodes = $this->route53->getNodesByRegion($region, true);

            $node = reset($r53RegionalNodes);
            $insIp = $node['ipv4'];
            $insData = $this->findInstanceByIP($region, $insIp);
            if ($insData) {
                $insType = $insData['InstanceType'];

                Log::info("get current instance from route53: {$insType}");
            }

            $amount = count($r53RegionalNodes);
        }

        if ($amount <= 0) {
            Log::error("invalid instance amount: {$amount}");
            return;
        }

        if (!$insType) {
            Log::error("unable to get current instance type in region {$region}");
            return;
        }

        $currentKey = array_search($insType, $this->verticalScaleInstypes);
        if ($currentKey === false) {
            Log::error("unable to find current instance type {$insType} location in types: " . implode(',', $this->verticalScaleInstypes));
            return;
        }

        $targetKey = $currentKey - 1;
        $targetInsType = $this->verticalScaleInstypes[$targetKey] ?? null;
        if (!$targetInsType) {
            //到顶了
            Log::error("unable to get target instance type based on current type {$insType}, types: " . implode(',', $this->verticalScaleInstypes));
            return;
        }

        if ($this->opLocked()) {
            return;
        }

        $this->lockOp("scale-down");

        //确定只有locked by self
        if (!$this->opLockedBy("scale-down")) {
            if (file_exists($this->opLockFile)) {
                $lockedBy = file_get_contents($this->opLockFile);
                Log::info("locked by another op: {$lockedBy}, skip");
            }
            return;
        }

        Log::info("### scale down in region:{$region}, amount: {$amount}, version: {$currentVersion}, current instance type: {$insType}, target instance type: {$targetInsType} ###");

        //启动新机器
        $insList = [];
        for ($i = 1; $i <= $amount; $i++) {
            $insData = $this->launchNode($region, $currentVersion, $targetInsType);
            if (!$insData) {
                Log::error("failed to deploy collector");
                return false;
            }

            $insList[] = $insData;
        }

        //等待app ready
        $ret = $this->waitAppReady(array_column($insList, 'ipv4'));
        if (!$ret) {
            $this->unlockOp();
            return false;
        }

        if ($this->config['r53_zones']) {
            //用旧eip

            if (!$r53RegionalNodes) {
                $r53RegionalNodes = $this->route53->getNodesByRegion($region, true);
            }

            foreach ($r53RegionalNodes as $idx => $rnode) {
                //直接用旧的eip重新关联ec2即可，无需更改route53
                $oldEIP = $rnode['ipv4'];
                $newInsId = $insList[$idx] ?? null;
                if (!$newInsId || !isset($newInsId['ins_id'])) {
                    Log::error("can not get new instance id according to route53 and inslist, region nodes: " . json_encode($r53RegionalNodes, JSON_UNESCAPED_SLASHES) . ', insList: ' . json_encode($insList, JSON_UNESCAPED_SLASHES) . ', idx: ' . $idx);
                    continue;
                }
                $newInsId = $newInsId['ins_id'];

                $allocateId = $this->getAllocateID($region, $oldEIP);
                if (!$allocateId) {
                    Log::error("Failed to get allocate id from EIP {$oldEIP}");
                    continue;
                }

                //关联新机器
                $ret = $this->associateEIP($region, $newInsId, $allocateId);
                if (!$ret) {
                    Log::error("Failed to associate EIP {$oldEIP} with instance {$newInsId}");
                }
            }
        }

        if ($this->config['aga_arns']) {
            $insIds = array_column($insList, 'ins_id');
            $this->aga->replaceNodes($region, $insIds);
        }

        $this->unlockOp();
    }

    /**
     * 健康监控
     *
     * @param [type] $intervalS 检测时间间隔
     * @param [type] $failThreshold 失败指标的连续次数
     * @return void
     */
    public function monitor($intervalS, $failThreshold, $maxCheckAttempts)
    {
        if ($intervalS < 10) {
            Log::error("interval too smal, should be > 10, current: {$intervalS}");
            return;
        }

        if ($this->opLocked()) {
            return;
        }

        $startTime = time();

        if ($this->config['r53_zones']) {
            //优先用route53中的ip作为监控目标，避免aga中的机器IP再发布版本的时候变化
            $regionNodes = $this->route53->getAllNodes(true);
        } else {
            //用aga中的ip作为监控目标
            $regionNodes = $this->aga->getAllNodes(true);
        }

        list($healthCheckDomain, $healthCheckPath) = explode('/', $this->config['health_check'], 2);

        foreach ($regionNodes as $region => $nodeList) {
            $unhealthyNodes = [];

            foreach ($nodeList as $node) {
                if ($this->opLocked()) {
                    return;
                }

                $nodeIp = $node['ipv4'];

                //不健康的次数
                $unHealthyRets = 0;

                //检查次数，一次最多检查5次
                $checkAttempts = 0;
                do {
                    $ch = curl_init("https://{$healthCheckDomain}/{$healthCheckPath}");

                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_HEADER => false,
                        CURLOPT_FORBID_REUSE => false,
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_FAILONERROR => false, //ignore http status code
                        CURLOPT_RESOLVE => ["{$healthCheckDomain}:443:{$nodeIp}"]
                    ]);

                    $checkAttempts++;

                    try {
                        $body = curl_exec($ch);
                    } catch (\Exception $e) {
                        $chInfo = curl_getinfo($ch);
                        curl_close($ch);
                        $unHealthyRets++;

                        sleep($intervalS);
                        continue;
                    }

                    //有错误产生
                    $errNo = curl_errno($ch);
                    if ($errNo) {
                        $chInfo = curl_getinfo($ch);
                        curl_close($ch);
                        $unHealthyRets++;

                        sleep($intervalS);
                        continue;
                    }

                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $chInfo = curl_getinfo($ch);
                    curl_close($ch);
                    if ($httpCode != 200) {
                        $unHealthyRets++;
                    }

                    //debug log
                    //Log::info("node {$node['ins_id']} ({$node['ipv4']}) in {$region}, {$unHealthyRets} / {$failThreshold}, {$checkAttempts} / {$maxCheckAttempts}");

                    if ($unHealthyRets >= $failThreshold) {
                        //认为不健康，跳出循环
                        break;
                    }

                    sleep($intervalS);
                } while ($checkAttempts < $maxCheckAttempts && $unHealthyRets < $failThreshold);

                if ($unHealthyRets >= $failThreshold) {
                    //该节点不健康，整个区域都升级
                    Log::error("Unhealthy node {$node['ins_id']} ({$node['ipv4']}) in {$region}");

                    $unhealthyNodes[] = "Unhealthy node {$node['ins_id']} ({$node['ipv4']}) in {$region}";

                    break;
                }
            }

            if ($unhealthyNodes) {
                //节点不健康
                $nodeContent = array_map(function ($item) {
                    return "<p>{$item}</p>";
                }, $unhealthyNodes);

                $content = implode('', $nodeContent) . "<p>Start scale up<p>";
                $this->sendAlarmEmail('Unhealthy nodes, start scale up', $content);

                //直接升级
                $this->scaleUp($region);

                $content = implode('', $nodeContent) . "<p>End scale up<p>";
                $this->sendAlarmEmail('Unhealthy nodes, end scale up', $content);
            }
        }

        $timeUsed = time() - $startTime;

        //Log::info("Finish monitor, time used: {$timeUsed}s");
    }

    /**
     * 根据cpu负载自动扩容和缩容
     *
     * @return void
     */
    public function autoScale()
    {
        if (!$this->config['auto_scale_cpu_metric'] || !$this->config['auto_scale_cpu_threshold']) {
            return;
        }

        if ($this->opLocked()) {
            return;
        }

        $this->lockOp("auto-scale");

        //确定只有locked by self
        if (!$this->opLockedBy("auto-scale")) {
            if (file_exists($this->opLockFile)) {
                $lockedBy = file_get_contents($this->opLockFile);
                Log::info("locked by another op: {$lockedBy}, skip");
            }
            return;
        }

        //debug log
        //Log::info("start watching auto scale");

        $startTime = time();

        if ($this->config['r53_zones']) {
            //优先用route53中的ip作为监控目标，避免发布版本的时候aga中的机器发生变化
            $regionNodes = $this->route53->getAllNodes(true);
        } else {
            //用aga中的ip作为监控目标
            $regionNodes = $this->aga->getAllNodes(true);
        }

        $cwClient = new \Aws\CloudWatch\CloudWatchClient(array_merge($this->defaultAwsConfig, [
            'region' => $this->config['auto_scale_cpu_metric']['region'],
            'version' => '2010-08-01'
        ]));

        foreach ($regionNodes as $region => $nodeList) {
            //该地区总共节点数
            $totalNodes = count($nodeList);

            //低负载节点
            $lowLoadNodes = 0;

            $currentCPUTotal = 0;

            foreach ($nodeList as $node) {
                $insId = $node['ins_id'];

                //查询该instance的cpu使用情况
                $nowTs = time();
                try {
                    $ret = $cwClient->getMetricStatistics(array_merge($this->config['auto_scale_cpu_metric']['filter'], [
                        'Dimensions' => [
                            [
                                'Name' => 'InstanceId',
                                'Value' => $insId
                            ],
                        ],
                        'StartTime' => $nowTs - (12 * $this->config['auto_scale_cpu_metric']['filter']['Period']),
                        'EndTime' => $nowTs,
                        'Statistics' => ['Average'],
                        'Unit' => 'Percent'
                    ]));
                } catch (\Throwable $th) {
                    Log::error($th->getMessage());
                    continue;
                }

                $dataPoints = $ret['Datapoints'];
                //倒序，最近的就是第一个
                usort($dataPoints, function ($a, $b) {
                    if ($a['Timestamp'] == $b['Timestamp']) {
                        return 0;
                    }
                    return ($a['Timestamp'] < $b['Timestamp']) ? 1 : -1;
                });

                //取最近的6个数据点
                $dataPoints = array_slice($dataPoints, 0, 6);

                //该instance的平均cpu
                $totalCpuArr = array_column($dataPoints, 'Average');
                $totalCpu = array_sum($totalCpuArr);
                $currentAvgCpu = number_format($totalCpu / count($totalCpuArr), 2, '.', '');

                //加到总cpu
                $currentCPUTotal += $currentAvgCpu;

                //debug log
                //Log::info("nodes metrics in {$region}, datapoints:" . count($totalCpuArr) . ", total datapoints cpu: {$totalCpu}%, avg cpu: {$currentAvgCpu}% ");

                if ($currentAvgCpu > 0 && $currentAvgCpu < $this->config['auto_scale_cpu_threshold'][0]) {
                    //缩容
                    $lowLoadNodes++;
                }
            }

            //当前地区的平均cpu
            $currentCPUAvg = $currentCPUTotal / $totalNodes;

            //debug log
            //Log::info("nodes metrics in {$region}, current avg. cpu: {$currentCPUAvg}%");

            $scaleLargeFlagFile = "/tmp/mbyte-lbops-{$this->config['module']}-scale-large.flag";
            $lastScalelargeTime = file_exists($scaleLargeFlagFile) ? file_get_contents($scaleLargeFlagFile) : 0;
            if (time() - $lastScalelargeTime > 300 && $currentCPUAvg > $this->config['auto_scale_cpu_threshold'][1]) {
                //扩容（要快），需要记录上次扩容至少5分钟，方便新扩容的机器生效
                Log::info("start scale up, nodes metrics in {$region}, current avg. cpu: {$currentCPUAvg}%, nodes: {$totalNodes}, threshold: {$this->config['auto_scale_cpu_threshold'][1]}%");

                $content = <<<STRING
<p><strong>nodes in {$region} is on high load, current avg. cpu {$currentCPUAvg}%, nodes: {$totalNodes}</strong><p>
<p>Start scale up<p>
STRING;
                $this->sendAlarmEmail("High cpu load {$currentCPUAvg}%, start scale up", $content);

                $this->scaleUp($region);

                $content = <<<STRING
<p><strong>nodes in {$region} is on high load, current avg. cpu {$currentCPUAvg}%, nodes: {$totalNodes}</strong><p>
<p>End scale up<p>
STRING;
                $this->sendAlarmEmail("High cpu load {$currentCPUAvg}%, end scale up", $content);

                file_put_contents($scaleLargeFlagFile, time());
            }

            if ($lowLoadNodes == $totalNodes) {
                //全部低负载，缩容（要慢）
                $tmpNode = reset($nodeList);
                $tmpInsId = $tmpNode['ins_id'];
                $tmpInsData = $this->describeInstance($region, $tmpInsId);
                $insType = $tmpInsData['InstanceType'] ?? null;

                $scaleSmallFlagFile = "/tmp/mbyte-lbops-{$this->config['module']}-scale-small.flag";
                $lastScalesmallTime = file_exists($scaleSmallFlagFile) ? file_get_contents($scaleSmallFlagFile) : 0;
                if (time() - $lastScalesmallTime > 1800 && $insType != $this->verticalScaleInstypes[0]) {
                    //不是最小的，缩容
                    Log::info("start scale down, nodes metrics in {$region}, current avg. cpu: {$currentCPUAvg}%, threshold: {$this->config['auto_scale_cpu_threshold'][0]}%");

                    $content = <<<STRING
<p><strong>nodes in {$region} is on low load, current avg. cpu {$currentCPUAvg}%</strong><p>
<p>Start scale down<p>
STRING;
                    $this->sendAlarmEmail('Low cpu load, start scale down', $content);

                    $this->scaleDown($region);

                    $content = <<<STRING
<p><strong>nodes in {$region} is on low load, current avg. cpu {$currentCPUAvg}%</strong><p>
<p>End scale down<p>
STRING;
                    $this->sendAlarmEmail('Low cpu load, end scale down', $content);

                    file_put_contents($scaleSmallFlagFile, time());
                }

                //重新获取一次时间，避免刚scale down完就scale in
                $lastScalesmallTime = file_exists($scaleSmallFlagFile) ? file_get_contents($scaleSmallFlagFile) : 0;

                if (time() - $lastScalesmallTime > 1800 && $totalNodes > 1) {
                    //距离上次scale down/in超过半小时，尝试scale in
                    Log::info("start scale in, nodes metrics in {$region}, current avg. cpu: {$currentCPUAvg}%, threshold: {$this->config['auto_scale_cpu_threshold'][0]}%");

                    $content = <<<STRING
<p><strong>nodes in {$region} is on low load, current avg. cpu {$currentCPUAvg}%</strong><p>
<p>Start scale in<p>
STRING;
                    $this->sendAlarmEmail('Low cpu load, start scale in', $content);

                    $this->scaleIn($region);

                    $content = <<<STRING
<p><strong>nodes in {$region} is on low load, current avg. cpu {$currentCPUAvg}%</strong><p>
<p>End scale in<p>
STRING;
                    $this->sendAlarmEmail('Low cpu load, end scale in', $content);

                    file_put_contents($scaleSmallFlagFile, time());
                }
            }
        }

        $usedTime = time() - $startTime;

        //debug log
        //Log::info("end watching auto scale, time used: {$usedTime}s");
    }
}
