<?php
/* Don't remove this line. WFSOURCEVISIBLE */
define('WORDFENCE_SCAN_MODE', true); //So that we can include wordfence.php and get the version constant
ignore_user_abort(true);
$wordfence_wp_version = false;
if ( !defined('ABSPATH') ) {
	/** Set up WordPress environment */
	if($_SERVER['SCRIPT_FILENAME']){
		$wfBaseDir = preg_replace('/[^\/]+\/[^\/]+\/[^\/]+\/wfscan\.php$/', '', $_SERVER['SCRIPT_FILENAME']);
		require_once($wfBaseDir . 'wp-load.php');
		global $wp_version;
		global $wordfence_wp_version;
		require($wfBaseDir . 'wp-includes/version.php');
		$wordfence_wp_version = $wp_version;
	} else {
		require_once('../../../wp-load.php');
		require_once('../../../wp-includes/version.php');
	}
}
require_once('wordfence.php');
require_once('lib/wordfenceConstants.php');
require_once('lib/wfScanEngine.php');

class wfScan {
	public static $debugMode = false;
	public static function wfScanMain(){
		$db = new wfDB();
		if($db->errorMsg){
			self::errorExit("Could not connect to database to start scan: " . $db->errorMsg);
		}
		if(! wordfence::wfSchemaExists()){
			self::errorExit("Looks like the Wordfence database tables have been deleted. You can fix this by de-activating and re-activating the Wordfence plugin from your Plugins menu.");
		}
		if(wfUtils::isAdmin() && $_GET['debugMode'] == '1'){
			header('Content-type: text/plain');
			wordfence::status(1, 'info', "Running in debug mode and writing directly to browser.");
			if(! wp_verify_nonce($_GET['nonce'], 'wp-ajax')){
				echo("The security key (nonce) provided for this debug scan is invalid. Please close this window, refresh your options page and try again.");
				exit();
			}
			self::$debugMode = true;
			wordfence::$printStatus = true;
		} else {
			wordfence::status(4, 'info', "Scan engine received request.");
			wordfence::status(4, 'info', "Checking cronkey header");
			if(! $_SERVER['HTTP_X_WORDFENCE_CRONKEY']){ 
				self::errorExit("The Wordfence scanner did not receive the x_wordfence_cronkey secure header.");
			}
			wordfence::status(4, 'info', "Fetching stored cronkey for comparison.");
			$currentCronKey = wfConfig::get('currentCronKey', false);
			if(! $currentCronKey){
				self::errorExit("Wordfence could not find a saved cron key to start the scan.");
			}

			wordfence::status(4, 'info', "Exploding stored cronkey"); 
			$savedKey = explode(',',$currentCronKey);
			if(time() - $savedKey[0] > 60){ 
				self::errorExit("The key used to start a scan has expired.");
			} //keys only last 60 seconds and are used within milliseconds of creation
			wordfence::status(4, 'info', "Checking saved cronkey against cronkey header");
			if($savedKey[1] != $_SERVER['HTTP_X_WORDFENCE_CRONKEY']){ 
				self::errorExit("Wordfence could not start a scan because the cron key does not match the saved key.");
			}
			wordfence::status(4, 'info', "Deleting stored cronkey");
			wfConfig::set('currentCronKey', '');
		}

		ini_set('max_execution_time', 1800); //30 mins
		wordfence::status(4, 'info', "Becoming admin for scan");
		self::becomeAdmin();

		wordfence::status(4, 'info', "Checking if scan is already running");
		if(! wfUtils::getScanLock()){
			self::errorExit("There is already a scan running.");
		}
		wordfence::status(4, 'info', "Requesting max memory");
		wfUtils::requestMaxMemory();
		wordfence::status(4, 'info', "Setting up error handling environment");
		set_error_handler('wfScan::error_handler', E_ALL);
		register_shutdown_function('wfScan::shutdown');
		if(! self::$debugMode){
			ob_start('wfScan::obHandler');
		}
		@error_reporting(E_ALL);
		@ini_set('display_errors','On');
		wordfence::status(4, 'info', "Setting up scanRunning and starting scan");
		$isFork = ($_GET['isFork'] == '1' ? true : false);
		$scan = wfConfig::get_ser('wfsd_engine', false, true);
		if($scan){
			//Set false so that we don't get stuck in a loop where we're repeating scan stages.
			wordfence::status(4, 'info', "Got a true deserialized value back from 'wfsd_engine' with type: " . gettype($scan));
			wfConfig::set('wfsd_engine', '', true);
		} else {
			if($isFork){ //We encountered an error so blank scan and exit
				wordfence::status(2, 'error', "Scan can't continue - stored data not found after a fork. Got type: " . gettype($scan));
				wfConfig::set('wfsd_engine', '', true);
				exit();
			} else {
				wordfence::statusPrep(); //Re-initializes all status counters
				$scan = new wfScanEngine();
			}
		}
		$scan->go();
		wfUtils::clearScanLock();
	}
	public static function obHandler($buf){
		if(strlen($buf) > 1000){
			$buf = substr($buf, 0, 255);
		}
		if(empty($buf) === false && preg_match('/[a-zA-Z0-9]+/', $buf)){
			wordfence::status(1, 'error', $buf);
		}
	}
	public static function error_handler($errno, $errstr, $errfile, $errline){
		wordfence::status(1, 'error', "$errstr ($errno) File: $errfile Line: $errline");
	}
	public static function shutdown(){
		wfUtils::clearScanLock();
	}
	private static function errorExit($msg){
		echo json_encode(array('errorMsg' => $msg)); 
		exit();	
	}
	public static function becomeAdmin(){
		global $wpdb;
		$ws = $wpdb->get_results("SELECT ID, user_login FROM $wpdb->users");
		$users = array();
		foreach($ws as $user){
			$userDat = get_userdata($user->ID);
			array_push($users, array(
				'id' => $user->ID,
				'user_login' => $user->user_login,
				'level' => $userDat->user_level
				));
		}
		usort($users, 'wfScan::usort');
		wp_set_current_user($users[0]['id'], $users[0]['user_login']);
	}
	public static function usort($b, $a){
		if($a['level'] == $b['level']){ return 0; }
		return ($a['level'] < $b['level']) ? -1 : 1;
	}
}
wfScan::wfScanMain();
?>
