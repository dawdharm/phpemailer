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

// Check if rem file directory is writable
if(!is_writable(__DIR__)){
	echo "\nError: Directory ".__DIR__." is not writable. Cannot create tracking files.\n";
	exit(1);
}

// If rem file exists, check if it's writable
if(file_exists($remFile) && !is_writable($remFile)){
	echo "\nError: File ".$remFile." is not writable. Please check permissions.\n";
	exit(1);
}

echo "\nFile Size: ".$fileSize."\n";
//Get the size and read position of the last memory log file
$lastSize = 0;
$lastPos  = 0;
if(file_exists($remFile)){
	$hd = fopen($remFile,'r+');
	$str = fread($hd,1024);
	$arr = explode(',',$str);
	$lastSize = isset($arr[0]) ? $arr[0] : 0;
	$lastPos  = isset($arr[1]) ? $arr[1] : 0;
	fclose($hd);
} else {
	// If rem file doesn't exist, start reading current log from beginning
	echo "Rem file not found - will read current mail.log from position 0\n";
	$lastSize = 0;
	$lastPos = 0;
}
// open the rem file in overwrite mode
$hd = fopen($remFile,'w+');
if(!$hd){
	echo "\nError: Cannot create/open rem file ".$remFile."\n";
	exit(1);
}
//If the log file is truncated, the read position is reset to 0
if($fileSize <$lastSize){
	echo "The log file is truncated, the read position is reset to 0\n";
	echo "Last Size: ".$lastSize."\n";
	echo "File Size: ".$fileSize."\n";
   $lastPos = 0;
}
echo "Last Position: ".$lastPos."\n";
echo "Last Size: ".$lastSize."\n";
//Lock file with PID tracking
echo $lockFile = __DIR__.'/mail.lock';
echo "\n";
//Check if lock file exists and if the process is actually running
if(file_exists($lockFile)){
	$lockAge = time() - filemtime($lockFile);
	$lockContent = file_get_contents($lockFile);
	$lockPid = intval(trim($lockContent));

	// Check if the process is actually running (Linux only)
	$processRunning = false;
	if($lockPid > 0 && file_exists("/proc/".$lockPid)){
		$processRunning = true;
	}

	// If lock is less than 5 minutes old and process is running, exit
	if($lockAge < 300 && $processRunning){
		echo "The program is already running (PID: ".$lockPid.", lock file age: ".$lockAge." seconds)\n";
		exit;
	} else {
		if(!$processRunning && $lockPid > 0){
			echo "Removing stale lock file (PID: ".$lockPid." not running, age: ".$lockAge." seconds)\n";
		} else {
			echo "Removing expired lock file (age: ".$lockAge." seconds)\n";
		}
		unlink($lockFile);
	}
}
// Write current process ID to lock file
file_put_contents($lockFile, getmypid());

// Track which rotated log files have been processed
$processedRotatedLogsFile = __DIR__.'/processed_rotated_logs.json';
$processedRotatedLogs = array();
if(file_exists($processedRotatedLogsFile)){
	// Check if file is writable
	if(!is_writable($processedRotatedLogsFile)){
		echo "\nError: File ".$processedRotatedLogsFile." is not writable. Please check permissions.\n";
		unlink($lockFile);
		exit(1);
	}
	$content = file_get_contents($processedRotatedLogsFile);
	$processedRotatedLogs = json_decode($content, true);
	if(!is_array($processedRotatedLogs)){
		$processedRotatedLogs = array();
	}
}

// If processed_rotated_logs.json doesn't exist, reset and process all rotated log files from scratch
if(!file_exists($processedRotatedLogsFile)){
	echo "========================================\n";
	echo "FRESH START: Tracking file not found\n";
	echo "Resetting and processing all rotated log files from scratch\n";
	echo "========================================\n";
	$rotatedFiles = getRotatedLogFiles($logFile);

	if(count($rotatedFiles) > 0){
		echo "Found ".count($rotatedFiles)." rotated log files to process\n";
		// Process from oldest to newest (reverse the array)
		$rotatedFiles = array_reverse($rotatedFiles);

		foreach($rotatedFiles as $rotatedFile){
			$fileKey = basename($rotatedFile['path']);
			// Process the file (no need to check if already processed since we're starting fresh)
			processLogFile($rotatedFile['path'], $rotatedFile['compressed'], $apiUrl, $domain_id);
			// Mark as processed
			$processedRotatedLogs[$fileKey] = array(
				'processed_at' => date('Y-m-d H:i:s'),
				'file_path' => $rotatedFile['path']
			);
			// Save processed logs tracking file after each file
			file_put_contents($processedRotatedLogsFile, json_encode($processedRotatedLogs, JSON_PRETTY_PRINT));
		}
		echo "Completed processing ".count($rotatedFiles)." rotated log files\n";
	} else {
		echo "No rotated log files found - creating empty tracking file\n";
		// Create empty tracking file
		file_put_contents($processedRotatedLogsFile, json_encode(array(), JSON_PRETTY_PRINT));
	}
	echo "========================================\n";
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

		// Prefer .gz file if both exist (avoid processing same log twice)
		if(file_exists($gzFile)){
			$rotatedFiles[] = array('path' => $gzFile, 'number' => $i, 'compressed' => true);
		} elseif(file_exists($file)){
			$rotatedFiles[] = array('path' => $file, 'number' => $i, 'compressed' => false);
		}
	}

	// Sort by number in ascending order (oldest first, will be reversed later)
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

		//Get the timestamp from the log line (format: Jan 17 14:30:45)
		$logDate = '';
		$logTimestamp = '';
		if(preg_match('/^(\w{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})/',$content,$match)){
			$logDate = $match[1];
			// Parse the date and add current year (postfix logs don't include year)
			$currentYear = date('Y');
			try {
				$dateTime = DateTime::createFromFormat('M d H:i:s', $logDate);
				if($dateTime){
					$dateTime->setDate($currentYear, $dateTime->format('m'), $dateTime->format('d'));
					// Check if the date is in the future (happens around year boundary)
					if($dateTime->getTimestamp() > time()){
						$dateTime->setDate($currentYear - 1, $dateTime->format('m'), $dateTime->format('d'));
					}
					$logTimestamp = $dateTime->format('Y-m-d H:i:s');
				}
			} catch(Exception){
				// If date parsing fails, use current timestamp
				$logTimestamp = date('Y-m-d H:i:s');
			}
		}
		if(empty($logTimestamp)){
			$logTimestamp = date('Y-m-d H:i:s');
		}

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
		return array(
			'email'=>$email,
			'status'=>$status,
			'dsn'=>$dsn,
			'reason'=>$reason,
			'relay'=>$relay,
			'domainId'=>$domain_id,
			'logDate'=>$logTimestamp,
			'version'=>2
		);
	}
	return null;
}
