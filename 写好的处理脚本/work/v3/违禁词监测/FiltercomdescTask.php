<?php
/**
 * 过滤处理企业展厅信息
 * User: zyh
 * Date: 17/3/20
 * Time: 下午3:58
 */
namespace Xz\Taskwww\Cominfo;
use \Phalcon\CLI\Task;
use Phalcon\DI;
/**
 * Class FilterComDescTask
 * @package Xz\Taskwww\Cominfo
 */
class FiltercomdescTask extends Task {
    private $keywords = array("特供", "直供", "专供");//要检索的关键词
    private $relaKeyword = array("ZF","军队","政府","国务院","人民大会堂","首长","部队","警察","公务员","领导","茅台","五粮液");//正选过滤词
    private $regLenLeft  = 8;//正则左侧过滤
    private $regLenRight = 8;//正则右侧过滤
    private $idRange     = 100000;//10万作为一个区间
    private $caijiKey    = "tyz:tasks:comdescfilter:caiji";
    private $caijiBeans  = "tyz_filter_comdesc_caiji";
    private $comBeans    = "tyz_filter_comdesc_company";
    public function initialize()
    {
        $di = $this->di;
        $di->set('rocksdbcommon', function () use ($di) {
            $redis = new \Redis();
            $redis->pconnect($di['config']->rocksdbcommon->host, intval($di['config']->rocksdbcommon->port), 3);
            $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
            return $redis;
        });
        /* beanstalk队列 */
        $di->setShared('beanstalk', function () use ($di) {
            $queue = new \Phalcon\Queue\Beanstalk(
                $di['config']->Beanstalk->toArray()
            );
            return $queue;
        });
    }

    /**
     * 任务开启管理
     * php cli.php Xz\\Taskwww\\Cominfo\\Filtercomdesc task
     * @return null
     */
    public function taskAction() {
        //开启采集数据任务处理
        $start = 50000003;
        $end   = 75427670;
        $cacheArr = array(
            "onRun" => 0,
            "tasks"  => array(),
        );
        while (1) {
            if ($start > $end) {
                break;
            }
            echo "php cli.php Xz\\\\Taskwww\\\\Cominfo\\\\Filtercomdesc caiji"." ".$start." ".(($start+$this->idRange) > $end ? $end : ($start+$this->idRange))."\n";
            $cacheArr["tasks"][$start] = array(
                "open" => 0, "run" => 0,
            );
            $start += $this->idRange;
        }
        //将拆解的任务缓存下
        $this->di->get("rocksdbcommon")->set("tyz:tasks:comdescfilter:caiji", json_encode($cacheArr));
    }

    /**
     * 获取采集任务组并开启任务进程
     * php cli.php Xz\\Taskwww\\Cominfo\\Filtercomdesc runcaiji
     * @return null
     */
    public function runcaijiAction() {
        ini_set("display_errors", 1);
        error_reporting(E_ALL);
        //获取下所有的采集任务
        $cacheRs = $this->di->get("rocksdbcommon")->get($this->caijiKey);
        while (1) {
            $cacheRs = $this->di->get("rocksdbcommon")->get($this->caijiKey);
            if (empty($cacheRs) || empty($cacheRs = json_decode($cacheRs, true))) {
                exit("采集任务不存在\n");
            }
            //迭代器读取下队列的数据，更新下任务状态
            foreach ($this->xrangeBeansInfo($this->caijiBeans) as $valueTask) {
                if (isset($cacheRs["tasks"][$valueTask])) {
                    echo "任务".$valueTask."已经处理\n";
                    $cacheRs["tasks"][$valueTask]["run"] = 1;
                    $cacheRs["onRun"] -= 1;
                }
            }
            //开始任务开启处理
            $endTask = 0;
            foreach ($cacheRs["tasks"] as $key => $value) {
                if ($cacheRs["onRun"] >= 8) {
                    sleep(10);
                    break;
                }
                $start = $key;
                $end   = $start + $this->idRange;
                if ($value["run"]) {
                    echo "采集过滤任务区间{$start}-{$end}已经处理\n";
                    $endTask++;
                } elseif ($value["open"]) {
                    echo "采集过滤任务区间{$start}-{$end}正在处理中\n";
                } else {
                    //开启任务
                    echo "开启任务\n";
                    echo "nohup php cli.php Xz\\\\Taskwww\\\\Cominfo\\\\Filtercomdesc caiji ".$start." ".$end." >> nohup.out &\n";
                    exec("nohup php cli.php Xz\\\\Taskwww\\\\Cominfo\\\\Filtercomdesc caiji ".$start." ".$end." >> nohup.out &");
                    $cacheRs["tasks"][$key]["open"] = 1;
                    $cacheRs["onRun"] += 1;
                }
            }
            //如果所有的任务都已经处理过了
            if ($endTask >= count($cacheRs["tasks"])) {
                $this->di->get("rocksdbcommon")->del($this->caijiKey);
                exit("所有任务均已经处理\n");
            }
            //重新将开启后的任务结果写入缓存
            $this->di->get("rocksdbcommon")->set($this->caijiKey, json_encode($cacheRs));
            echo "\n\n\n\n\n\n";
        }
    }

