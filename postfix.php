<?php
//get domain id from json file
$domain_id = 0;
$domainIdFile = __DIR__.'/domain_id.json';
if(file_exists($domainIdFile)){
	$fp = fopen($domainIdFile, 'r');
	$domain = json_decode(fread($fp, filesize($domainIdFile)), true);
	fclose($fp);
	$domain_id = $domain['domain_id'];
}

//get API URL from config file or use default production URL
$apiUrl = 'https://api.pay-per-lead.co.uk/postfix/log';
$apiConfigFile = __DIR__.'/api_config.json';
if(file_exists($apiConfigFile)){
	$fp = fopen($apiConfigFile, 'r');
	$apiConfig = json_decode(fread($fp, filesize($apiConfigFile)), true);
	fclose($fp);
	if(isset($apiConfig['api_url'])){
		$apiUrl = $apiConfig['api_url'];
	}
}

/**
 * PHP Postfix log analyzer main file
 *
 **/
//Set the time limit to 0
set_time_limit(0);
//Set the memory limit to 0
ini_set('memory_limit',0);
//Set the default timezone
date_default_timezone_set('Europe/London');

define('ROOT',DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'log'.DIRECTORY_SEPARATOR);
//log file path - For Plesk 'maillog' other servers 'mail.log'
$logFile  = ROOT.'maillog';
$fileSize = filesize($logFile);
//cache filesize for next time
//echo $remFile  = __DIR__.'/mail.rem';
echo $remFile  = ROOT.'/mail.rem';

echo "File Size: ".$fileSize."\n";
//Get the size and read position of the last memory log file
$lastSize = 0;
$lastPos  = 0;
if(file_exists($remFile)){
	$hd = fopen($remFile,'r+');
	$str = fread($hd,1024);
	$arr = explode(',',$str);
	$lastSize = $arr[0];
	$lastPos  = $arr[1];
	fclose($hd);
}
// open the rem file in overwrite mode
$hd = fopen($remFile,'w+');
//If the log file is truncated, the read position is reset to 0
if($fileSize <$lastSize){
	echo "The log file is truncated, the read position is reset to 0\n";
	echo "Last Size: ".$lastSize."\n";
	echo "File Size: ".$fileSize."\n";
   $lastPos = 0;
}
echo "Last Position: ".$lastPos."\n";
echo "Last Size: ".$lastSize."\n";
//Lock file
echo $lockFile = __DIR__.'/mail.lock';
echo "\n";
//If the lock file exists and the creation time is less than 30 minutes, the program is running
if(file_exists($lockFile) && (time()-filemtime($lockFile))<1800){
	echo "The program is already running\n";
	exit;
}
touch($lockFile);
//Open the log file
$hdLog = new SplFileObject($logFile);
if($lastPos >0){
	$hdLog->seek($lastPos);
	$hdLog->next();
}else {
	$hdLog->seek(0);
}
//The format of the result array is array('msgID'=>array('email'=>'status'));
$ret = array();
while(!$hdLog->eof()){
	$content = $hdLog->current();
	// Loof for bounce email log
	if(preg_match('/status=(bounced|deferred)/',$content)){
		echo $content."\n";
		//Get the email address
		preg_match('/to=<([^>]*)>/',$content,$match);
		$email = $match[1];
		//Get the status
		preg_match('/status=([^\s]*)\s/',$content,$match);
		$status = $match[1];
		//Get the mail dsn code
		preg_match('/dsn=\s*(\d\.\d\.\d)/',$content,$match);
		$dsn = isset($match[1])?$match[1]:'';
		//GET the relay details
		preg_match('/relay=([^,]*),/',$content,$match);
		$relay = isset($match[1])?$match[1]:'';
		//Get the bounce email reason
		preg_match('/(said|refused to talk to me):\s*(.*)$/',$content,$match);
		$reason = isset($match[2])?$match[2]:'';
		//Save the result
		$ret = array('email'=>$email,'status'=>$status, 'dsn'=>$dsn, 'reason'=>$reason, 'relay'=>$relay, 'domainId'=>$domain_id);
		//Post the result to the server
		$ret = postData($apiUrl,$ret);
	}
	$hdLog->next();
}
//truncate the rem file and Save the read position and file size to the $hd file resource
fwrite($hd,$fileSize.','.$hdLog->key());
fclose($hd);
unlink($lockFile);

/**
 * function to post data to the server $data is the data to be posted
 * @param $url
 * @param $data
 * 
 */
function postData($url,$data){
	$ch = curl_init();
	curl_setopt($ch,CURLOPT_URL,$url);
	curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
	curl_setopt($ch,CURLOPT_POST,1);
	curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
	$output = curl_exec($ch);
	curl_close($ch);
	return $output;
}
