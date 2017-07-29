<?php


namespace CKylin;

//COMMON Uses
use pocketmine\utils\TextFormat as Color;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\event\Listener;
use pocketmine\plugin\Plugin;
use pocketmine\utils\Config;
use pocketmine\Server;
use pocketmine\Player;

use CKylin\CkMsgBox;
use onebone\economyapi\EconomyApi;

class CkPaymentReview extends PluginBase implements Listener
{

    public function getAPI(){
        return $this;
    }

    public function onEnable() {
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->path = $this->getDataFolder();
		@mkdir($this->path);
		$this->cfg = new Config($this->path."options.yml", Config::YAML,array(
            'useLocalMsgBox'=>true,
            'Enabled'=>true,
            'AutoPass'=>false,
            'AutoPassMaxLimit'=>200,
        ));
		$this->data = new Config($this->path."data.yml", Config::YAML,array());
        // data format:
        // reqID=>[
        //     from=><Player>,
        //     to=><Player>,
        //     money=><Int>,
        //     reqTime=><Time>,
        //     passTime=><Time>,
        //     status=><Failed|Passed|Rejected|false>,
        //     admin=><false|Player>
        // ],
        if($this->getServer()->getPluginManager()->getPlugin('CkMsgBox')&&$this->cfg->get('useLocalMsgBox')!=true){
            $this->msgbox = CkMsgBox::getAPI();
            $this->getLogger()->info('检测到消息盒插件，所有消息将由消息盒插件代理。');
        }else{
            $this->msgbox = $this;
        }
        $this->eco = EconomyAPI::getInstance();
        $this->getLogger()->info('插件已启动.');
	}

    public function onDisable(){
        $this->saveall();
    }

    public function saveall(){
        $this->cfg->save();
        $this->data->save();
    }

    public function onCommand(CommandSender $s, Command $cmd, $label, array $args){
        if($cmd=="payto"){
            if(count($args)<2){
                $s->sendMessage("[转账] ".Color::YELLOW."用法：/payto <目标玩家> <金额>");
                return true;
            }
            $tp = $s->getServer()->getPlayer($args[0]);
            if(!$tp instanceof Player) $s->sendMessage("[转账] ".Color::RED."目标玩家未能找到。".Color::RESET);
            $target = $tp->getName();
            $money = $args[1];
            $this->newRequest($s->getName(),$target,$money);
            return true;
        }
        if($cmd=="checkpay"){
            if(empty($args[0])){
                $s->sendMessage("[转账查询] ".Color::YELLOW."用法：/checkpay <请求ID>");
                return true;
            }
            $id = $args[0];
            if($s->isOp()||$this->is_Sender($s->getName(),$id)){
                $s->sendMessage($this->getRequestInfo($id));
            }else{
                $s->sendMessage("[转账查询] ".Color::YELLOW."查看失败。请求不存在或权限不足。");
            }
            return true;
        }
        if($cmd=="allowpay"&&$s->isOp()){
            if(empty($args[0])){
                $s->sendMessage("[转账后台] ".Color::YELLOW."用法：/allowpay <请求ID>");
                return true;
            }
            $id = $args[0];
            $statuscode = $this->allowRequest($id,$s->getName());
            if($statuscode==0){
                $s->sendMessage("[转账后台] ".Color::GREEN."通过请求处理成功，已完成转账");
                return true;
            }elseif($statuscode==1){
                $s->sendMessage("[转账后台] ".Color::GREEN."请求已经被处理，无法重复处理。");
                return true;
            }elseif($statuscode==2){
                $s->sendMessage("[转账后台] ".Color::GREEN."请求处理时出现错误。");
                return true;
            }else{
                $s->sendMessage("[转账后台] ".Color::RED."通过请求处理失败");
                return true;
            }
        }
        if($cmd=="disallowpay"&&$s->isOp()){
            if(empty($args[0])){
                $s->sendMessage("[转账后台] ".Color::YELLOW."用法：/disallowpay <请求ID>");
                return true;
            }
            $id = $args[0];
            $statuscode = $this->rejectRequest($id,$s->getName());
            if($statuscode==0){
                $s->sendMessage("[转账后台] ".Color::GREEN."拒绝请求处理成功，已返还金币");
                return true;
            }elseif($statuscode==1){
                $s->sendMessage("[转账后台] ".Color::GREEN."请求已经被处理，无法重复处理。");
                return true;
            }elseif($statuscode==2){
                $s->sendMessage("[转账后台] ".Color::GREEN."请求处理时出现错误。");
                return true;
            }else{
                $s->sendMessage("[转账后台] ".Color::RED."拒绝请求处理失败");
                return true;
            }
        }
        if($cmd=="pays"&&$s->isOp()){
            $type = empty($args[0]) ? 'waiting' : $args[0];
            if($type!='waiting'&&$type!='all'){
                if($type=='w') $type = 'waiting';
                elseif($type=='a') $type = 'all';
                else {
                    $s->sendMessage('[转账后台] 用法：/pays <waiting|all|w|a> [页码]');
                    return true;
                }
            }
            if($type=='waiting'){
                $pagenum = empty($args[1]) ? 1 : $args[1];
                $data = $this->genReqList('waiting');
                $pages = $this->calcPages(count($data));
                $msgs = $this->getPage($data,$pagenum);
                $s->sendMessage("[转账后台] 待处理请求 [ $pagenum / $pages ]：");
                // $msgs = $this->genReqList('waiting');
            }else{
                $pagenum = empty($args[1]) ? 1 : $args[1];
                $data = $this->genReqList('all');
                $pages = $this->calcPages(count($data));
                $msgs = $this->getPage($data,$pagenum);
                $s->sendMessage("[转账后台] 全部请求 [ $pagenum / $pages ]：");
                // $msgs = $this->genReqList('all');
            }
            foreach($msgs as $msg){
                $s->sendMessage($msg);
            }
            return true;
        }
    }

