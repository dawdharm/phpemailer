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
// Check which log file exists (Ubuntu uses mail.log, RHEL/CentOS uses maillog)
$logFile = '';
if(file_exists(ROOT.'mail.log')){
	$logFile = ROOT.'mail.log';
} elseif(file_exists(ROOT.'maillog')){
	$logFile = ROOT.'maillog';
} else {
	echo "Error: No mail log file found at ".ROOT."mail.log or ".ROOT."maillog\n";
	exit(1);
}
echo "Using log file: ".$logFile."\n";
$fileSize = filesize($logFile);
//cache filesize for next time
echo $remFile  = __DIR__.'/mail.rem';

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
if(file_exists($lockFile)){
	$lockAge = time() - filemtime($lockFile);
	if($lockAge < 1800){
		echo "The program is already running (lock file age: ".$lockAge." seconds)\n";
		exit;
	} else {
		echo "Removing stale lock file (age: ".$lockAge." seconds)\n";
		unlink($lockFile);
	}
}
touch($lockFile);

// Track which rotated log files have been processed
$processedRotatedLogsFile = __DIR__.'/processed_rotated_logs.json';
$processedRotatedLogs = array();
if(file_exists($processedRotatedLogsFile)){
	$content = file_get_contents($processedRotatedLogsFile);
	$processedRotatedLogs = json_decode($content, true);
	if(!is_array($processedRotatedLogs)){
		$processedRotatedLogs = array();
	}
}

// If this is the first run (no position saved), process rotated log files
if($lastPos == 0 && $lastSize == 0){
	echo "First run detected - processing rotated log files\n";
	$rotatedFiles = getRotatedLogFiles($logFile);

	if(count($rotatedFiles) > 0){
		echo "Found ".count($rotatedFiles)." rotated log files\n";
		// Process from oldest to newest (reverse the array)
		$rotatedFiles = array_reverse($rotatedFiles);

		foreach($rotatedFiles as $rotatedFile){
			$fileKey = basename($rotatedFile['path']);
			// Only process if not already processed
			if(!isset($processedRotatedLogs[$fileKey])){
				processLogFile($rotatedFile['path'], $rotatedFile['compressed'], $apiUrl, $domain_id);
				// Mark as processed
				$processedRotatedLogs[$fileKey] = array(
					'processed_at' => date('Y-m-d H:i:s'),
					'file_path' => $rotatedFile['path']
				);
				// Save processed logs tracking file
				file_put_contents($processedRotatedLogsFile, json_encode($processedRotatedLogs, JSON_PRETTY_PRINT));
			} else {
				echo "Skipping already processed file: ".$rotatedFile['path']."\n";
			}
		}
	} else {
		echo "No rotated log files found\n";
	}
}

//Open the log file
$hdLog = new SplFileObject($logFile);
if($lastPos >0){
	$hdLog->seek($lastPos);
	$hdLog->next();
}else {
	$hdLog->seek(0);
}
//Process the main log file from the last position
echo "Processing main log file from position ".$lastPos."\n";
while(!$hdLog->eof()){
	$content = $hdLog->current();
	$result = processLogLine($content, $domain_id);
	if($result){
		//Post the result to the server
		postData($apiUrl, $result);
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

/**
 * Get all rotated log files in reverse chronological order
 * Returns files like mail.log.1, mail.log.2.gz, mail.log.3.gz, etc.
 * @param string $baseLogFile - The base log file path (e.g., /var/log/mail.log)
 * @return array - Array of rotated log files sorted from newest to oldest
 */
function getRotatedLogFiles($baseLogFile){
	$rotatedFiles = array();
	$dir = dirname($baseLogFile);
	$baseName = basename($baseLogFile);

	// Look for numbered rotated files (mail.log.1, mail.log.2, etc.)
	for($i = 1; $i <= 50; $i++){
		$file = $dir.'/'.$baseName.'.'.$i;
		$gzFile = $file.'.gz';

		if(file_exists($file)){
			$rotatedFiles[] = array('path' => $file, 'number' => $i, 'compressed' => false);
		}
		if(file_exists($gzFile)){
			$rotatedFiles[] = array('path' => $gzFile, 'number' => $i, 'compressed' => true);
		}
	}

	// Sort by number in descending order (newest first)
	usort($rotatedFiles, function($a, $b){
		return $a['number'] - $b['number'];
	});

	return $rotatedFiles;
}

/**
 * Process a log file and extract bounce/deferred email data
 * @param string $filePath - Path to the log file
 * @param bool $isCompressed - Whether the file is gzip compressed
 * @param string $apiUrl - API URL to post data to
 * @param int $domain_id - Domain ID
 * @return int - Number of bounced/deferred emails found
 */
function processLogFile($filePath, $isCompressed, $apiUrl, $domain_id){
	echo "Processing file: ".$filePath."\n";
	$count = 0;

	if($isCompressed){
		// Read compressed file
		$gz = gzopen($filePath, 'r');
		if(!$gz){
			echo "Error: Could not open compressed file ".$filePath."\n";
			return 0;
		}

		while(!gzeof($gz)){
			$content = gzgets($gz);
			$result = processLogLine($content, $domain_id);
			if($result){
				postData($apiUrl, $result);
				$count++;
			}
		}
		gzclose($gz);
	} else {
		// Read regular file
		$hdLog = new SplFileObject($filePath);
		$hdLog->seek(0);

		while(!$hdLog->eof()){
			$content = $hdLog->current();
			$result = processLogLine($content, $domain_id);
			if($result){
				postData($apiUrl, $result);
				$count++;
			}
			$hdLog->next();
		}
	}

	echo "Found ".$count." bounced/deferred emails in ".$filePath."\n";
	return $count;
}

/**
 * Process a single log line and extract bounce/deferred email data
 * @param string $content - Log line content
 * @param int $domain_id - Domain ID
 * @return array|null - Extracted data or null if not a bounce/deferred line
 */
function processLogLine($content, $domain_id){
	// Look for bounce email log
	if(preg_match('/status=(bounced|deferred)/',$content)){
		echo $content."\n";
		//Get the email address
		preg_match('/to=<([^>]*)>/',$content,$match);
		$email = isset($match[1]) ? $match[1] : '';
		//Get the status
		preg_match('/status=([^\s]*)\s/',$content,$match);
		$status = isset($match[1]) ? $match[1] : '';
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
		return array('email'=>$email,'status'=>$status, 'dsn'=>$dsn, 'reason'=>$reason, 'relay'=>$relay, 'domainId'=>$domain_id);
	}
	return null;
}