    /**
     * 删除企业
     * php cli.php Xz\\Taskwww\\Cominfo\\Filtercomdesc del
     */
    public function delAction() {
        $cid = 74332305;
        $res = $this->di->get("local")->call(array(
            "service" => "Gcsupplier\Services\Delete",
            "method"  => "company",
            "args"    => array(array($cid)),
        ));
        var_dump($res);
    }



    /**
     * 开始单进程处理采集任务需求
     * php cli.php Xz\\Taskwww\\Cominfo\\Filtercomdesc caiji
     * @return null
     */
    public function caijiAction() {
        global $argv, $argc;
        ini_set("display_errors", 1);
        error_reporting(E_ALL);
        //建立下结果目录
        $resPath = "/tmp/filtercomdesc/";
        if (!is_dir($resPath)) {mkdir($resPath, 0777);}
        //构造下结果文件
        $successLog = $resPath."success";
        $failureLog = $resPath."failure";
        $start = isset($argv[3]) ? intval($argv[3]) : 0;
        $end   = isset($argv[4]) ? intval($argv[4]) : 0;
        if ($argc != 5 || $start >= $end) {
            exit("参数错误");
        }
        //遍历下企业简介信息
        foreach ($this->xrangeCaijiComDesc($start, $end) as $comDescArr) {
            $comDescArr["comdesc"] = $this->preFilter($comDescArr["comdesc"]);
            if (!($res = $this->filterBanWord($comDescArr["comdesc"]))) {
                $this->fPutContent($failureLog."_".$start.".log", $comDescArr["cid"]."参与监测，结果:没有监测到违禁词\n", FILE_APPEND);
                continue;
            }
            //遍历监测到的违禁词
            foreach ($res as $mainKey) {
                foreach ($this->relaKeyword as $value) {
                    //往左侧进行过滤并且过滤到了违禁词
                    if ($this->pregFilterLeft($mainKey, $value, $comDescArr["comdesc"])) {
                        //开始关闭当前采集企业，并写入文件结果缓存
                        $this->fPutContent($successLog . "_" . $start . ".log", "删除了企业" . $comDescArr["cid"] . "\n", FILE_APPEND);
                        continue 3;
                    }
                    //往右侧进行过滤
                    if ($this->pregFilterRight($mainKey, $value, $comDescArr["comdesc"])) {
                        //开始关闭当前采集企业，并写入文件结果缓存
                        $this->fPutContent($successLog."_".$start.".log", "删除了企业".$comDescArr["cid"]."\n", FILE_APPEND);
                        continue 3;
                    }
                }
            }
            //没有检测到违禁词信息
            $this->fPutContent($failureLog."_".$start.".log", $comDescArr["cid"]."检测到违禁词，但是没有检测到相应正选词"."\n", FILE_APPEND);
        }
        //所有的区间内的企业遍历完毕后，开始合并下结果集
        if (!file_exists($failureLog.".log")) {$this->fPutContent($failureLog.".log", "start\n", FILE_APPEND);}
        if (!file_exists($successLog.".log")) {$this->fPutContent($successLog.".log", "start\n", FILE_APPEND);}
        if (file_exists($failureLog."_".$start.".log")) {
            exec("cat ".$failureLog."_".$start.".log >> ".$failureLog.".log");
            unlink($failureLog."_".$start.".log");
        }
        if (file_exists($successLog."_".$start.".log")) {
            exec("cat ".$successLog."_".$start.".log >> ".$successLog.".log");
            unlink($successLog."_".$start.".log");
        }
        //所有的任务执行完毕后，将缓存中该任务的状态更改下
        $this->addRunProcess($start, $this->caijiBeans);
    }

