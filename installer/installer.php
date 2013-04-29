<?php
/**
 * installer.php
 */

defined('IN_WITY') or die('Access denied');
define('DS', DIRECTORY_SEPARATOR);

require 'request.php';
require 'view.php';

/**
 * Installer installs Wity on the server (configuration files and MySQL tables)
 *
 * @package Installer
 * @author Julien Blatecky <julien.blatecky@creatiwity.net>
 * @version 0.3-17-01-2013
 */
class Installer {

	private static $THEMES_DIR;
	private static $APPS_DIR;
	private static $CONFIG_DIR;
	
	private static $EXCLUDED_THEMES = array('system', 'admin');
	private static $EXCLUDED_APPS = array('admin');
	private static $EXCLUDED_DIRS = array('.', '..');
	
	private static $view;
	
	/**
	 * Security system
	 * 
	 *  if (the lock file exists && the lock file is still valid) || lock file does not exist
	 *      create lock file (again)
	 *      execute control
	 *  else
	 *      return an error message (msg: delete lock file) 
	 *  
	 */
	public static function launch() {
		self::$THEMES_DIR = "themes";
		self::$APPS_DIR = "apps";
		self::$CONFIG_DIR = "system".DS."config";
		
		self::$view = new View();
		
		$data = Request::getAssoc(array('command', 'installer', 'step', 'group'), '', 'POST');
		
		switch ($data['command']) {
			default:
			case 'START':
				self::$view->render();
				return;
			
			case 'INIT_INSTALLER':
				self::$view->info('installer', $data['installer'], 'Installer initialized', 'The Installer has been successfully initialized.');
				break;
			
			case 'FINISH_INSTALLATION':
				// Store the data in config files
				if(self::installerValidation($data)) {
					// General configuration
					$config = WRequest::getAssoc(array('site_name', 'base', 'theme', 'language'), '', 'POST');
					WConfig::set('config.base', $config['base']);
					WConfig::set('config.site_name', $config['site_name']);
					WConfig::set('config.theme', $config['theme']);
					WConfig::set('config.lang', $config['language']);
					
					WConfig::save('config', CONFIG_DIR.'config.php');
					
					// Database
					$database = WRequest::getAssoc(array('server', 'port', 'user', 'pw', 'dbname', 'prefix'), '', 'POST');
					WConfig::set('database.server', $database['server']);
					WConfig::set('database.port', $database['port']);
					WConfig::set('database.user', $database['user']);
					WConfig::set('database.pw', $database['pw']);
					WConfig::set('database.dbname', $database['dbname']);
					WConfig::set('database.prefix', $database['prefix']);
					
					WConfig::save('database', CONFIG_DIR.'database.php');
					
					// Route
					$route = WRequest::getAssoc(array('default', 'admin'));
					WConfig::set('route.default', array($route['default'], array()));
					WConfig::set('route.admin', array($route['admin'], array()));
					
					WConfig::save('route', CONFIG_DIR.'route.php');
					
					// If success, Delete installer directory
					if (file_exists(CONFIG_DIR.'config.php') && file_exists(CONFIG_DIR.'database.php') && file_exists(CONFIG_DIR.'route.php')) {
						$dir = WITY_PATH.'installer';
						$it = new RecursiveDirectoryIterator($dir);
						$files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
						foreach($files as $file) {
							if ($file->getFilename() === '.' || $file->getFilename() === '..') {
								continue;
							}
							if ($file->isDir()) {
								rmdir($file->getRealPath());
							} else {
								unlink($file->getRealPath());
							}
						}
						rmdir($dir);
					} else {
						self::$view->error('installer', $data['installer'], 'Fatal Error', 'Data submitted cannot be validated. Please, restart the installation and fill in the form again.');
					}
				} else {
					self::$view->error('installer', $data['installer'], 'Fatal Error', 'Data submitted cannot be validated. Please, restart the installation and fill in the form again.');
				}
				break;
			
			// Groups
			case 'GROUP_VALIDATION':
				self::groupValidation($data);
				break;
			
			// Autocompletes
			case 'GET_THEMES':
				if($themes = self::getThemes()) {
					self::$view->push_content("GET_THEMES", $themes);
				} else {
					self::$view->error('installer', $data['installer'], 'Fatal Error', 'Themes directory cannot be found.');
				}
				break;
			
			case 'GET_FRONT_APPS':
				if($themes = self::getFrontApps()) {
					self::$view->push_content("GET_FRONT_APPS", $themes);
				} else {
					self::$view->error('installer', $data['installer'], 'Fatal Error', 'Applications directory cannot be found.');
				}
				break;
			
			case 'GET_ADMIN_APPS':
				if($themes = self::getAdminApps()) {
					self::$view->push_content("GET_ADMIN_APPS", $themes);
				} else {
					self::$view->error('installer', $data['installer'], 'Fatal Error', 'Applications directory cannot be found.');
				}
				break;
		}
		
		self::$view->respond();
	}
	