    public function calcPages($count = 1,$perpage = 4){
        return ceil($count/$perpage);
    }

    public function getPage(Array $allData, $pagenum = 1, $itemsPerPage = 4){
        if(count($allData)<$itemsPerPage) return $allData;
        if($pagenum>$this->calcPages(count($allData),$itemsPerPage)) return array();
        $lastEnd = ($pagenum - 1) * $itemsPerPage;
        $startItem = $lastEnd + 1;
        $endItem = $pagenum * $itemsPerPage;
        $endItem++;
        $finalArr = array();
        $currentItem = $startItem;
        while($currentItem<$endItem){
            $currentIndex = $currentItem - 1;
            if(!empty($allData[$currentIndex])){
                array_push($finalArr,$allData[$currentIndex]);
                $currentItem++;
            }else{
                break;
            }
        }
        return $finalArr;
    }

    public function msgOP($msg){
        foreach($this->getServer()->getOnlinePlayers() as $p){
            if($p->isOp()){
                $this->msgbox->msg($msg,$p->getName());
            }
        }
    }

    public function newRequest($from, $to, $money = 100, $force = false){
        if((!$this->cfg->get('Enabled'))&&$force==false){
            $this->msgbox->msg("[转账] ".Color::YELLOW."转账系统已关闭。",$from);
            return false;
        }
        $waiting = $this->hasRequest($from);
        if($waiting!==false){
            $this->msgbox->msg("[转账] ".Color::YELLOW."您有一个等待处理的转账请求，请求ID为 $waiting 。",$from);
            return false;
        }
        if(!$this->hasAccount($to)){
            $this->msgbox->msg("[转账] ".Color::RED."目标玩家未能找到。",$from);
            return false;
        }
        $money = (int) $money;
        if($money<=0){
            $this->msgbox->msg("[转账] ".Color::RED."不正确的转账金额。",$from);
            return false;
        }
        if(!$this->hasMoney($from,$money)){
            $this->msgbox->msg("[转账] ".Color::RED."余额不足。",$from);
            return false;
        }
        $takecode = $this->takeMoney($from,$money);
        if(!$takecode){
            $this->msgbox->msg("[转账] ".Color::RED."生成转账请求时出现错误：扣款错误。",$from);
            return false;
        }
        if($this->cfg->get('AutoPass')&&$this->cfg->get('AutoPassMaxLimit')>=$money){
            $addcode = $this->addMoney($to,$money);
            if(!$addcode){
                $this->msgbox->msg("[转账] ".Color::RED."生成转账请求时出现错误：划款错误。",$from);
                return false;
            }
            $this->msgbox->msg("[转账] ".Color::GREEN."符合小额免审转账要求，您的转账(目标：$to | 金额：$money)已经即时到帐。",$from);
            $this->msgbox->msg("[转账] ".Color::BLUE."收到来自$from的转账，金额$money。",$to);
            return true;
        }
        $req = $this->getRequestArray();
        $req['from'] = $from;
        $req['to'] = $to;
        $req['money'] = $money;
        $id = $this->getID();
        $this->data->set($id,$req);
        $this->saveall();
        $this->msgbox->msg("[转账] ".Color::GREEN."已生成转账请求，ID $id ，等待管理员审核。",$from);
        $this->msgbox->msg("[转账] ".Color::GREEN."$from 提交了向您转账 $money 金币的请求，管理员正在审核",$to);
        $this->msgOP("[转账审查] $from 提交了向 $to 转账 $money 金币的请求( $id )，等待处理。");
    }

