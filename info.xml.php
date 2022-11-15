<?php
class DiskStatus {
  const RAW_OUTPUT = true;
  private $diskPath;
  function __construct($diskPath) {
    $this->diskPath = $diskPath;
  }
  public function totalSpace($rawOutput = false) {
    $diskTotalSpace = @disk_total_space($this->diskPath);
    if ($diskTotalSpace === FALSE) {
      throw new Exception('totalSpace(): Invalid disk path.');
    }
    return $rawOutput ? $diskTotalSpace : $this->addUnits($diskTotalSpace);
  }
  public function freeSpace($rawOutput = false) {
    $diskFreeSpace = @disk_free_space($this->diskPath);
    if ($diskFreeSpace === FALSE) {
      throw new Exception('freeSpace(): Invalid disk path.');
    }
    return $rawOutput ? $diskFreeSpace : $this->addUnits($diskFreeSpace);
  }
  public function usedSpace($precision = 1) {
    try {
      return round((100 - ($this->freeSpace(self::RAW_OUTPUT) / $this->totalSpace(self::RAW_OUTPUT)) * 100), $precision);
    } catch (Exception $e) {
      throw $e;
    }
  }
  public function getDiskPath() {
    return $this->diskPath;
  }
  private function addUnits($bytes) {
    $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
    for($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++ ) {
      $bytes /= 1024;
    }
    return round($bytes, 1).' '.$units[$i];
  }
}
$diskStatus = new DiskStatus('./');

header('content-type: text/xml');

echo "<?xml version=\"1.0\"?>\n";
echo "<response>\n";
 echo "\t<patchlevel>5.8.6</patchlevel>\n";
 echo "\t<database_host></database_host>\n";
 echo "\t<database_name></database_name>\n";
 echo "\t<tableprefix></tableprefix>\n";
 echo "\t<application_url></application_url>\n";
 echo "\t<tracking></tracking>\n";
 echo "\t<analytics></analytics>\n";
 echo "\t<analytics_url></analytics_url>\n";
 echo "\t<trapcheck></trapcheck>\n";
 echo "\t<privacy_company></privacy_company>\n";
 echo "\t<privacy_domain></privacy_domain>\n";
 //echo "\t<process_url>".SENDSTUDIO_PROCESS_URL."</process_url>\n";
 echo "\t<IP>".$_SERVER['SERVER_ADDR']."</IP>\n";
 echo "\t<total_space>".$diskStatus->totalSpace()."</total_space>\n";
 echo "\t<free_space>".$diskStatus->freeSpace()."</free_space>\n";
 echo "\t<used_space>".$diskStatus->usedSpace()."%</used_space>\n";

$fp = fsockopen("localhost", 25, $errno, $errstr, 30);
if (!$fp) {
    echo "\t<SMTP>".$errstr."</SMTP>\n";
} else {
    echo "\t<SMTP>OK</SMTP>\n";
}
echo "</response>";
?>
