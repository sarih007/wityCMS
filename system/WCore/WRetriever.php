<?php 
/**
 * WRetriever.php
 */

defined('IN_WITY') or die('Access denied');

/**
 * WRetriever is the component to get the model or the view of an action from any WityCMS's application.
 * 
 * @package System\WCore
 * @author Johan Dufau <johan.dufau@creatiwity.net>
 * @version 0.4.0-12-10-2013
 */
class WRetriever {
	/**
	 * @var Stores the application list
	 * @static
	 */
	private static $apps_list = array();
	
	/**
	 * @var Stores all the app already instantiated
	 * @static
	 */
	private static $controllers = array();
	
	/**
	 * @var Stores the models already calculated
	 * @static
	 */
	private static $models = array();
	
	public static function init() {
		// Init template handler
		WSystem::getTemplate();
		WTemplateCompiler::registerCompiler('retrieve_view', array('WRetriever', 'compile_retrieve_view'));
		WTemplateCompiler::registerCompiler('retrieve_model', array('WRetriever', 'compile_retrieve_model'));
	}
	
	/**
	 * Gets the model of an application/action
	 * 
	 * @param string $app_name
	 * @param array  $params
	 * @return array
	 */
	public static function getModel($app_name, array $params = array(), $has_parent = true) {
		$signature = md5($app_name.serialize($params).$has_parent);
		
		// Check if this model was not already calculated
		if (isset(self::$models[$signature])) {
			return self::$models[$signature];
		}
		
		// Get app controller
		$controller = self::getController($app_name, $has_parent);
		
		// Treat the GET querystring
		if (isset($params['querystring'])) {
			$querystring = explode('&', str_replace('&amp;', '&', $params['querystring']));
			foreach ($querystring as $assignment) {
				$data = addslashes($assignment);
				
				$equal_pos = strpos($data, '=');
				if ($equal_pos !== false) {
					// Extract key and value
					$key = substr($data, 0, $equal_pos);
					$value = substr($data, $equal_pos+1);
					
					// Update the Global variables
					WRequest::set($key, $value, "GET");
				}
			}
			
			unset($params['querystring']);
		}
		
		// Init model structure
		$model = array(
			'app-name'         => $app_name,
			'triggered-action' => '',
			'result'           => null
		);
		
		// Get model
		if ($controller instanceof WController) {
			// Lock access to the Request variables for non targeted apps
			$form_signature = WRequest::get('form_signature');
			if (!empty($form_signature) && $form_signature != $signature) {
				WRequest::lock();
			}
			
			// Trigger the action and get the result model
			$model['result'] = $controller->launch($params);
			
			$model['triggered-action'] = $controller->getTriggeredAction();
			
			// Unlock the Request variables access
			WRequest::unlock();
		} else {
			$model['result'] = $controller;
		}
		
		// Cache the value
		self::$models[$signature] = $model;
		
		return $model;
	}
	
	/**
	 * Gets the View of a given application/action
	 * The model will automatically be generated and the View will be prepared
	 * (the corresponding method to the action will be executed in WView)
	 * 
	 * @param string $app_name  Application's name
	 * @param array  $params    Some special parameters to send to the controller (optional)
	 * @param string $view_size Size mode of the view expected (optional)
	 * @return WView
	 */
	public static function getView($app_name, array $params = array(), $view_size = '', $has_parent = true) {
		// Get app controller
		$controller = self::getController($app_name, $has_parent);
		
		if ($controller instanceof WController) {
			// Get the model
			$model = self::getModel($app_name, $params);
			
			if (array_keys($model['result']) == array('level', 'code', 'message', 'handlers')) {
				// If model is a Note
				$view = WNote::getView(array($model['result']));
			} else {
				$view = $controller->getView();
				
				// Attempt to declare the template file according to the action
				// The final template file can be changed directly in the View.php
				$actionTemplateFile = $view->getContext('directory').'templates'.DS.$model['triggered-action'].'.html';
				if (file_exists($actionTemplateFile)) {
					$view->setTemplate($actionTemplateFile);
				}
				
				// Prepare the view
				if (method_exists($view, $model['triggered-action'])) {
					$view->$model['triggered-action']($model['result']);
				}
				
				// Update the context
				$view->updateContext('signature', md5($app_name.serialize($params).$has_parent));
			}
			
			return $view;
		} else {
			// Return a WView with error
			return WNote::getView(array($controller));
		}
	}
	
