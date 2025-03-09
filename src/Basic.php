<?php

namespace Mbyte\Lbops;

use Mbyte\Lbops\Balancer\Aga;
use Mbyte\Lbops\Balancer\Route53;

class Basic
{
    /**
     * 默认aws配置
     *
     * @var [type]
     */
    public $defaultAwsConfig;

    /**
     * 配置
     *
     * @var array
     */
    public $config = [];

    /**
     * aga类
     *
     * @var [type]
     */
    public $aga;

    /**
     * route53类
     *
     * @var [type]
     */
    public $route53;

    /**
     * lock file location
     *
     * @var string
     */
    public $opLockFile;

    /**
     * Undocumented function
     *
     * @param [type] $config
     */
    public function __construct($config)
    {
        if (!$config['module']) {
            throw new \Exception('config.module is required');
        }

        if (!$config['regions']) {
            throw new \Exception('config.regions is required');
        }

        if (!$config['aws_key']) {
            throw new \Exception('config.aws_key is required');
        }

        if (!$config['aws_secret']) {
            throw new \Exception('config.aws_secret is required');
        }

        $defaultConfig = [
            'module' => '', //系统模块名，区分多系统 !!!必须
            'regions' => [], //发布的地区 !!!必须
            'aws_key' => '', //aws key !!!必须
            'aws_secret' => '', //aws secret !!!必须
            'health_check' => '', //健康检查的路径，如api.domain.com/.devops/health
            'launch_tpl' => '', //创建ec2的模板
            's3_startup_script' => '', //开机脚本的s3位置
            's3_startup_script_region' => '', //开机脚本的s3桶的区域

            'aga_arns' => [], //需要发布的global accelerator列表，留空表示不发布
            'r53_zones' => [], //需要发布的route53域名区，包含zone_id和domain, 留空表示不发布

            'r53_subdomain' => '', // route53中的域名前缀，比如 *.domain.com就是*

            'auto_scale_cpu_metric' => [],
            'auto_scale_cpu_threshold' => [], //缩容和扩容百分比

            'loggers' => [], //日志记录
        ];

        $this->config = array_merge($defaultConfig, $config);

        $this->aga = new Aga($this->config);
        $this->route53 = new Route53($this->config);

        //记录日志
        if ($this->config['loggers']) {
            Log::setLoggers($this->config['loggers']);
        }

        //默认的aws 配置
        $this->defaultAwsConfig = [
            'credentials' => [
                'key' => $this->config['aws_key'],
                'secret' => $this->config['aws_secret'],
            ],
            'http' => [
                'connect_timeout' => 5,
                'timeout' => 15,
                'verify' => false, //Disable SSL/TLS verification
            ],
            'retries' => 3,
            'version' => 'latest',
        ];

        $this->opLockFile = "/tmp/mbyte-lbops-{$this->config['module']}-op.lock";
    }

    /**
     * Undocumented function
     *
     * @param [type] $region
     * @param [type] $version
     * @param [type] $assignEIP 指定一个eip，而不是分配新eip
     * @param string $insType
     * @return string
     */
    function launchNode($region, $version, $insType = 't4g.small', $startupEnv = '')
    {
        #create instance
        $instance = $this->launchEc2($region, $version, $insType, $startupEnv);
        if (!$instance) {
            return false;
        }

        $instanceId = $instance['InstanceId'];

        $ret = $this->waitInstanceReady($region, $instanceId);
        if (!$ret) {
            return false;
        }

        $instanceInfo = $this->describeInstance($region, $instanceId);
        if (!$instanceInfo) {
            Log::error("Failed to get instance info from: {$instanceId}");
            return false;
        }
        $ipv4 = $instanceInfo['PublicIpAddress'];
        $ipv6 = $instanceInfo['Ipv6Address'];

        return [
            'ins_id' => $instanceId,
            'ipv4' => $ipv4,
            'ipv6' => $ipv6
        ];
    }