    public function is_Sender($name,$id){
        $id = (int) $id;
        $name = strtolower($name);
        $req = $this->getRequest($id);
        if($req===false) return false;
        return $req['from']==$name;
    }

    public function getRequestInfo($id){
        $req = $this->getRequest($id);
        if($req===false) return '未找到信息';
        $msg =   "======[转账查询]======";
        $msg.= "\n发起玩家：{$req['from']}";
        $msg.= "\n接收玩家：{$req['to']}";
        $msg.= "\n转账金额：{$req['money']}";
        $starttime = $this->getTimeDate($req['reqTime']);
        $msg.= "\n发起时间：{$starttime}";
        if($req['status']=='Failed'){
            $msg.= "\n转账状态：失败";
        }elseif($req['status']=='Passed'){
            $msg.= "\n转账状态：成功";
            $msg.= "\n审核人员：{$req['admin']}";
            $passtime = $this->getTimeDate($req['passTime']);
            $msg.= "\n操作时间：{$passtime}";
        }elseif($req['status']=='Rejected'){
            $msg.= "\n转账状态：驳回";
            $msg.= "\n审核人员：{$req['admin']}";
            $passtime = $this->getTimeDate($req['passTime']);
            $msg.= "\n操作时间：{$passtime}";
        }else{
            $msg.= "\n转账状态：待审";
        }
        $msg.= "\n==================";
        return $msg;
    }

    public function genReqLine($req){
        // $req = $this->getRequest($id);
        if($req===false) return '未知 ID';
        $line = "{$req['from']} | {$req['to']} | {$req['money']} | {$req['status']}";
    }

    public function genReqList($type = 'waiting'){
        $data = $this->data->getAll();
        $arr = array();
        switch($type){
            case 'waiting':
                foreach($data as $id => $req){
                    if($req['status']===false){
                        $stat = $this->getStatusString($req['status']);
                        array_push($arr,"$id | {$req['from']} | {$req['to']} | {$req['money']} | {$stat}");
                    }
                }
                break;
            case '1':
            case 'all':
                foreach($data as $id => $req){
                    $stat = $this->getStatusString($req['status']);
                    array_push($arr,"$id | {$req['from']} | {$req['to']} | {$req['money']} | {$stat}");
                }
                break;
            default:
                array_push($arr,'出现错误');
        }
        return $arr;
    }

    public function getStatusString($status){
        if($status=='Failed'){
            return "出错";
        }elseif($status=='Passed'){
            return "通过";
        }elseif($status=='Rejected'){
            return "驳回";
        }else{
            return "待审";
        }
    }

    // status code:
    // 0 - success
    // 1 - status error
    // 2 - error