	private static function installerValidation($data) {
		$inputs = array('site_name', 'base', 'theme', 'language', 'server', 'port', 'user', 'pw', 'dbname', 'prefix', 'default', 'admin');
		foreach ($inputs as $input_name) {
			if (!groupValidation($input_name)) {
				return false;
			}
		}
		return true;
	}
	
	private static function groupValidation($data) {
		$respond = true;
		
		switch ($data['group']) {
			case 'site_name':
				$r = Request::getAssoc(array('site_name'), array('site_name'=>''), 'POST');
				if(self::isVerifiedString($r['site_name'], $data, $respond)) {
					self::$view->success('group', $data['group'], "Validated !", "Site name validated.");
					return true;
				} else if($respond) {
					self::$view->error('group', $data['group'], "Invalid site name", "The site name must be an alphanumeric string. (- and ' and spaces are allowed too)");
					return false;
				}
				break;
			
			case 'base_url':
				$r = Request::getAssoc(array('base'), array('base'=>''), 'POST');
				if(self::isURL($r['base'], $data, $respond)) {
					self::$view->success('group', $data['group'], "Validated !", "Base URL validated.");
					return true;
				} else if($respond) {
					self::$view->error('group', $data['group'], "Invalid base url", "The base url must be a valid URL representing the constant part of your site URL.");
					return false;
				}
				break;
			
			case 'theme':
				$r = Request::getAssoc(array('theme'), array('theme'=>''), 'POST');
				if(self::isTheme($r['theme'], $data, $respond)) {
					self::$view->success('group', $data['group'], "Validated !", "Theme validated.");
					return true;
				} else if($respond) {
					self::$view->error('group', $data['group'], "Invalid theme", "Theme parameter must be an existing front theme, in 'themes' directory.");
					return false;
				}
				break;
			
			case 'language':
				// TODO : auto-detect available languages and validate them
				self::$view->success('group', $data['group'], "Validated !", "Theme validated.");
				return true;
				break;
			
			case 'front_app':
				$r = Request::getAssoc(array('default'), array('default'=>''), 'POST');
				if(self::isFrontApp($r['default'], $data, $respond)) {
					self::$view->success('group', $data['group'], "Validated !", "Front application validated.");
					return true;
				} else if($respond) {
					self::$view->error('group', $data['group'], "Invalid front application", "Starting front application parameter must an existing front application, in 'apps' directory.");
					return false;
				}
				break;
			
			case 'admin_app':
				$r = Request::getAssoc(array('admin'), array('admin'=>''), 'POST');
				if(self::isAdminApp($r['admin'], $data, $respond)) {
					self::$view->success('group', $data['group'], "Validated !", "Admin application validated.");
					return true;
				} else if($respond) {
					self::$view->error('group', $data['group'], "Invalid admin application", "Starting admin application parameter must an existing admin application, in 'apps' directory.");
					return false;
				}
				break;
			
			case 'db_credentials':
				$r = Request::getAssoc(array('server', 'port', 'user', 'pw'), array('server'=>'', 'port'=>'', 'user'=>'', '', 'pw'=>''), 'POST');
				if(self::isSQLServer($r, $data, $respond)) {
					self::$view->success('group', $data['group'], "Validated !", "Database credentials validated.");
					return true;
				} else if($respond) {
					self::$view->error('group', $data['group'], "Invalid database credentials", "Unable to connect to the database with the credentials you've just provided.");
					return false;
				}
				break;
			
			case 'db_name':
				$r = Request::getAssoc(array('server', 'port', 'user', 'pw', 'dbname'), array('server'=>'', 'port'=>'', 'user'=>'', '', 'pw'=>'', 'dbname'=>''), 'POST');
				if(self::isDatabase($r, $data, $respond)) {
					self::$view->success('group', $data['group'], "Validated !", "Database name validated.");
					return true;
				} else if($respond) {
					self::$view->error('group', $data['group'], "Invalid database name", "Unable to find the database with the name you've just provided.");
					return false;
				}
				break;
			
			case 'tables_prefix':
				$r = Request::getAssoc(array('server', 'port', 'user', 'pw', 'dbname', 'prefix'), array('server'=>'', 'port'=>'', 'user'=>'', '', 'pw'=>'', 'dbname'=>'', 'prefix'=>''), 'POST');
				if(self::isPrefixNotExisting($r, $data, $respond)) {
					self::$view->success('group', $data['group'], "Validated !", "Tables prefix validated and not used.");
					return true;
				} else if($respond) {
					self::$view->warning('group', $data['group'], "Prefix already used", "Be careful, the prefix you provides is already used. Some existing tables will be overridden");
					return true;
				}
				break;
			
			case 'user_nickname':
				$r = Request::getAssoc(array('nickname'), array('nickname'=>''), 'POST');
				if(self::isVerifiedString($r['nickname'], $data, $respond)) {
					self::$view->success('group', $data['group'], "Validated !", "Nickname validated.");
					return true;
				} else if($respond) {
					self::$view->error('group', $data['group'], "Invalid nickname", "Your nickname must be an alphanumeric string. (- and ' and spaces are allowed too)");
					return false;
				}
				break;
			
			case 'user_password':
				$r = Request::getAssoc(array('password'), array('password'=>''), 'POST');
				self::$view->success('group', $data['group'], "Validated !", "Password validated.");
				return true;
				break;
			
			case 'user_email':
				$r = Request::getAssoc(array('email'), array('email'=>''), 'POST');
				if(self::isEmail($r['email'], $data, $respond)) {
					self::$view->success('group', $data['group'], "Validated !", "Email validated.");
					return true;
				} else if($respond) {
					self::$view->error('group', $data['group'], "Invalid email", "This email is not valid.");
					return false;
				}
				break;
			
			case 'user_firstname':
				$r = Request::getAssoc(array('firstname'), array('firstname'=>''), 'POST');
				if(self::isVerifiedString($r['firstname'], $data, $respond)) {
					self::$view->success('group', $data['group'], "Validated !", "Firstname validated.");
					return true;
				} else if($respond) {
					self::$view->error('group', $data['group'], "Invalid firstname", "Your firstname must be an alphanumeric string. (- and ' and spaces are allowed too)");
					return false;
				}
				break;
			
			case 'user_lastname':
				$r = Request::getAssoc(array('lastname'), array('lastname'=>''), 'POST');
				if(self::isVerifiedString($r['lastname'], $data, $respond)) {
					self::$view->success('group', $data['group'], "Validated !", "Lastname validated.");
					return true;
				} else if($respond) {
					self::$view->error('group', $data['group'], "Invalid lastname", "Your lastname must be an alphanumeric string. (- and ' and spaces are allowed too)");
					return false;
				}
				break;
			
			default:
				self::$view->error('step', $data['step'], 'Unknown group', "You're trying to validate an unknown group.");
				return false;
		}
	}
	