    /**
     * 启动新机器ec2
     *
     * @param [type] $region
     * @param [type] $regionAttr
     * @return void
     */
    public function launchEc2($region, $version, $insType, $startupEnv = '')
    {
        Log::info("launching ec2 instance with version {$version} in {$region}...");

        $ec2Client = new \Aws\Ec2\Ec2Client(array_merge($this->defaultAwsConfig, [
            'region' => $region,
            'version' => '2016-11-15'
        ]));

        $userData = <<<STRING
#!/bin/bash

aws s3 cp {$this->config['s3_startup_script']} /tmp/startup.sh --region={$this->config['s3_startup_script_region']}

chmod +x /tmp/startup.sh

DEPLOY_FILE={$version}.tgz {$startupEnv} /tmp/startup.sh > /tmp/startup.log
STRING;

        try {
            $now = new \DateTime('now', new \DateTimeZone('Asia/Shanghai'));
            $deployDatetime = $now->format('Y-m-d H:i:s');

            $insOptions = [
                'MaxCount' => 1,
                'MinCount' => 1,
                'InstanceType' => $insType,
                'LaunchTemplate' => [
                    'LaunchTemplateName' => $this->config['launch_tpl'],
                    'Version' => '$Default',
                ],

                'DisableApiStop' => false,
                'DisableApiTermination' => false,

                'Monitoring' => [
                    'Enabled' => false,
                ],
                'TagSpecifications' => [
                    [
                        'ResourceType' => 'instance',
                        'Tags' => [
                            [
                                'Key' => 'Name',
                                'Value' => "{$this->config['module']}-{$version}",
                            ],
                            [
                                'Key' => 'Module',
                                'Value' => $this->config['module'],
                            ],
                            [
                                'Key' => 'Last Change DateTime',
                                'Value' => $deployDatetime,
                            ],
                            [
                                'Key' => 'Deploy Version',
                                'Value' => $version,
                            ]
                        ],
                    ],
                ],
                'UserData' => base64_encode($userData),
            ];

            if (stripos($insType, 't') === 0) {
                //cpu credits unlimited
                $insOptions['CreditSpecification'] = [
                    'CpuCredits' => 'unlimited'
                ];
            }

            $ret = $ec2Client->runInstances($insOptions);
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            return false;
        }

        $instance = $ret['Instances'][0];

        Log::info("ec2 instance launched successfully: {$instance['InstanceId']}");

        return $instance;
    }

    /**
     * 等待instance创建完成
     */
    function waitInstanceReady($region, $instanceId)
    {
        $ec2Client = new \Aws\Ec2\Ec2Client(array_merge($this->defaultAwsConfig, [
            'region' => $region,
            'version' => '2016-11-15'
        ]));

        //wait until instance is running
        Log::info("wait until instance {$instanceId} is running...");

        $startTime = time();

        $ec2State = null;
        do {

            sleep(1);

            try {
                $ec2State = $ec2Client->describeInstanceStatus([
                    'IncludeAllInstances' => true,
                    'InstanceIds' => [$instanceId],
                ]);
            } catch (\Throwable $th) {
                Log::error($th->getMessage());
                continue;
            }

            $ec2State = $ec2State['InstanceStatuses'][0]['InstanceState']['Name'] ?? '';
        } while ($ec2State != 'running' && time() - $startTime <= 60);

        Log::info("instance {$instanceId} ready, state: {$ec2State}");

        return true;
    }

    /**
     * 获取instance详情
     */
    public function describeInstance($region, $instanceId)
    {

        $ec2Client = new \Aws\Ec2\Ec2Client(array_merge($this->defaultAwsConfig, [
            'region' => $region,
            'version' => '2016-11-15'
        ]));

        try {
            $ret = $ec2Client->describeInstances([
                'InstanceIds' => [$instanceId]
            ]);
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            return [];
        }

        return $ret['Reservations'][0]['Instances'][0] ?? [];
    }