    /**
     * 测试下队列优先级
     * php cli.php Xz\\Taskwww\\Cominfo\\Filtercomdesc test
     * @return null
     */
    public function testAction() {
        $this->addRunProcess("aaaaa", $this->comBeans, 1024);
        $this->addRunProcess("bbbb", $this->comBeans, 512);
        $this->addRunProcess("cccc", $this->comBeans, 512);
        foreach ($this->xrangeBeansInfo($this->comBeans) as $value) {
            var_dump($value);echo "\n";
        }
    }

    /**
     * 普通的发布任务拆解
     * php cli.php Xz\\Taskwww\\Cominfo\\Filtercomdesc comtask
     * @return null
     */
    public function comtaskAction() {
        //初始化下结果目录
        $resPath = "/tmp/filtercomdesc/";
        if (!is_dir($resPath)) {mkdir($resPath, 0777);}
        //开启采集数据任务处理
        $start = 0;
        $end   = 3867080;
        $runArr = array(
            "onRun" => 0,
            "tasks"  => array(),
        );
        while (1) {
            if ($start > $end) {
                break;
            }
            echo "php cli.php Xz\\\\Taskwww\\\\Cominfo\\\\Filtercomdesc com"." ".$start." ".(($start+$this->idRange) > $end ? $end : ($start+$this->idRange))."\n";
            $runArr["tasks"][$start] = array(
                "open" => 0, "run" => 0,
            );
            $start += $this->idRange;
        }
        //将拆解的任务存储到文件中
        file_put_contents($resPath."run.php", json_encode($runArr));
    }

    /**
     * 开启进程任务
     * php cli.php Xz\\Taskwww\\Cominfo\\Filtercomdesc comrun
     * @return null
     */
    public function comrunAction() {
        $maxRunNum  = 8;
        $resPath = "/tmp/filtercomdesc/";
        while (1) {
            //获取下任务处理进程记录
            $runArr = file_get_contents($resPath."run.php");
            if (empty($runArr) || empty(($runArr = json_decode($runArr, true)))) {
                exit("没有获取到要执行的任务\n");
            }
            //判断下任务是否处理完毕
            $finishTask = true;
            foreach ($runArr["tasks"] as $key => $value) {
                if ($value["run"] != 1) {
                    $finishTask = false;
                }
                if ($value["open"] != 1) {
                    //将要开启的任务打入队列
                    echo "初始化打入队列任务start=".$key."\n";
                    $this->addRunProcess(json_encode(array("type" => "openProcess", "start" => $key, "end" => $key + $this->idRange)), $this->comBeans);
                    $runArr["tasks"][$key]["open"] = 1;
                    file_put_contents($resPath."run.php", json_encode($runArr));
                }
            }
            if ($finishTask) {
                exit("所有任务处理完毕\n");
            }
            //循环队列开始处理
            foreach ($this->xrangeBeansInfo($this->comBeans) as $value) {
                if (empty($value) || empty(($value = json_decode($value, true)))) {
                    continue;
                }
                //两种更改任务进程的方案
                if ($value["type"] == "openProcess" && $runArr[$value["start"]]["open"] != 1) {
                    echo "当前进程数:".$runArr["onRun"]."\n";
                    if ($runArr["onRun"] >= $maxRunNum) {
                        //将当前任务扔回队列，休眠10s，然后继续读队列
                        echo "任务".$value["start"]."扔回队列，继续等待进程处理完毕\n";
                        $this->addRunProcess(json_encode($value), $this->comBeans);
                        sleep(10);
                        break;
                    }
                    echo "开启com处理任务start=".$value["start"]." end=".$value["end"]."\n";
                    exec("nohup php cli.php Xz\\\\Taskwww\\\\Cominfo\\\\Filtercomdesc com ".$value["start"]." ".$value["end"]." >> nohup.out &");
                    $runArr["onRun"]++;
                    file_put_contents($resPath."run.php", json_encode($runArr));
                } elseif ($value["type"] == "resUpdate") {
                    echo "com任务start=".$value["data"]."处理完毕\n";
                    $runArr["onRun"]--;
                    $runArr["tasks"][$value["data"]]["run"] = 1;
                    file_put_contents($resPath."run.php", json_encode($runArr));
                }
            }
        }
    }