	/**
	 * Validators
	 */
	private static function isURL($url, $data,&$respond) {
		return !empty($url) && preg_match('/^(http|https|ftp)://([A-Z0-9][A-Z0-9_-]*(?:.[A-Z0-9][A-Z0-9_-]*)+):?(d+)?/?/i', $url);
	}
	
	private static function isVerifiedString($string, $data, &$respond) {
		return !empty($url) && preg_match("/^[A-Z]?'?[- a-zA-Z]( [a-zA-Z])*$/i", $string);
	}
	
	private static function isFrontApp($app, $data, &$respond) {
		return in_array(strtolower($app), self::getFrontApps());
	}
	
	private static function isAdminApp($app, $data, &$respond) {
		return in_array(strtolower($app), self::getAdminApps());
	}
	
	private static function isTheme($theme, $data, &$respond) {
		return in_array(strtolower($theme), self::getThemes());
	}
	
	private static function isSQLServer($credentials, $data, &$respond) {
		if (!class_exists('PDO')) {
			self::$view->error('installer', $data['installer'], 'System failure', 'PDO class cannot be found. This feature has been introduced since PHP5.1+');
			return $respond = false;
		}
		
		$dsn = 'mysql:dbname=;host='.WConfig::get('database.server');
		$dsn .= (isset($credentials['port']) && !empty($credentials['port']) && is_numeric($credentials['port'])) ? ';port='.$credentials['port']:'';
		
		try {
			new PDO($dsn, $credentials['user'], $$credentials['pw']);
		} catch (PDOException $e) {
			if(strstr($e->getMessage(), 'SQLSTATE[')) { 
				preg_match('/SQLSTATE\[(\w+)\] \[(\w+)\] (.*)/', $e->getMessage(), $matches);
				if ($matches[2] == "1049") {
					return true;
				} else if ($matches[2] == "1044") {
					self::$view->error('group', $data['group'], 'Unable to connect to the database', "Bad user/password.");
					return $respond = false;
				} else {
					return false;
				}
			}
		}
		
		return true;
	}
	