	/**
	 * If found, execute the application in the apps/$app_name directory
	 * 
	 * @param string $app_name name of the application that will be launched
	 * @return WController App Controller
	 */
	public static function getController($app_name, $has_parent) {
		// Check if app not already instantiated
		if (isset(self::$controllers[$app_name])) {
			return self::$controllers[$app_name];
		}
		
		// App asked exists?
		if (self::isApp($app_name)) {
			// App controller file
			$app_dir = APPS_DIR.$app_name.DS.'front'.DS;
			include_once $app_dir.'main.php';
			$app_class = str_replace('-', '_', ucfirst($app_name)).'Controller';
			
			// App's controller must inherit WController
			if (class_exists($app_class) && get_parent_class($app_class) == 'WController') {
				$context = array(
					'name'       => $app_name,
					'directory'  => $app_dir,
					'controller' => $app_class,
					'admin'      => false,
					'parent'     => $has_parent
				);
				
				// Construct App Controller
				$controller = new $app_class();
				
				// Instantiate Model if exists
				if (file_exists($app_dir.'model.php')) {
					include_once $app_dir.'model.php';
					$model_class = str_replace('Controller', 'Model', $app_class);
					if (class_exists($model_class)) {
						$controller->setModel(new $model_class());
					}
				}
				
				// Instantiate View if exists
				if (file_exists($app_dir.'view.php')) {
					include_once $app_dir.'view.php';
					$view_class = str_replace('Controller', 'View', $app_class);
					if (class_exists($view_class)) {
						$controller->setView(new $view_class());
					}
				}
				
				// Init
				$controller->init($context);
				
				// Store the controller
				self::$controllers[$app_name] = $controller;
				
				return $controller;
			} else {
				return WNote::error('app_structure', "The application \"".$app_name."\" has to have a main class inheriting from WController abstract class.");
			}
		} else {
			return WNote::error(404, "The page requested was not found.");
		}
	}
	
	/**
	 * Returns a list of applications that contains a main.php file in their front directory
	 * 
	 * @return array(string)
	 */
	public static function getAppsList() {
		if (empty(self::$apps_list)) {
			$apps = glob(APPS_DIR.'*', GLOB_ONLYDIR);
			foreach ($apps as $appDir) {
				if ($appDir != '.' && $appDir != '..' && file_exists($appDir.DS.'front'.DS.'main.php')) {
					self::$apps_list[] = basename($appDir);
				}
			}
		}
		return self::$apps_list;
	}
	
	/**
	 * Returns application existence
	 * 
	 * @param string $app
	 * @return bool true if $app exists, false otherwise
	 */
	public static function isApp($app) {
		return !empty($app) && in_array($app, self::getAppsList());
	}
	
	/********************************************
	 * WTemplateCompiler's custom handlers part *
	 ********************************************/
	
	/**
	 * Handles the {retrieve_model} node in WTemplate
	 * {retrieve_model} will return an array of the targeted Model
	 * 
	 * The full syntax is as follow:
	 * {retrieve_model app_name/action/param1/param2?var1=value1&var2=value2}
	 * 
	 * Note that you can specify a querystring that will be accessible through WRequest.
	 * 
	 * It should be used within a {set} node as follow:
	 * {set $model = {retrieve_model app_name/action/param1/param2?var1=value1&var2=value2}}
	 * 
	 * @param string $args Model location + querystring: "app_name/action/param1/param2?var1=value1&var2=value2"
	 * @return string PHP string to trigger WRetriever that will return an array of the desired model
	 */
	public static function compile_retrieve_model($args) {
		if (!empty($args)) {
			$args = addslashes($args);
			
			// Replace all the template variables in the string
			$args = WTemplateParser::replaceNodes($args, create_function('$s', "return '\".'.WTemplateCompiler::parseVar(\$s).'.\"';"));
			
			$args = explode('?', $args);
			
			// Explode the route in several parts
			$args[0] = trim($args[0], '/');
			$route = explode('/', $args[0]);
			
			if (count($route) >= 1) {
				// Extract the relevant data
				$app_name = addslashes(array_shift($route));
				$params = '';
				
				// Get the params from the route of the view
				foreach ($route as $part) {
					if (!empty($part)) {
						$params .= '"'.$part.'", ';
					}
				}
				
				// Format the querystring PHP code if a querystring is given
				if (isset($args[1])) {
					$querystring = ', "'.$args[1].'"';
					$params .= '"querystring" => "'.$args[1].'"';
				} else {
					$params = substr($params, 0, -2);
				}
				
				return 'WRetriever::getModel("'.$app_name.'", array('.$params.'))';
			}
		}
		return '';
	}
	
	/**
	 * Handles the {retrieve_view} node in WTemplate
	 * {retrieve_view} will return a compiled view of an internal or external application's action
	 * 
	 * The full syntax is as follow :
	 * {retrieve_view app_name/action/param1/param2?var1=value1&var2=value2}
	 * 
	 * Note that you can specify a querystring that will be accessible through WRequest.
	 * 
	 * @param string $args View location + querystring: "app_name/action/param1/param2?var1=value1&var2=value2"
	 * @return string PHP string to fire the View compilation (will be replaced by the View HTML response in the end)
	 */
	public static function compile_retrieve_view($args) {
		// Use {retrieve_model} compiler
		$model_syntax = self::compile_retrieve_model($args);
		
		// Replace 'WRetriever::getModel' with 'WRetriever::getView'
		if (!empty($model_syntax)) {
			return '<?php echo '.str_replace('getModel', 'getView', $model_syntax).'->render(); ?>'."\n";
		}
		
		return '';
	}
}

?>