    /**
     * 清空下队列
     * php cli.php Xz\\Taskwww\\Cominfo\\Filtercomdesc clear
     * @return null
     */
    public function  clearAction() {
        foreach ($this->xrangeBeansInfo($this->comBeans) as $value) {
            echo $value."\n";
        }
    }

    /**
     * 用户发布的企业信息过滤
     * php cli.php Xz\\Taskwww\\Cominfo\\Filtercomdesc com
     * @return null
     */
    public function comAction() {
        global $argv, $argc;
        ini_set("display_errors", 1);
        error_reporting(E_ALL);
        //建立下结果目录
        $resPath = "/tmp/filtercomdesc/";
        if (!is_dir($resPath)) {mkdir($resPath, 0777);}
        //构造下结果文件
        $successLog = $resPath."success";
        $failureLog = $resPath."failure";
        $start = isset($argv[3]) ? intval($argv[3]) : 0;
        $end   = isset($argv[4]) ? intval($argv[4]) : 0;
        if ($argc != 5 || $start >= $end) {
            exit("参数错误");
        }

        //遍历下企业简介信息
        foreach ($this->xrangeComDesc($start, $end) as $comDescArr) {
            if (empty($comDescArr)) {
                continue;
            }
            $comDescArr["comdesc"] = $this->preFilter($comDescArr["comdesc"]);
            if (!($res = $this->filterBanWord($comDescArr["comdesc"]))) {
                $this->fPutContent($failureLog."_".$start.".log", $comDescArr["cid"]."参与监测，结果:没有监测到违禁词\n", FILE_APPEND);
                continue;
            }
            //遍历监测到的违禁词
            foreach ($res as $mainKey) {
                foreach ($this->relaKeyword as $value) {
                    //往左侧进行过滤并且过滤到了违禁词
                    if ($this->pregFilterLeft($mainKey, $value, $comDescArr["comdesc"])) {
                        $this->fPutContent($successLog . "_" . $start . ".log", $comDescArr["cid"] . "\n", FILE_APPEND);
                        continue 3;
                    }
                    //往右侧进行过滤
                    if ($this->pregFilterRight($mainKey, $value, $comDescArr["comdesc"])) {
                        $this->fPutContent($successLog."_".$start.".log", $comDescArr["cid"]."\n", FILE_APPEND);
                        continue 3;
                    }
                }
            }
            //没有检测到违禁词信息
            $this->fPutContent($failureLog."_".$start.".log", $comDescArr["cid"]."检测到违禁词，但是没有检测到相应正选词"."\n", FILE_APPEND);
        }
        //所有的区间内的企业遍历完毕后，开始合并下结果集
        if (!file_exists($failureLog.".log")) {$this->fPutContent($failureLog.".log", "start\n", FILE_APPEND);}
        if (!file_exists($successLog.".log")) {$this->fPutContent($successLog.".log", "start\n", FILE_APPEND);}
        if (file_exists($failureLog."_".$start.".log")) {
            exec("cat ".$failureLog."_".$start.".log >> ".$failureLog.".log");
            unlink($failureLog."_".$start.".log");
        }
        if (file_exists($successLog."_".$start.".log")) {
            exec("cat ".$successLog."_".$start.".log >> ".$successLog.".log");
            unlink($successLog."_".$start.".log");
        }
        //将执行结果打入队列,更新运行结果
        $beanArr = array(
            "type" => "resUpdate",
            "data" => $start,
        );
        $this->addRunProcess(json_encode($beanArr), $this->comBeans, 512);
    }

    /**
     * 将处理好的进程打入队列
     * @param $start
     * @param $beansName
     * @param $priority
     * @return null
     */
    private function addRunProcess($start, $beansName, $priority = 1024) {
        try {
            DI::getDefault()->getShared('beanstalk')->choose($beansName);
            DI::getDefault()->getShared('beanstalk')->put(
                $start,
                array(
                    'priority' => $priority ? $priority : 1024,
                    'delay'    => 0,
                    'ttr'      => 86400,
                )
            );
        } catch (\Exception $e) {
        }
    }

