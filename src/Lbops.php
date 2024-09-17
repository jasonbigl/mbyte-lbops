<?php

namespace Mbyte\Lbops;

class Lbops extends Basic
{
    //竖向扩容机器路线
    public $scaleUpInsTypes = [
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

        $this->lockOp();

        $startTime = time();

        //当前版本和机器类型
        $insType = $currentVersion = null;
        if ($this->config['r53_zones']) {
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
        } else {
            $currentVersion = $this->aga->getCurrentVersion();

            $region = reset($this->config['regions']);
            $regionNodes = $this->aga->getNodesByRegion($region);
            $node = reset($regionNodes);
            $insId = $node['ins_id'];
            $insData = $this->describeInstance($region, $insId);
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
            $insIds = array_column($insList, 'ins_id');
            $ipv4List = array_column($insList, 'ipv4');

            //等待app ready
            $ret = $this->waitAppReady($ipv4List);
            if (!$ret) {
                continue;
            }

            //将新启动的ec2部署到aga中
            if ($this->config['aga_arns']) {
                $this->aga->replaceNodes($region, $insIds);
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
        }

        //update tag
        $this->aga->updateTags($version);
        $this->route53->updateTags($version);

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
    public function clean($ignoreRoute53DeployTime = false)
    {
        if ($this->config['r53_zones'] && !$ignoreRoute53DeployTime) {
            //wait at least 40 minutes if route53
            //获取上次发布时间
            $lastDeployDatetime = $this->route53->getLastDeployDateTime();
            if (!$lastDeployDatetime) {
                return;
            }

            $lastDeployTimestamp = \DateTime::createFromFormat('Y-m-d H:i:s', $lastDeployDatetime, new \DateTimeZone('Asia/Shanghai'))->getTimestamp();

            if (time() - $lastDeployTimestamp <= 2400) {
                //less then 40 minutes
                Log::error("the last route 53 deployment is less then 40 minutes, skip clean");
                return;
            }
        }

        if ($this->opLocked()) {
            return;
        }

        $this->lockOp();

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
                    if (!in_array($eipAddr['PublicIp'], $agaResvIps) && !in_array($eipAddr['PublicIp'], $r53ResvIps)) {
                        $cleanEIPs[] = $eipAddr['PublicIp'];
                    }
                }

                if ($cleanEIPs) {
                    $this->cleanEIPs($region, $cleanEIPs);
                }
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
                        if (!in_array($ins['InstanceId'], $agaResvInsIds) && !in_array($ins['InstanceId'], $r53ResvInsIds))
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
        if ($this->config['r53_zones']) {
            //优先用route53
            $currentVersion = $this->route53->getCurrentVersion();
        } else {
            $currentVersion = $this->aga->getCurrentVersion();
        }

        if ($this->opLocked()) {
            return;
        }

        $this->lockOp();

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
        if ($this->config['r53_zones']) {
            $nodesList = $this->route53->getNodesByRegion($region, true);
        } else {
            $nodesList = $this->aga->getNodesByRegion($region, true);
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

        $this->lockOp();

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

            Log::info("updating aga in {$region}: " . implode(',', $insIdList));

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
        if ($this->config['r53_zones']) {
            //优先用route53
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
        } else {
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
        }

        if ($amount <= 0) {
            Log::error("invalid instance amount: {$amount}");
            return;
        }

        if (!$insType) {
            Log::error("unable to get current instance type in region {$region}");
            return;
        }

        $currentKey = array_search($insType, $this->scaleUpInsTypes);
        if ($currentKey === false) {
            Log::error("unable to find current instance type {$insType} location in types: " . implode(',', $this->scaleUpInsTypes));
            return;
        }

        $targetKey = $currentKey + 1;
        $targetInsType = $this->scaleUpInsTypes[$targetKey] ?? null;
        if (!$targetInsType) {
            //到顶了
            Log::error("unable to get target instance type based on current type {$insType}, types: " . implode(',', $this->scaleUpInsTypes));
            return;
        }

        if ($this->opLocked()) {
            return;
        }

        $this->lockOp();

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
        if ($this->config['r53_zones']) {
            //优先用route53
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
        } else {
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
        }

        if ($amount <= 0) {
            Log::error("invalid instance amount: {$amount}");
            return;
        }

        if (!$insType) {
            Log::error("unable to get current instance type in region {$region}");
            return;
        }

        $currentKey = array_search($insType, $this->scaleUpInsTypes);
        if ($currentKey === false) {
            Log::error("unable to find current instance type {$insType} location in types: " . implode(',', $this->scaleUpInsTypes));
            return;
        }

        $targetKey = $currentKey - 1;
        $targetInsType = $this->scaleUpInsTypes[$targetKey] ?? null;
        if (!$targetInsType) {
            //到顶了
            Log::error("unable to get target instance type based on current type {$insType}, types: " . implode(',', $this->scaleUpInsTypes));
            return;
        }

        if ($this->opLocked()) {
            return;
        }

        $this->lockOp();

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
    public function monitor($intervalS, $failThreshold)
    {
        if ($intervalS < 10) {
            Log::error("interval too smal, should be > 10, current: {$intervalS}");
            return;
        }

        Log::info("start monitor health");
        $startTime = time();

        if ($this->config['r53_zones']) {
            //用route53中的ip作为监控目标
            $regionNodes = $this->route53->getAllNodes(true);
        } else {
            //用aga中的ip作为监控目标
            $regionNodes = $this->aga->getAllNodes(true);
        }

        list($healthCheckDomain, $healthCheckPath) = explode('/', $this->config['health_check'], 2);

        Log::info("start monitoring nodes: " . json_encode($regionNodes, JSON_UNESCAPED_SLASHES));

        foreach ($regionNodes as $region => $nodeList) {
            foreach ($nodeList as $node) {
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
                        continue;
                    }

                    //有错误产生
                    $errNo = curl_errno($ch);
                    if ($errNo) {
                        $chInfo = curl_getinfo($ch);
                        curl_close($ch);
                        $unHealthyRets++;
                        continue;
                    }

                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $chInfo = curl_getinfo($ch);
                    curl_close($ch);
                    if ($httpCode != 200) {
                        $unHealthyRets++;
                    }

                    if ($unHealthyRets >= $failThreshold) {
                        //认为不健康，跳出循环
                        break;
                    }

                    sleep($intervalS);
                } while ($checkAttempts <= 5 && $unHealthyRets < $failThreshold);

                if ($unHealthyRets >= $failThreshold) {
                    Log::error("Unhealthy node {$node['ins_id']} ({$node['ipv4']}) in {$region}, start scale up");

                    //该节点不健康
                    $content = <<<STRING
<p><strong>{$node['ins_id']} ({$node['ipv4']}) in {$region} is not healthy</strong><p>
<p>Start scale up<p>
STRING;
                    $this->sendAlarmEmail('Unhealthy node, start scale up', $content);

                    //直接升级
                    $this->scaleUp($region);

                    $content = <<<STRING
<p><strong>{$node['ins_id']} ({$node['ipv4']}) in {$region} is not healthy</strong><p>
<p>End scale up<p>
STRING;
                    $this->sendAlarmEmail('Unhealthy node, end scale up', $content);

                    Log::error("Unhealthy node {$node['ins_id']} ({$node['ipv4']}) in {$region}, end scale up");
                }
            }
        }

        $usedTime = time() - $startTime;

        Log::info("finished monitor health, time used: {$usedTime}s");
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

        Log::info("start watching auto scale");
        $startTime = time();

        if ($this->config['r53_zones']) {
            //用route53中的ip作为监控目标
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

            $currentCPUTotal = $lastCPUTotal = 0;

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
                        'StartTime' => $nowTs - (6 * $this->config['auto_scale_cpu_metric']['filter']['Period']), //最近6个节点数据
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

                $currentCPU = $dataPoints[0]['Average'] ?? 0;

                $currentCPUTotal += $currentCPU;
                $lastCPUTotal += $dataPoints[1]['Average'] ?? $currentCPU;

                if ($currentCPU > 0 && $currentCPU < $this->config['auto_scale_cpu_threshold'][0]) {
                    //缩容
                    $lowLoadNodes++;
                }
            }

            $currentCPUAvg = $currentCPUTotal / $totalNodes;
            $lastCPUAvg = $lastCPUTotal / $totalNodes;

            //提升幅度
            $increaseRate = $lastCPUAvg > 0 ? $currentCPUAvg / $lastCPUAvg : 0;

            if ($currentCPUAvg > $this->config['auto_scale_cpu_threshold'][1]) {
                //扩容
                $content = <<<STRING
<p><strong>nodes in {$region} is on high load, current avg. cpu {$currentCPUAvg}%</strong><p>
<p>Start scale up<p>
STRING;
                $this->sendAlarmEmail('High cpu load, start scale up', $content);

                $this->scaleUp($region);

                $content = <<<STRING
<p><strong>nodes in {$region} is on high load, current avg. cpu {$currentCPUAvg}%</strong><p>
<p>End scale up<p>
STRING;
                $this->sendAlarmEmail('High cpu load, end scale up', $content);
            }

            if ($lowLoadNodes == $totalNodes) {
                //全部低负载，缩容
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
            }
        }

        $usedTime = time() - $startTime;

        Log::info("end watching auto scale, time used: {$usedTime}s");
    }
}