    /**
     * 等待所有instance中的app启动完成
     *
     * @param [type] $ipList
     * @return void
     */
    public function waitAppReady($ipList)
    {
        Log::info("waiting app to be ready, ips: " . implode(',', $ipList));

        $startTime = time();

        $maxAttempts = 30;

        list($healthCheckDomain, $healthCheckPath) = explode('/', $this->config['health_check'], 2);

        //启动完成的nodes
        $readyNodes = [];

        foreach ($ipList as $ip) {
            $checkAttempts = 0;

            do {
                $ch = curl_init("https://{$healthCheckDomain}/{$healthCheckPath}");

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_HEADER => false,
                    CURLOPT_FORBID_REUSE => false,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_FAILONERROR => false, //ignore http status code
                    CURLOPT_RESOLVE => ["{$healthCheckDomain}:443:{$ip}"]
                ]);

                $checkAttempts++;

                try {
                    $body = curl_exec($ch);
                } catch (\Exception $e) {
                    $chInfo = curl_getinfo($ch);
                    curl_close($ch);

                    sleep(5);

                    continue;
                }

                //有错误产生
                $errNo = curl_errno($ch);
                if ($errNo) {
                    $chInfo = curl_getinfo($ch);
                    curl_close($ch);

                    sleep(5);

                    continue;
                }

                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $chInfo = curl_getinfo($ch);
                curl_close($ch);

                if ($httpCode === 200) {
                    //启动完成
                    $readyNodes[] = $ip;

                    Log::info("app in instances {$ip} is ready after {$checkAttempts} checks");

                    break;
                }

                sleep(5);
            } while ($checkAttempts <= $maxAttempts);
        }

        if (count($readyNodes) != count($ipList)) {
            Log::error("app in instances is not ready after {$maxAttempts} checks, ready nodes: " . implode(',', $readyNodes) . ", all nodes:" . implode(',', $ipList));
            return false;
        }

        $timeUsed = time() - $startTime;

        Log::info("app in instances {$ip} is ready, time used: {$timeUsed}s");

        return true;
    }

    /**
     * 查询EIP的分配ID
     */
    function getAllocateID($region, $eip)
    {
        $ec2Client = new \Aws\Ec2\Ec2Client(array_merge($this->defaultAwsConfig, [
            'region' => $region,
            'version' => '2016-11-15'
        ]));

        //allocate eip
        try {
            $ret = $ec2Client->describeAddresses([
                'Filters' => [
                    [
                        'Name' => 'public-ip',
                        'Values' => [$eip],
                    ],
                ],
            ]);
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            return false;
        }

        $allocateId = $ret['Addresses'][0]['AllocationId'] ?? '';

        Log::info("Get allocate id from existing EIP {$eip}: {$allocateId}");

        return $allocateId;
    }

    /**
     * 分配EIP
     */
    function allocateNewEIP($region, $version)
    {
        $now = new \DateTime('now', new \DateTimeZone('Asia/Shanghai'));
        $deployTimestamp = $now->getTimestamp();
        $deployDatetime = $now->format('Y-m-d H:i:s');

        $ec2Client = new \Aws\Ec2\Ec2Client(array_merge($this->defaultAwsConfig, [
            'region' => $region,
            'version' => '2016-11-15'
        ]));

        //allocate eip
        try {
            $ret = $ec2Client->allocateAddress([
                'Domain' => 'vpc',
                'TagSpecifications' => [
                    [
                        'ResourceType' => 'elastic-ip',
                        'Tags' => [
                            [
                                'Key' => 'Name',
                                'Value' => "{$this->config['module']}-{$version}",
                            ],
                            [
                                'Key' => 'Module',
                                'Value' => $this->config['module'],
                            ],
                            [
                                'Key' => 'Last Change DateTime',
                                'Value' => $deployDatetime,
                            ],
                            [
                                'Key' => 'Deploy Version',
                                'Value' => $version,
                            ]
                        ]
                    ]
                ]
            ]);
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            return false;
        }

        $eip = $ret['PublicIp'];
        $allocId = $ret['AllocationId'];

        Log::info("allocate new eip:{$eip}, alloc id: {$allocId}");

        return [
            $allocId,
            $eip
        ];
    }

    /**
     * 关联eip
     */
    function associateEIP($region, $instanceId, $allocId)
    {
        $ec2Client = new \Aws\Ec2\Ec2Client(array_merge($this->defaultAwsConfig, [
            'region' => $region,
            'version' => '2016-11-15'
        ]));

        //associate eip with instance
        try {
            $ret = $ec2Client->associateAddress([
                'AllocationId' => $allocId,
                'InstanceId' => $instanceId,
                'AllowReassociation' => true,
            ]);
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
            return false;
        }

        Log::info("associate eip {$allocId} with instance {$instanceId} successfully");

        return true;
    }

    /**
     * 根据IP找到instance
     */
    function findInstanceByIP($region, $ip)
    {
        $ec2Client = new \Aws\Ec2\Ec2Client(array_merge($this->defaultAwsConfig, [
            'region' => $region,
            'version' => '2016-11-15'
        ]));

        $ret = $ec2Client->describeInstances([
            'Filters' => [
                [
                    'Name' => 'ip-address',
                    'Values' => [$ip]
                ]
            ]
        ]);

        return $ret['Reservations'][0]['Instances'][0] ?? [];
    }

    /**
     * 清理elastic eips
     */
    function cleanEIPs($region, $cleanEIPs)
    {
        if (!$cleanEIPs) {
            return;
        }

        Log::info("Start cleaning eips in region {$region}: " . implode(',', $cleanEIPs));

        $ec2Client = new \Aws\Ec2\Ec2Client(array_merge($this->defaultAwsConfig, [
            'region' => $region,
            'version' => '2016-11-15'
        ]));

        foreach ($cleanEIPs as $eip) {
            //eip
            try {
                $ret = $ec2Client->describeAddresses([
                    'Filters' => [
                        [
                            'Name' => 'public-ip',
                            'Values' => [$eip]
                        ]
                    ]
                ]);
            } catch (\Throwable $th) {
                Log::error($th->getMessage());
            }

            $eipInfo = null;
            if ($ret && $ret['Addresses']) {
                $eipInfo = $ret['Addresses'][0] ?? [];
            }

            if ($eipInfo) {
                Log::info("cleaning eip {$eip}...");

                if ($eipInfo['AssociationId']) {
                    try {
                        $ec2Client->disassociateAddress([
                            'AssociationId' => $eipInfo['AssociationId'],
                        ]);
                        Log::info("disassociate {$eipInfo['AssociationId']} success");
                    } catch (\Exception $e) {
                        Log::error("disassociate {$eipInfo['AssociationId']} failed: " . $e->getMessage());
                    }
                }

                try {
                    $ec2Client->releaseAddress([
                        'AllocationId' => $eipInfo['AllocationId']
                    ]);
                    Log::info("release {$eipInfo['AllocationId']} success");
                } catch (\Exception $e) {
                    Log::error("release {$eipInfo['AllocationId']} failed: " . $e->getMessage());
                }

                Log::info("clean eip {$eip} successfully");
            } else {
                Log::error("Can not find eip info from {$eip}");
            }
        }

        return true;
    }

    /**
     * 清理ec2
     */
    function cleanInstances($region, $cleanInsIds)
    {
        if (!$cleanInsIds) {
            return;
        }

        $ec2Client = new \Aws\Ec2\Ec2Client(array_merge($this->defaultAwsConfig, [
            'region' => $region,
            'version' => '2016-11-15'
        ]));

        Log::info("Start cleaning ec2 instances: " . json_encode($cleanInsIds, JSON_UNESCAPED_SLASHES));

        foreach ($cleanInsIds as $insId) {
            Log::info("cleaning ec2 instance {$insId}...");

            //分别取消停止保护和删除保护，不能同时取消，否则api报错
            try {
                $ret = $ec2Client->modifyInstanceAttribute([
                    'DisableApiStop' => ['Value' => false],
                    'InstanceId' => $insId,
                ]);
            } catch (\Throwable $th) {
                Log::error("DisableApiStop failed: " . $th->getMessage());
            }

            try {
                $ret = $ec2Client->modifyInstanceAttribute([
                    'DisableApiTermination' => ['Value' => false],
                    'InstanceId' => $insId,
                ]);
            } catch (\Throwable $th) {
                Log::error("DisableApiTermination failed: " . $th->getMessage());
            }

            //terminate
            try {
                $ret = $ec2Client->terminateInstances([
                    'InstanceIds' => [$insId],
                ]);
            } catch (\Throwable $th) {
                Log::error($th->getMessage());
            }

            Log::info("ec2 instance {$insId} terminated successfully");
        }

        return true;
    }

    /**
     * 发送健康状况通知邮件
     *
     * @return void
     */
    public function sendAlarmEmail($subject, $content)
    {
        $sesV2Client = new \Aws\SesV2\SesV2Client([
            'credentials' => [
                'key' => $this->config['aws_key'],
                'secret' => $this->config['aws_secret'],
            ],
            'http' => [
                'connect_timeout' => 5,
                'timeout' => 15,
                'verify' => false, //Disable SSL/TLS verification
            ],
            'retries' => 3,
            'version' => '2019-09-27',
            'region' => 'us-east-1'
        ]);

        $sesV2Client->sendEmail([
            'Content' => [
                'Simple' => [
                    'Body' => [
                        'Html' => [
                            'Charset' => 'UTF-8',
                            'Data' => $content,
                        ],
                    ],
                    'Subject' => [
                        'Charset' => 'UTF-8',
                        'Data' => $subject,
                    ],
                ]
            ],
            'Destination' => [
                'BccAddresses' => [],
                'CcAddresses' => [],
                //注意这里只能用单个用户，如果用多个用户，每个用户都能看到其他收件人的地址
                'ToAddresses' => [
                    "Mr Lee <maxalarm@foxmail.com>",
                ],
            ],
            'FromEmailAddress' => 'Mtech Alarm <alarm@maxbytech.com>',
        ]);
    }

    /**
     * Undocumented function
     *
     * @return boolean
     */
    public function opLocked($currentOp = '')
    {
        if (file_exists($this->opLockFile) && file_get_contents($this->opLockFile) != 'n') {
            Log::info("trying {$currentOp} but lbops has been locked by another operation: " . file_get_contents($this->opLockFile) . ", skip");
            return true;
        }

        return false;
    }

    /**
     * lock operation
     *
     * @return void
     */
    public function lockOp($lockedBy = "common")
    {
        file_put_contents($this->opLockFile, $lockedBy);
    }

    /**
     * unlock operation
     *
     * @return void
     */
    public function unlockOp()
    {
        file_put_contents($this->opLockFile, 'n');
    }

    /**
     * 确定是否由特定的操作来lock
     *
     * @param [type] $op
     * @return void
     */
    public function opLockedBy($op)
    {
        $lockedBy = null;
        $attempts = 0;
        do {
            if (file_exists($this->opLockFile)) {
                $lockedBy = file_get_contents($this->opLockFile);
            }

            $attempts++;
            sleep(1);
        } while ($lockedBy == $op && $attempts <= 5);

        return $lockedBy == $op;
    }
}