    /**
     * 遍历获取下当前队列的所有内容
     * @param $beansName
     * @return string
     */
    private function xrangeBeansInfo($beansName) {
        while (1) {
            DI::getDefault()->get("beanstalk")->watch($beansName);
            $rs = DI::getDefault()->get("beanstalk")->reserve(10);
            if (empty($rs)) {
                yield 0;
                break;
            }
            $result = "";
            if ($id = $rs->getId()) {
                $result = $rs->getbody();
                $rs->delete($id);
            }
            yield $result;
        }
    }

    /**
     * 往左侧正则过滤监测
     * @param $mainKeyword
     * @param $relaKeyword
     * @param $string
     * @return boolean
     */
    private function pregFilterLeft($mainKeyword, $relaKeyword, $string) {
        $pat = "/{$relaKeyword}(?<t>.*){$mainKeyword}/U";
        preg_match_all($pat, $string, $foreMatch);
        if (isset($foreMatch['t'])) {
            foreach ($foreMatch['t'] as $vv) {
                if (mb_strlen($vv, 'UTF-8') <= $this->regLenLeft) {
                    return true;
                }
            }
        }
        return false;
    }


    /**
     * 往右侧正则过滤检测
     * @param $mainKeyword
     * @param $relaKeyword
     * @param $string
     * @return boolean
     */
    private function pregFilterRight($mainKeyword, $relaKeyword, $string) {
        $pat = "/{$mainKeyword}(?<t>.*){$relaKeyword}/U";
        preg_match_all($pat, $string, $afterMatch);
        if (isset($afterMatch['t'])) {
            foreach ($afterMatch['t'] as $vv) {
                if (mb_strlen($vv, 'UTF-8') <= $this->regLenRight) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 违禁词数据监测
     * @param $string
     * @return boolean
     */
    private function filterBanWord($string) {
        $mssObj = $this->mssReady("filterComDesc");
        $re  = mss_search($mssObj, $string);
        if (empty($re)) {
            return false;
        }
        $result = array();
        foreach ($re as $value) {
            $result[] = $value[0];
        }
        return $result;
    }

    /**
     * 数据监测前预过滤处理
     * @param $string
     * @return string
     */
    private function preFilter($string) {
        //清除掉无用的字符
        $string = $this->cleanChars($string);
        //繁体转简体，过滤空格
        $string = $this->promatcher($string);
        //去除非数字，字母，标点符号汉字之外的其他字符
        $string = preg_replace('/[^\x7f-\xff0-9a-zA-Z,.:;\'\'""?!《》，。：；‘’“”？！]/', '', $string);
        return $string;
    }

    /*功能:专为产品信息违禁词检测做的接口，繁体转化为简体，然后返回转换后的结果
     *名称:promatcher
     *返回:过滤空格并转换为简体的字符串
     *@string str_t表示繁体转化后的简体
     */
    public function promatcher($str)
    {
        $search = array(
            "'<[\/\!]*?[^<>]*?>'si", // 去掉 HTML 标记
            "'([\r\n])[\s]+'", // 去掉空白字符
            "'&(quot|#34);'i", // 替换 HTML 实体
            "'&(amp|#38);'i",
            "'&(lt|#60);'i",
            "'&(gt|#62);'i",
            "'&(nbsp|#160);'i");

        $replace = array(
            "",
            "\\1",
            "\"",
            "&",
            "<",
            ">");
        $str        = preg_replace($search, $replace, $str); //去除html中tab、空格、回车标签
        $str        = str_replace(' ', '', $str); //去除文本中的空白字符
        @$strGbk    = iconv("UTF-8", "GBK//IGNORE", $str);
        @$strGb2312 = iconv("UTF-8", "GB2312//IGNORE", $str);

        if ($strGbk != $strGb2312) {
            //简繁转换
            $str = \Xz\Func\Jianfan::_hant2hans($str);
        }
        return $str;
    }


    /**
     * 清理掉无用字符
     *
     * @access public
     * @param mixed $string
     * @return void
     * @author 刘建辉
     * @修改日期 2012-11-05 10:57:22
     */
    public function cleanChars($string)
    {

        $string    = strip_tags(htmlspecialchars_decode($string));
        $string    = preg_replace("~>\s+\r~", ">", preg_replace("~>\s+\n~", ">", $string)); //modify 压缩
        $string    = preg_replace("~>\s+<~", "><", $string);
        $string    = str_replace("\r\n", '', $string); //清除换行符
        $string    = str_replace("\n", '', $string); //清除换行符
        $string    = str_replace("\t", '', $string); //清除制表符
        $pattern   = array(
            "'<!--[/!]*?[^<>]*?>'si", //去掉注释标记
            "'  '",
        );
        $string         = preg_replace($pattern, '', $string);
        return $string;
    }

    /**
     * 开始初始化mss多词检索工具
     * @param $mssName
     * @return boolean
     */
    private function mssReady($mssName) {
        ini_set('memory_limit', '512M');
        $mss     = mss_create($mssName, -1);
        $isready = mss_is_ready($mss);
        if (!$isready) {
            foreach ($this->keywords as $k => $v) {
                mss_add($mss, $v, $k);
            }
        }
        return $mss;
    }

    /**
     * 迭代器遍历区间范围内的所有信息
     * @param $start
     * @param $end
     * @return array
     */
    private function xrangeCaijiComDesc($start, $end) {
        $id = $start;
        while (1) {
            //查询下状态正常的企业信息
            $sql   = "SELECT cid, state from \Caijicominfo\Models\Gccompany where cid > :cid: and state = 1 order by cid asc limit 1";
            $query = $this->di->get("modelsManager")->createQuery($sql);
            $buildRs = $query->execute(array("cid" => $id));
            $buildRs = $buildRs->toArray() ? $buildRs->toArray()[0] : array();
            if (empty($buildRs)) {
                break;
            }
            $id = $buildRs["cid"];
            if ($id > $end) {
                break;
            }
            //查询下采集的企业简介
            $companyDesc = $this->getCaijiCompanyDesc($buildRs["cid"]);
            if (empty($companyDesc) || empty($companyDesc["comdesc"])) {
                continue;
            }
            //迭代器返回用作过滤处理
            yield $companyDesc;
        }
    }

    /**
     * 遍历下企业简介信息
     * @param $start
     * @param $end
     * @return array
     */
    private function xrangeComDesc($start, $end) {
        $id = $start;
        while (1) {
            //查询下状态正常的企业信息
            $sql = "SELECT cid, state from \Gccominfo\Models\Gccompany where cid > :cid: and state = 1 order by cid asc limit 1";
            $query = $this->di->get("modelsManager")->createQuery($sql);
            $buildRs = $query->execute(array("cid" => $id));
            $buildRs = $buildRs->toArray() ? $buildRs->toArray()[0] : array();
            if (empty($buildRs) || $buildRs["cid"] > $end) {
                yield array();
                break;
            }
            $id = $buildRs["cid"];
            //查询下企业简介信息
            $companyDesc = $this->getCompanyDesc($buildRs["cid"]);
            if (empty($companyDesc) || empty($companyDesc["comdesc"])) {continue;}
            yield $companyDesc;
        }
    }

    /**
     * 查询下企业的简介信息
     * @param $cid
     * @return array
     */
    private function getCompanyDesc($cid) {
        if ($cid < 1) {
            return array();
        }
        $sql   = "SELECT cid, comdesc from \Gccominfo\Models\Gccomdata where cid = :cid: limit 1";
        $query  = $this->di->get("modelsManager")->createQuery($sql);
        $result = $query->execute(array("cid" => $cid));
        if ($result && $result->toArray()) {
            return $result->toArray()[0];
        }
        return array();
    }

    /**
     * 查询下采集企业信息
     * @param $cid
     * @return array
     */
    private function getCaijiCompanyDesc($cid) {
        if ($cid < 1) {
            return array();
        }
        $sql   = "SELECT cid, comdesc from \Caijicominfo\Models\Gccomdata where cid = :cid: limit 1";
        $query  = $this->di->get("modelsManager")->createQuery($sql);
        $result = $query->execute(array("cid" => $cid));
        if ($result && $result->toArray()) {
            return $result->toArray()[0];
        }
        return array();
    }

    /**
     * 写入文件内容
     * @param $path
     * @param $content
     * @param $flag
     * @return null
     */
    private function fPutContent($path, $content, $flag = null) {
        if ($flag) {
            file_put_contents($path, $content, $flag);
        } else {
            file_put_contents($path, $content);
        }
        chmod($path, 0777);
    }
}