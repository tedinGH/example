<?php
import("COM.Utils.XMLUtil");
require_once(LIB_PATH.'\Utils\nusoap\nusoap.php');

class GuoYaoService
{
	/**
	 * 日志
	 *
	 * @var string
	 */
	private $log;

	/**
	 * soap客户端
	 *
	 * @var nusoap_client
	 */
	private $service;
	private $authname = 'JSDX01'; //JSDX01 //NGWL01
	private $authpass;
	private $orderno;
	private $error;
	private $sendBack = array();

	public function __construct(){
		set_time_limit(0);
	}
	
	/**
	 * 建立nusoap client
	 *
	 */
	private function create()
	{
		//登录服务
		if(!$this->service)
		{
			//测试库
			$this->service = new nusoap_client("http://221.133.237.233:8199/gytms_edi/services/gyService?wsdl", true);
			//正式库
			//$this->service = new nusoap_client("http://221.133.237.232:8002/gytms_edi/services/gyService?wsdl", true);
			$this->service->soap_defencoding = 'utf-8';
			$this->service->decode_utf8 = false;
			$this->service->response_timeout = 120;
		}
	}

	/**
	 * 下载订单
	 * 
	 */
	public function ReadFromSPL()
	{
		$this->create();
		try {
			//call
			//$result = unserialize(base64_decode(file_get_contents("d:/result.log")));
			$result = $this->service->call("setTmsShipment",array('tmsShipment'=>$this->authname),'','');
			
			if(DEBUG){
				$this->debug("resultSerialize", base64_encode(serialize($result)));
			}
			if(!$result){
				$this->addLog("the webservice was no result.");
				$this->addLog($this->service->error_str);
				
				return false;
			}
			
			if(!$result["item"]){
				$this->addLog("the item as empty.");
				return false;
			}

			if(DEBUG){
				$this->debug("readdata", print_r($result["item"],true));
			}
			
			$readItem = array();
			if(is_array($result["item"])){
				$readItem = $result["item"];
			} else {
				$readItem[] = $result["item"];
			}
			foreach($readItem as $data){
				$xml = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA);
				$response = XMLUtil::XML2array($xml);
				$messagedetail = simplexml_load_string(trim($response['MESSAGEDETAIL']));
				
				$contentlist = array();
				$contentlist[] = $messagedetail->CONTENTLIST;
				if(DEBUG){
					$this->debug("contentlist", print_r($contentlist,true));
				}
				
				foreach($contentlist as $tmp){
					if(DEBUG){
						$this->debug("temp", print_r($tmp, true));
					}
					
					$tmp = XMLUtil::XML2array($tmp);
					$tmp = $tmp['CONTENT'];
					$order = $tmp['HEADER'];
					
					$details = array();
					if(array_key_exists(0, $tmp['DETAIL'])){
						$details = $tmp['DETAIL'];
					} else {
						$details[] = $tmp['DETAIL'];
					}
					
					$succeed = 0;
					if(DEBUG){
						$this->debug("details", print_r($details, true));
					}
					foreach($details as $detail){
						if(DEBUG){
							$this->debug("detail", print_r($detail,true));
						}
						$order = array_merge($order, $detail);
						$order['FILEFUNCTION'] = $response['MESSAGEHEAD']['FILEFUNCTION'];
						
						if(!$this->saveOrder($order)){
							$this->addLog("saveOrder data error in function ReadFromSPL");
							$succeed += 1;
						}
					}
					// 如果都成功了，反馈信息
					if($succeed === 0){
						//回写已下载标记
						$content =  "<CONTENTLIST>".
						"<CONTENT>".
						"<HEADER></HEADER>".
						"<DETAIL>".
						"<ECNO>".$this->orderno."</ECNO>".
						"<CECNO></CECNO>".
						"<FLAG>1</FLAG>".
						"</DETAIL>".
						"</CONTENT>".
						"</CONTENTLIST>";

						$senddata = trim($this->createResult('DispatcFlag', $content));
						if(DEBUG){
							$this->debug("response", htmlspecialchars_decode($senddata));
						}
						
						if(!$this->orderno){
							$this->addLog("ecno in setTmsShipmentType is empty, not good");
							continue;
						}
						
						//call
						if(in_array($this->orderno, $this->sendBack)){
							$this->addLog("bill was sendback");
						} else {
							$rs = $this->service->call("setTmsShipmentType", array('tmsShipmentTypeXml'=>$senddata), '', '');
							if(DEBUG){
								$this->debug("responseresult", print_r($rs, true));
							}
							
							$this->checkResult($rs);
														
							// 休息一下
							$this->sleep4awhile();
							
						}
					} 
				}
			}
		} catch (Exception $e) {
			$this->addLog("setTmsShipment Exception error".$e->getMessage());
			return false;
		}
	}

	/**
	 * 保存订单
	 *
	 */
	private function saveOrder($data)
	{
		try {
			$headmodel = new OrderModel();
			//作废标记处理
			if($data['FILEFUNCTION'] === 'CANCEL'){
				$sql = "update web_order set status = -1 where orderNo = '".$data['ECNO']."';";
				$headmodel->execute($sql);
				$headmodel->commit();
				return true;
			}

			//查找已经存在的记录
			$find = $headmodel->where("orderNo = '".$data['ECNO']."'")->find();			
			if($find['id']){
				unset($find['dno']);
				unset($find['synup']);
				unset($find['billNo']);
				unset($find['OrderInfoId']);
				unset($find['status']);

				$head = $find;
			}

			//订单头文件
			$head["departure"] = '';
			$head["destination"] = '';
			$head["orderNo"] = $data['ECNO'];
			$head["client"] = '国药控股广州有限公司';
			$head["clientName"] = $data['CONSIGNOR']!='null'?$data['CONSIGNOR']:'';
			$head["clientPhone"] = $data['CONPHONE']!='null'?$data['CONPHONE']:'';
			$head["clientAddress"] = $data['CONADDRESS']!='null'?$data['CONADDRESS']:'';
			$head["consignee"] = $data['CUSTOMERNNAME']!='null'?$data['CUSTOMERNNAME']:'';
			$head["consigneeName"] = $data['CUSCONTACT']!='null'?$data['CUSCONTACT']:'';
			$head["consigneePhone"] = $data['CUSNPHONENO']!='null'?$data['CUSNPHONENO']:'';
			$head["consigneeAddress"] = $data['CUSTADDRESS']!='null'?$data['CUSTADDRESS']:'';
			$head["deliverType"] = $data['TMSLOADINGMETHOD']!='null'?$data['TMSLOADINGMETHOD']:'';
			$head["goods"] = '药品';//$data['GOODSTYPE']!='null'?$data['GOODSTYPE']:'';
			$head["package"] = '';
			$head["qty"] = intval($data['TOTALQUANTITY']);
			$head["weight"] = 0;
			$head["addition"] = intval($data['INVOICENUMBER']);
			$head["notice"] = $data['REQUIREMENT']!='null'?$data['REQUIREMENT']:'';
			$head["transtype"] = ( $this->authname == 'NGWL01'?'空运':'汽运' );  //$orderhead->Transporttype;
			$head["declareValue"] = 0;
			$head["sendFax"] = 0;
			$head["requirementDate"] = $data['DELIVETIME'];
			$head["requirementTime"] = $data['DELIVETIME'];
			$head["userName"] = $data['CREATER']!='null'?$data['CREATER']:'';
			$head["userId"] = '000032';
			$head["company"] = '000032';
			$head["billDate"] = $data['CREATETIME'];

			date_default_timezone_set("Asia/Shanghai");

			//额外信息
			$head["clientMobile"] = '';
			$head["consigneeMobile"] = '';
			$head["getMoney"] = 0;
			$head["cubage"] = 0;
			$head["package"] = '纸箱';
			$head["payType"] = "月结";
			$head["insuranceValue"] = 0;
			$head["signBackType"] = '';
			
			//save head
			if($head['id']){
				$rs = $headmodel->save($head);
			} else {
				$rs = $headmodel->where("not exist")->add($head);
			}
			
			if($rs === false) {
				$this->addLog("save head error");
				return false;
			}
					
			//save detail
			$detail['orderNo'] = $data["ECNO"];
			$detail['businessNo'] = $data["LEGNO"];
			$detail['OUTORDERID'] = $data["OUTORDERID"];
			$detail['LOTNO'] = $data["LOTNO"];
			$detail['GOODSTYPE'] = $data["GOODSTYPE"];
			$detail['TRASNCONDITION'] = $data['TRASNCONDITION'];
			$detail['PRODUCTTYPE'] = $data["PRODUCTTYPE"];
			$detail['QUANTITY'] = intval($data["QUANTITY"]);
			$detail['QUANTITYUNIT'] = intval($data["QUANTITYUNIT"]);
			$detail['QUNITNAME'] = $data["QUNITNAME"];
			$detail['PACKWEIGHT'] = intval($data["PACKWEIGHT"]);
			$detail['CARTONQUANITTY'] = intval($data["CARTONQUANITTY"]);
			$detail['PLACESUPPLYDTLID'] = $data["PLACESUPPLYDTLID"];
			$detail['OWNERGOODSID'] = $data["OWNERGOODSID"];
			
			$detailmodel = new OrderdetailModel();
			$find = $detailmodel->where("orderNo = '".$data['ECNO']."' and businessNo='".$data['LEGNO']."'")->find();
			if(!$find){
				$detailmodel->add($detail);
			} else {
				if(DEBUG){
					$this->debug('duplicate', "found detail".$detail['orderNo'] . " " . $data['LEGNO']);
				}
			}
		} catch (Exception  $e) {
			$this->addLog("save order Exception error".$e->getMessage());
			return false;
		}

		$this->orderno = $data['ECNO'];
		return true;
	}

	/**
	 * 回传信息
	 *
	 */
	public function synup()
	{
		//上传费用
		$this->uploadFee();
		
		//上传签收
		$this->uploadSign();
		
		//上传跟踪轨迹
		$this->uploadTrack();
		
		//上传异常
		$this->uploadException();
		
		// 上传签收图片
		$this->uploadImg();
	}

	/**
	 * 上传签收信息
	 */
	private function uploadSign(){
		$this->create();
		
		//上传签收信息 convert(money,a.cubage)
		$model = new AirwaybillModel();
		/*
		 //南冠适用
		$sql = "SELECT top 100 d.id, w.orderno, a.dno, d.businessNo, isnull(a.EstimatedTime, GETDATE()) as estime, isnull(a.signdate,GETDATE()) as signdate, isnull(a.arriveddate,GETDATE()) as arriveddate, a.signin,
		a.qty, a.weight, convert(money,a.cubage) as cubage, a.netweight, a.price,
		isnull(a.planno,'') as planeno, a.packagemoney, a.delivermoney, a.money, a.insurance, a.othermoney, a.freight
		FROM airwaybill a INNER JOIN web_order w ON a.weborder = w.id INNER JOIN web_orderdetail d ON w.orderno = d.orderno
		WHERE d.synup = 0 and isnull(signin,'') <> '' and d.synupcount <3";
		*/
		$sql = "SELECT top 100 d.id, w.orderno, a.dno, d.businessNo, isnull(a.EstimatedTime, GETDATE()) as estime, isnull(a.signdate,GETDATE()) as signdate, isnull(a.arriveddate,GETDATE()) as arriveddate, a.signin,
					   a.qty, a.weight, convert(money,a.cubage) as cubage, a.netweight, a.price,
					   isnull((select max(planno) from peihuo p where p.sysdno = a.sysdno),'') as planeno, a.packagemoney, a.delivermoney, a.money, a.insurance, a.othermoney, a.freight
		          FROM airwaybill a INNER JOIN web_order w ON a.weborder = w.id INNER JOIN web_orderdetail d ON w.orderno = d.orderno
		         WHERE d.synup = 0 and isnull(signin,'') <> '' and d.synupcount <3 ";
		
		$rs = $model->query($sql);
		if($rs){
			for($i=0; $i < count($rs); $i++){
				$data['orderno'] = $rs[$i]['orderno'];
				$data['dno'] = $rs[$i]['dno'];
				$data['businessNo'] = $rs[$i]['businessNo'];
				$data['estime'] = $rs[$i]['estime'];
				$data['arriveddate'] = $rs[$i]['arriveddate'];
				$data['signdate'] = $rs[$i]['signdate'];
				$data['signin'] = $rs[$i]['signin'];
				$data['qty'] = $rs[$i]['qty'];
				$data['weight'] = $rs[$i]['weight'];
				$data['cubage'] = $rs[$i]['cubage'];
				$data['netweight'] = $rs[$i]['netweight'];
				$data['price'] = $rs[$i]['price'];
				$data['planeno'] = $rs[$i]['planeno'];
				$data['packagemoney'] = $rs[$i]['packagemoney'];
				$data['delivermoney'] = $rs[$i]['delivermoney'];
				$data['insurance'] = $rs[$i]['insurance'];
				$data['othermoney'] = $rs[$i]['othermoney'];
				$data['freight'] = $rs[$i]['freight'];
				$data['money'] = $rs[$i]['money'];
		
				//upload 签收
				if($data['signin']){
					//太经常访问，国药服务器会崩溃的，休息下再说
					$this->sleep4awhile();

					$result = $this->sendToSPL($data);
					if($result === -100){
						$this->addLog($data['dno']." the planeno was empty!");
						continue;
					} else if ($result === 404) {
						//如果服务器过忙, 终止上传
						return;
					} else if($result){
						$model->execute("update web_orderdetail set synup = 1 where id =" . $rs[$i]["id"]);
					}
					//上传次数
					$model->execute("update web_orderdetail set synupcount = synupcount +1 where id =" . $rs[$i]["id"]);
				}
			}
		}
	}
	

	/**
	 * 上传签收信息
	 *
	 */
	private function sendToSPL($data)
	{
		try {
			$content = "<CONTENTLIST>".
			           "<CONTENT>".
			           "<HEADER></HEADER>".
			           "<DETAIL>".
			           "<ECNO>".$data['orderno']."</ECNO>".
			           "<CECNO>".$data['dno']."</CECNO>".
			           "<LEGNO>".$data['businessNo']."</LEGNO>".
			           "<VEHICLENO>".$data['planeno']."</VEHICLENO>".
			           "<PICKTIME>1900-01-01 00:00:00</PICKTIME>".
			           "<PICKPHONE>NULL</PICKPHONE>".
			           "<ESTIME>".$data['estime']."</ESTIME>".
			           "<SIGNPERSON>".$data['signin']."</SIGNPERSON>".
			           "<SIGNTIME>".$data['signdate']."</SIGNTIME>".
			           "<RETURNTIME>".$data['signdate']."</RETURNTIME>".
			           "<ARRIVERDTIME>".$data['arriveddate']."</ARRIVERDTIME>".
			           "</DETAIL>".
			           "</CONTENT>".
			           "</CONTENTLIST>";

			$response = $this->createResult('CarrierSign', $content, 'UPDATE');
		
			if(!trim($data['planeno'])){
				$this->addLog("planeno is empty stop upload");
				return -100;
			}
			//call
			$result = $this->service->call("setTmsShipmentQianshou", array("tmsShipmentQianshouXml"=>$response), '', '');
			if(!$result){
				$this->addLog("the webservice was no result on SendToSPL.");
				$this->addLog($this->service->error_str);
				return false;
			}
			
			$result = $this->checkResult($result);
			
			if(DEBUG){
				$this->debug('uploadsign', htmlspecialchars_decode($response));
				$this->debug('uploadsignresult', print_r($result,true));
			}
			
			if($result === 404){
				$this->addLog("404 in setTmsShipmentQianshou");
				return 404;
			} else if($result === 0){
				$this->addLog("result fault on SendToSPL:".$result);
				
				$this->debug('uploadsign', htmlspecialchars_decode($response));
				$this->debug('uploadsignresult', print_r($result,true));
				
				return false;
			} 
		} catch (Exception $e){
			$this->addLog("call setTmsShipmentQianshou Exception:". $e->getMessage());
			return false;
		}

		return true;
	}

	/**
	 * 上传费用信息
	 */
	private function uploadFee(){
		$this->create();
		
		//上传费用信息
		$model = new AirwaybillModel();
		/*
		 //南冠适用
		$sql = "SELECT top 100 w.id, w.orderno, a.dno, isnull(a.signdate,GETDATE()) as signdate, a.signin,
		a.qty, a.weight, convert(money,a.cubage) as cubage, a.netweight, a.price,
		isnull(a.planno,'') as planeno, a.packagemoney, a.delivermoney, a.money, a.insurance, (a.othermoney + a.mbmoney) as othermoney, a.freight
		FROM airwaybill a INNER JOIN web_order w ON a.weborder = w.id
		WHERE w.feedup = 0 and w.feedupcount <3 and isnull(signin,'') <> ''";
		*/
		//Gy适用
		$sql = "SELECT top 100 w.id, w.orderno, a.dno, isnull(a.signdate,GETDATE()) as signdate, a.signin,
					   a.qty, a.weight, convert(money,a.cubage) as cubage, a.netweight, a.price,
					   a.packagemoney, a.delivermoney, a.money, a.insurance, (a.othermoney + a.mbmoney) as othermoney, a.freight
		          FROM airwaybill a INNER JOIN web_order w ON a.weborder = w.id
		         WHERE w.feedup = 0 and w.feedupcount <3 and isnull(signin,'') <> ''";
		
		$rs = $model->query($sql);
		if($rs){
			for($i=0; $i < count($rs); $i++){
				$data['orderno'] = $rs[$i]['orderno'];
				$data['dno'] = $rs[$i]['dno'];
				$data['signdate'] = $rs[$i]['signdate'];
				$data['signin'] = $rs[$i]['signin'];
				$data['qty'] = $rs[$i]['qty'];
				$data['weight'] = $rs[$i]['weight'];
				$data['cubage'] = $rs[$i]['cubage'];
				$data['netweight'] = $rs[$i]['netweight'];
				$data['price'] = $rs[$i]['price'];
				$data['packagemoney'] = $rs[$i]['packagemoney'];
				$data['delivermoney'] = $rs[$i]['delivermoney'];
				$data['insurance'] = $rs[$i]['insurance'];
				$data['othermoney'] = $rs[$i]['othermoney'];
				$data['freight'] = $rs[$i]['freight'];
				$data['money'] = $rs[$i]['money'];
		
				//太经常访问，国药服务器会崩溃的，休息下再说
				$this->sleep4awhile();
		
				//upload 费用
				$result = $this->sendToSPLFee($data);
				if($result === 404){
					// 如果服务器过忙, 终止上传
					return;
				} else if($result){
					$model->execute("update web_order set feedup = 1 where id=".$rs[$i]["id"]);
				}
								
				//上传次数
				$model->execute("update web_order set feedupcount = feedupcount +1 where id=".$rs[$i]["id"]);
			}
		}
	}
	
	/**
	 * 上传费用信息
	 *
	 */
	private function sendToSPLFee($data){
		try {
			$content = "<CONTENTLIST>".
					   "<CONTENT>".
					   "<HEADER></HEADER>".
					   "<DETAIL>".
					   "<ECNO>".$data['orderno']."</ECNO>".
					   "<CECNO>".$data['dno']."</CECNO>".
					   "<CARTONQUANTITY>".$data['qty']."</CARTONQUANTITY>".
					   "<CHARGWEIGHT>".$data['netweight']."</CHARGWEIGHT>".
					   "<VOLUME>".$data['cubage']."</VOLUME>".
					   "<RATE>".$data['price']."</RATE>".
					   "<PACKAGING>".$data['packagemoney']."</PACKAGING>".
					   "<DISTANCEFEE>".$data['delivermoney']."</DISTANCEFEE>".
					   "<ADDSPORTFEE>0</ADDSPORTFEE>".
					   "<URGENTFEE>0</URGENTFEE>".
					   "<INSURANCEFEE>".$data['insurance']."</INSURANCEFEE>".
					   "<OTHERFEE>".$data['othermoney']."</OTHERFEE>".
					   "<OTHERFEEMARK></OTHERFEEMARK>".
					   "<CARRIAGEFEE>".$data['freight']."</CARRIAGEFEE>".
					   "<TOTALFEE>".$data['money']."</TOTALFEE>".
					   "</DETAIL>".
					   "</CONTENT>".
					   "</CONTENTLIST>";
					   
			$response = $this->createResult('CarrierFee', $content, 'UPDATE');
			$result = $this->service->call("setTmsShipmentFee", array("tmsShipmentFeeXml"=>$response), '', '');
			if(!$result){
				$this->addLog("the webservice was no result on setTmsShipmentFee.");
				$this->addLog($this->service->error_str);
				return false;
			}

			$result = $this->checkResult($result);
			
			if(DEBUG){
				$this->debug('uploadfee', htmlspecialchars_decode($response));
				$this->debug('uploadfeeresult', print_r($result,true));
			}
			
			if($result === 404){
				$this->addLog("404 in setTmsShipmentFee");
				return 404;
			} else if($result === 0){
				$this->addLog("result fault on setTmsShipmentFee:".$result);
				
				$this->debug('uploadfee', htmlspecialchars_decode($response));
				$this->debug('uploadfeeresult', print_r($result,true));
				
				return false;
			} 
		} catch (Exception $e) {
			$this->addLog("call setTmsShipmentFee Exception:".$e->getMessage());
			return false;
		}

		return true;
	}

	/**
	 * 上传跟踪信息
	 */
	private function uploadTrack(){
		$this->create();
		
		//上传跟踪信息
		$model = new OrdertrackModel();
		$sql = "SELECT top 100 t.orderNo, t.dno, t.trackTime, t.operator, t.info, t.id, d.businessNo
				FROM web_ordertrack t INNER JOIN web_orderdetail d ON d.orderNo = t.orderNo
				WHERE t.synup = 0 and t.synupcount < 3 and type = '' ";
		$rs = $model->query($sql);
		if($rs){
			for($i=0; $i < count($rs); $i++){
				$data['orderNo'] = $rs[$i]["orderNo"];
				$data['dno'] = $rs[$i]["dno"];
				$data['businessNo'] = $rs[$i]['businessNo'];
				$data['trackTime'] = $rs[$i]["trackTime"];
				$data['operator'] = $rs[$i]["operator"];
				$data['info'] = $rs[$i]["info"];
				$data['uid'] = $rs[$i]["id"];
		
				//太经常访问，国药服务器会崩溃的，休息下再说
				$this->sleep4awhile();
				
				$result = $this->sendToSPLTrack($data);
				
				if($result === 404){
					return;
				} else if($result){
					$model->execute("update web_ordertrack set synup = 1 where id=".$rs[$i]["id"]);
				}
				
				//上传次数
				$model->execute("update web_ordertrack set synupcount = synupcount +1 where id=".$rs[$i]["id"]);
			}
		}
	}
	
	/**
	 * 上传跟踪信息
	 *
	 */
	private function sendToSPLTrack($data)
	{
		try {
			$content = "<CONTENTLIST>".
					   "<CONTENT>".
					   "<HEADER></HEADER>".
					   "<DETAIL>".
					   "<ECNO>".$data['orderNo']."</ECNO>".
					   "<CECNO>".$data['dno']."</CECNO>".
					   "<LEGNO>".$data['businessNo']."</LEGNO>".
					   "<TRACKTIME>".$data['trackTime']."</TRACKTIME>".
					   "<TRACKPERSON>".$data['operator']."</TRACKPERSON>".
					   "<TRACKINFO>".$data['info']."</TRACKINFO>".
					   "<TRACKTYPE></TRACKTYPE>".
					   "</DETAIL>".
					   "</CONTENT>".
					   "</CONTENTLIST>";

			$response = $this->createResult('CarrierTrackRecord', $content);
			$result = $this->service->call("setTmsShipmentInTransit", array("tmsShipmentInTransitXml"=>$response), '', '');
			if(!$result){
				$this->addLog("the webservice was no result on setTmsShipmentInTransit.");
				$this->addLog($this->service->error_str);
				return false;
			}

			$result = $this->checkResult($result);
			
			if(DEBUG){
				$this->debug("uploadtrack", htmlspecialchars_decode($response));
				$this->debug("uploadtrackresult", print_r($result,true));
			}
			
			if($result === 404){
				$this->addLog("404 in setTmsShipmentInTransit");
				return 404;
			} else if($result === 0){
				$this->addLog("result fault on setTmsShipmentInTransit:".$result);
				return false;
			} 
			
		} catch (Exception $e) {
			$this->addLog("call setTmsShipmentInTransit Exception:".$e->getMessage());
			return false;
		}

		return true;
	}
	
	/**
	 * 上传异常信息
	 */
	private function uploadException()
	{
		$this->create();
		
		//上传跟踪信息
		$model = new OrdertrackModel();
		$sql = "SELECT top 100  t.orderNo, t.dno, t.trackTime, t.operator, t.info, t.id, t.LEGNO, isnull(t.EXQUANTITY,0) as EXQUANTITY, 
						t.EXCODE, t.EXCAUSECODE, isnull(t.EXDESC,'') as EXDESC, isnull(t.EXTIME, GETDATE()) as EXTIME, 
						isnull(t.BACKTIME, GETDATE()) as BACKTIME,
						d.PLACESUPPLYDTLID, d.OWNERGOODSID, d.GOODSTYPE as GOODSNAME, d.LOTNO, d.QUANTITYUNIT,
				 		d.PLACESUPPLYDTLID, d.OWNERGOODSID, OUTORDERID
				FROM web_ordertrack t INNER JOIN web_orderdetail d ON d.orderNo = t.orderNo and d.businessNo = t.LEGNO
				WHERE t.synup = 0 and t.synupcount < 3 and type <> ''";
		$rs = $model->query($sql);
		if($rs){
			for($i=0; $i < count($rs); $i++){
				$data['orderNo'] = $rs[$i]["orderNo"];
				$data['dno'] = $rs[$i]["dno"];
				$data['trackTime'] = $rs[$i]["trackTime"];
				$data['operator'] = $rs[$i]["operator"];
				$data['info'] = $rs[$i]["info"];
				$data['uid'] = $rs[$i]["id"];
				$data['OUTORDERID'] = $rs[$i]['OUTORDERID'];
				$data['LEGNO'] = $rs[$i]['LEGNO'];
				$data['PLACESUPPLYDTLID'] = $rs[$i]['PLACESUPPLYDTLID'];
				$data['OWNERGOODSID'] = $rs[$i]['OWNERGOODSID'];
				$data['GOODSNAME'] = $rs[$i]['GOODSNAME'];
				$data['LOTNO'] = $rs[$i]['LOTNO'];
				$data['EXQUANTITY'] = $rs[$i]['EXQUANTITY'];
				$data['QUANTITYUNIT'] = $rs[$i]['QUANTITYUNIT'];
				$data['EXCODE'] = $rs[$i]['EXCODE'];
				$data['EXCAUSECODE'] = $rs[$i]['EXCAUSECODE'];
				$data['EXDESC'] = $rs[$i]['EXDESC'];
				$data['EXTIME'] = $rs[$i]['EXTIME'];
				$data['BACKTIME'] = $rs[$i]['BACKTIME'];
		
				//太经常访问，国药服务器会崩溃的，休息下再说
				$this->sleep4awhile();
				
				$result = $this->setTmsShipmentQSException($data);
				if($result === 404){
					return;
				} else if($result){
					$model->execute("update web_ordertrack set synup = 1 where id=".$rs[$i]["id"]);
				}
				//上传次数
				$model->execute("update web_ordertrack set synupcount = synupcount +1 where id=".$rs[$i]["id"]);
			}
		}
	}
	
	//setTmsShipmentQSException
	private function setTmsShipmentQSException($data)
	{
		try {
			$content = "<CONTENTLIST>".
						"<CONTENT>".
						"<HEADER>".
						"</HEADER>".
						"<DETAIL>".
						"<OUTORDERID>".$data['OUTORDERID']."</OUTORDERID>".
						"<ECNO>".$data['orderNo']."</ECNO>".
						"<LEGNO>".$data['LEGNO']."</LEGNO>".
						"<PLACESUPPLYDTLID>".$data['PLACESUPPLYDTLID']."</PLACESUPPLYDTLID>".
						"<OWNERGOODSID>".$data['OWNERGOODSID']."</OWNERGOODSID>".
						"<GOODSNAME>".$data['GOODSNAME']."</GOODSNAME>".
						"<LOTNO>".$data['LOTNO']."</LOTNO>".
						"<EXQUANTITY>".$data['EXQUANTITY']."</EXQUANTITY>".
						"<QUANTITYUNIT>".$data['QUANTITYUNIT']."</QUANTITYUNIT>".
						"<EXCODE>".$data['EXCODE']."</EXCODE>".
						"<EXCAUSECODE>".$data['EXCAUSECODE']."</EXCAUSECODE>".
						"<EXDESC>".$data['EXDESC']."</EXDESC>".
						"<EXTIME>".$data['EXTIME']."</EXTIME>".
						"<BACKTIME>".$data['BACKTIME']."</BACKTIME>".
						"</DETAIL>".
						"</CONTENT>".
						"</CONTENTLIST>";
		
			foreach ($data as $key=>$val){
				if($key==='EXDESC'){
					continue;
				} else {
					if(!$val){
						$this->addLog("$key is need bu it was not.");
						return -1;
					}
				}
			}
		
			$response = $this->createResult('SignException ', $content);
			$result = $this->service->call("setTmsShipmentQSException", array("tmsShipmentQSExceptionXml"=>$response), '', '');
			if(!$result){
				$this->addLog("the webservice was no result on setTmsShipmentQSException.");
				$this->addLog($this->service->error_str);
				return false;
			}
		
			$result = $this->checkResult($result);
			
			if(DEBUG){
				$this->debug("uploadsignexception", htmlspecialchars_decode($response));
				$this->debug("uploadsignexceptionresult", print_r($result,true));
			}
			
			if($result === 404){
				$this->addLog("404 in setTmsShipmentQSException");
				return 404;
			} else if($result === 0){
				$this->addLog("result fault on setTmsShipmentQSException:".$result);
				return false;
			}
		} catch (Exception $e) {
			$this->addLog("call setTmsShipmentQSException Exception:".$e->getMessage());
			return false;
		}
		
		return true;		
	}

	/**
	 * 上传签收图片
	 *
	 */
	private function uploadImg()
	{
		$this->create();

		//上传签收信息 convert(money,a.cubage)
		$model = new AirwaybillModel();
		$sql = "SELECT top 100 d.id, w.orderno, w.company, a.dno, d.businessNo
		          FROM airwaybill a INNER JOIN web_order w ON a.weborder = w.id INNER JOIN web_orderdetail d ON w.orderno = d.orderno
		         WHERE d.synupsign = 0 and d.synupsigncount < 3";

		$rs = $model->query($sql);		
		if($rs){
			for($i=0; $i < count($rs); $i++){
				$data['orderno'] = $rs[$i]['orderno'];
				$data['dno'] = $rs[$i]['dno'];
				$data['businessNo'] = $rs[$i]['businessNo'];
				$data['company'] = $rs[$i]['company'];

				//太经常访问，国药服务器会崩溃的，休息下再说
				$this->sleep4awhile();
				
				//upload 签收
				$result = $this->sendToSPLImage($data);
				if($result === 404){
					return;
				} else if($result === -100){
					$this->addLog("the img file was not existed!");
					continue;
				} else if($result){
					$model->execute("update web_orderdetail set synupsign = 1 where id =" . $rs[$i]["id"]);
				}
				//上传次数
				$model->execute("update web_orderdetail set synupsigncount = synupsigncount +1 where id =" . $rs[$i]["id"]);
			}
		}
	}
	
	/**
	 * 上传图片
	 */
	private function sendToSPLImage($data)
	{
		$filepath = "D:/GYIP.WLPT.Business/Sybase/EAServer/bin/sign";
		try {
			$filename = $filepath .'/'.$data['company'].'/'.$data['orderno'].".".$data['businessNo'].".jpg";
			if(!file_exists($filename)){
				return -100;
			}
			
			ob_start();
			readfile($filename);
			$img = ob_get_contents();
			ob_end_clean();
			$size = strlen($img);
			/*
			$size = filesize($filename);
			$img = fread(fopen($filename,'rb'),$size);
			*/
			/*
			$info = getimagesize($filename);
			//var_dump($info);
			header("content-type:".$info['mime']);
			echo $img;
			return;
			*/
			
			$img = base64_encode($img);
			
			$content = "<CONTENTLIST>
					<CONTENT>
					<HEADER></HEADER>
					<DETAILLIST>
					<DETAIL>
					<ECNO>".$data['orderno']."</ECNO>
					<LEGNO>".$data['businessNo']."</LEGNO>
					<FLAG>base</FLAG>
					<AFILEBYTES>".$img."</AFILEBYTES>
					</DETAIL>
					</DETAILLIST>
					</CONTENT> 
					</CONTENTLIST>";
		
			$response = $this->createResult('Sign Exception', $content);
			$result = $this->service->call("setTmsShipmentBackImage", array("tmsShipmentBackImageXml"=>$response), '', '');
			if(!$result){
				$this->addLog("the webservice was no result on setTmsShipmentBackImage.");
				$this->addLog($this->service->error_str);
				return false;
			}
		
			$result = $this->checkResult($result);
			if($result === 404){
				$this->addLog("404 in setTmsShipmentBackImage");
				return 404;
			} else if($result === 0){
				$this->addLog("result fault on setTmsShipmentBackImage:".$result);
				return false;
			} else {
				if(DEBUG){
					$this->debug("uploadimg", htmlspecialchars_decode($response));
					$this->debug("uploadimgresult", print_r($result, true));
				}
			}
		} catch (Exception $e) {
			$this->addLog("call setTmsShipmentBackImage Exception:".$e->getMessage());
			return false;
		}
		
		return true;
}

	/**
	 * 建立返回值XMS string
	 *
	 */
	private function createResult($contenttype, $contentdetail, $type='ADD'){
		$date = date('Y-m-d H:i:s');
		$result = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>".
		"<XML>".
		"<MESSAGEHEAD>".
		"<SENDER>".$this->authname."</SENDER>".
		"<FILETYPE>XML</FILETYPE>".
		"<CONTENTTYPE>".$contenttype."</CONTENTTYPE>".
		"<SENDTIME>".$date."</SENDTIME>".
		"<FILENAME>".str_replace(' ','',str_replace(':','',str_replace('-','',$date)))."</FILENAME>".
		"<FILEFUNCTION>".$type."</FILEFUNCTION>".
		"</MESSAGEHEAD>".
		"<MESSAGEDETAIL>".
		"<![CDATA[<?xml version=\"1.0\" encoding=\"UTF-8\"?>".
		"<XML>".$contentdetail."</XML>".
		"]]>".
		"</MESSAGEDETAIL>".
		"</XML>";
		//进行编码
		$result = htmlentities($result, ENT_NOQUOTES, "UTF-8");
		return $result;
	}
	
	/**
	 * checkResult
	 * 
	 * parse xml result
	 *
	 * @param string $xml
	 */
	private function checkResult($data){
		$xml = simplexml_load_string($data);
		
		if(DEBUG){
			$this->debug("serviceresult", $data);
		}
		
		$result = intval($xml->MSGCODE);
		if($result !== 1){
			$this->addLog($xml->MSGCONTENT);
			$this->error = $xml->MSGCONTENT;
		}
		
		return $result;
	}
	
	/**
	 * 太经常访问，国药服务器会崩溃的，休息下再说
	 */
	private function sleep4awhile(){
		$seconds = rand(6);
		
		if(DEBUG){
			$this->addLog("i am sleep for $seconds seconds<br>");
		}
		
		sleep($seconds);
		
		return true;
	}

	/**
	 * 记录日志
	 *
	 * @param string $data
	 */
	private function addLog($data) {
		Log::record($data,Log::ERR);
		Log::save();
		return ;
	}
	
	/**
	 * debug output
	 * @param string $data
	 */
	private function debug($filename, $data) {	
		$destination = LOG_PATH."$filename.log";
		$file=fopen($destination,"a+");
		fwrite($file, $filename."\r\n");
		fwrite($file, $data);
		fwrite($file, "\r\n");
		fclose($file);
	}
}
?>