    public function allowRequest($id,$admin = 'SYSTEM'){
        $id = (string) $id;
        $req = $this->getRequest($id);
        if($req['status']!=false) return 1;
        $req['passTime'] = time();
        $req['admin'] = $admin;
        $code = $this->addMoney($req['to'],$req['money']);
        if(!$code){
            $this->msgOP("[转账审查] $admin 已经通过了 $id 号请求，但是出现了错误。");
            $this->msgbox->msg("[转账] ".Color::YELLOW."您的{$id}号转账请求(目标：{$req['to']} | 金额：{$req['money']})已被通过，但是转账时出现了错误(EcoError {$code})。请联系管理员。",$req['from']);
            $req['status'] = 'Failed';
            $this->data->set($id,$req);
            $this->saveall();
            return 2;
        }else{
            $this->msgOP("[转账审查] $admin 已经通过了 $id 号请求。");
            $this->msgbox->msg("[转账] ".Color::GREEN."您的{$id}号转账请求(目标：{$req['to']} | 金额：{$req['money']})已被通过。",$req['from']);
            $this->msgbox->msg("[转账] ".Color::BLUE."收到来自{$req['from']}的转账，金额{$req['money']}。",$req['to']);
            $req['status'] = 'Passed';
            $this->data->set($id,$req);
            $this->saveall();
            return 0;
        }
    }

    public function rejectRequest($id,$admin = 'SYSTEM'){
        $id = (string) $id;
        $req = $this->getRequest($id);
        if($req['status']!=false) return 1;
        $req['passTime'] = time();
        $req['admin'] = $admin;
        $code = $this->addMoney($req['from'],$req['money']);
        if(!$code){
            $this->msgOP("[转账审查] $admin 已经拒绝了 $id 号请求，但是出现了错误。");
            $this->msgbox->msg("[转账] ".Color::RED."您的{$id}号转账请求(目标：{$req['to']} | 金额：{$req['money']})已被驳回，且资金转回时出现了错误(EcoError {$code})。请联系管理员。",$req['from']);
            $req['status'] = 'Failed';
            $this->data->set($id,$req);
            $this->saveall();
            return 2;
        }else{
            $this->msgOP("[转账审查] $admin 已经拒绝了 $id 号请求。");
            $this->msgbox->msg("[转账] ".Color::RED."您的{$id}号转账请求(目标：{$req['to']} | 金额：{$req['money']})已被驳回。",$req['from']);
            $req['status'] = 'Rejected';
            $this->data->set($id,$req);
            $this->saveall();
            return 0;
        }
    }

    public function hasAccount($name){
        return $this->eco->accountExists($name);
    }

    public function hasMoney($p,$m){
        return $this->eco->myMoney($p)>=$m;
    }

    public function takeMoney($p,$m){
        return $this->eco->reduceMoney($p,$m,false,'CkPaymentReview');
    }

    public function addMoney($p,$m){
        return $this->eco->addMoney($p,$m,false,'CkPaymentReview');
    }

    public function getRequest($id){
        $id = (string) $id;
        if(empty($id)) return false;
        if($this->data->exists($id)){
            return $this->data->get($id);
        }else return false;
    }

    public function getRequestArray(){
        return array(
            'from'=>'',
            'to'=>'',
            'money'=>100,
            'reqTime'=>time(),
            'passTime'=>'',
            'status'=>false,
            'admin'=>false
        );
    }

    public function getID(){
        $data = $this->data->getAll();
        $baseId = count($data)+1;
        $bool = true;
        while($bool){
            if($this->data->exists($baseId)){
                $baseId++;
            }else{
                break;
            }
        }
        return (string) $baseId;
    }

    public function hasRequest($pname){
        if(empty($pname)) return false;
        return array_search($pname,$this->data->getAll());
    }

    public function getTimeDate($time){
        return date('Y年m月d日',$time);
    }

    public function msg($msg,$name){
        if(empty($msg)||empty($name)) return false;
        foreach($this->getServer()->getOnlinePlayers() as $p){
            if(strtolower($p->getName())==strtolower($name)){
                $p->sendMessage($msg);
                return true;
                break;
            }
        }
        return false;
    }
}