	private static function isDatabase($credentials, $data, &$respond) {
		if (!class_exists('PDO')) {
			self::$view->error('installer', $data['installer'], 'System failure', 'PDO class cannot be found. This feature has been introduced since PHP5.1+');
			return $respond = false;
		}
		
		$dsn = 'mysql:dbname=;host='.WConfig::get('database.server');
		$dsn .= (isset($credentials['port']) && !empty($credentials['port']) && is_numeric($credentials['port'])) ? ';port='.$credentials['port']:'';
		
		try {
			new PDO($dsn, $credentials['user'], $$credentials['pw']);
		} catch (PDOException $e) {
			if(strstr($e->getMessage(), 'SQLSTATE[')) { 
				preg_match('/SQLSTATE\[(\w+)\] \[(\w+)\] (.*)/', $e->getMessage(), $matches);
				if ($matches[2] == "1049") {
					self::$view->error('group', $data['group'], 'Unable to find the database', "The database you specified cannot be found.");
					return $respond = false;
				} else {
					return false;
				}
			}
		}
		
		return true;
	}
	
	private static function isPrefixNotExisting($credentials, $data, &$respond) {
		if (!preg_match("/^[a-zA-Z0-9]$/", $credentials['prefix'])) {
			self::$view->error('group', $data['group'], 'Malformed prefix', 'The prefix must be only alphanumeric.');
			return $respond = false;
		}
		
		if (!class_exists('PDO')) {
			self::$view->error('installer', $data['installer'], 'System failure', 'PDO class cannot be found. This feature has been introduced since PHP5.1+');
			return $respond = false;
		}
		
		$dsn = 'mysql:dbname=;host='.WConfig::get('database.server');
		$dsn .= (isset($credentials['port']) && !empty($credentials['port']) && is_numeric($credentials['port'])) ? ';port='.$credentials['port']:'';
		
		try {
			$db = new PDO($dsn, $credentials['user'], $$credentials['pw']);
		} catch (PDOException $e) {
			if(strstr($e->getMessage(), 'SQLSTATE[')) { 
				preg_match('/SQLSTATE\[(\w+)\] \[(\w+)\] (.*)/', $e->getMessage(), $matches);
				if ($matches[2] == "1049") {
					self::$view->error('group', $data['group'], 'Unable to find the database', "The database you specified cannot be found.");
					return $respond = false;
				} else {
					return false;
				}
			}
		}
		
		$prefix = (!empty($credentials['prefix'])) ? $credentials['prefix']."_":"";
		
		$prep = $db->prepare("SHOW TABLES LIKE :prefixedTable");
		$prep->bindParam(":prefixedTable", $prefix."user");
		$prep->execute();
		return $prep->fetch() ? false:true;
	}
	
	private static function isEmail($email, $data, &$respond) {
		return !empty($email) && preg_match('/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}$/i', $email);
	}
	
	/**
	 * Getters
	 */
	private static function getThemes() {
		if($result = scandir(self::$THEMES_DIR)) {
			foreach ($result as $key => $value) {
				if(in_array($value, self::$EXCLUDED_THEMES) || !is_dir(self::$THEMES_DIR.DS.$value) || in_array($value, self::$EXCLUDED_DIRS)) {
					unset($result[$key]);
				}
			}
			$result[] = "_blank";
		}
		
		return $result;
	}
	
	private static function getFrontApps() {
		if($result = scandir(self::$APPS_DIR)) {
			foreach ($result as $key => $value) {
				if(in_array($value, self::$EXCLUDED_APPS) || !is_dir(self::$APPS_DIR.DS.$value.DS."front") || in_array($value, self::$EXCLUDED_DIRS)) {
					unset($result[$key]);
				}
			}
		}
		
		return $result;
	}
	
	private static function getAdminApps() {
		if($result = scandir(self::$APPS_DIR)) {
			foreach ($result as $key => $value) {
				if(in_array($value, self::$EXCLUDED_APPS) || !is_dir(self::$APPS_DIR.DS.$value.DS."admin") || in_array($value, self::$EXCLUDED_DIRS)) {
					unset($result[$key]);
				}
			}
		}
		
		return $result;
	}

}

?>
