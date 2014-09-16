<?php
define('APP_NAME', 'APP'); 
define('APP_PATH', realpath(".")); 
// 定义ThinkPHP框架路径 
define('THINK_PATH', APP_PATH.'/../ThinkPHP'); 

// 加载框架入口文件  
require(THINK_PATH."/ThinkPHP.php"); 

//实例化一个网站应用实例 
$App = new App();  
$App->init();

vendor("WBService",LIB_PATH."/Service");

ini_set("soap.wsdl_cache_enabled", "0");
$server = new SoapServer(LIB_PATH."/Service/WebBusiness.wsdl", array('soap_version' => SOAP_1_2));   
$server->setClass("WBService");   
$server->handle();

if(C('LOG_RECORD')) Log::save();
?>