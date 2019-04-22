<?php

namespace Spry;

use stdClass;

/*!
 *
 * Spry Framework
 * https://github.com/ggedde/spry
 *
 * Copyright 2016, GGedde
 * Released under the MIT license
 *
 */

class Spry {

	private static $version = "0.9.32";
	private static $routes = [];
	private static $params = [];
	private static $db = null;
	private static $log = null;
	private static $path = null;
	private static $route = null;
	private static $validator;
	private static $auth;
	private static $config;
	private static $config_file = '';
	private static $timestart;
	private static $cli = false;
	private static $test = false;
	private static $request_id = '';

	/**
	 * Initiates the API Call.
	 *
	 * @param string $config_file
 	 *
 	 * @access 'public'
 	 * @return void
	 */

	public static function run($args=[])
	{
		self::$timestart = microtime(true);
		self::$request_id = md5(uniqid('', true));

		if($args && is_string($args))
		{
			if(file_exists($args))
			{
				$args = ['config' => $args];
			}
			else
			{
				// Check if it is not Json and if so then base64_decode it.
				if(!preg_match('/[\"\[\{]+/', $args))
				{
					$args = base64_decode($args);
				}

				$args = json_decode($args, true );
			}

			if(empty($args) || !is_array($args))
			{
				$response_codes = self::get_core_response_codes();

				// Logger may not be setup so trigger php notice
				trigger_error('Spry ERROR: '.$response_codes[5003]['en']);

				self::stop(5003, null, $response_codes[5003]['en']);
			}
		}

		// Set Defaults
		$args = array_merge([
            'config' => '',
            'path' => '',
            'controller' => '',
            'params' => null,
        ], $args);

		if(empty($args['config']) || (is_string($args['config']) && !file_exists($args['config'])))
		{
			$response_codes = self::get_core_response_codes();

			// Logger may not be setup so trigger php notice
			trigger_error('Spry ERROR: '.$response_codes[5001]['en']);

			self::stop(5001, null, $response_codes[5001]['en']);
		}

		self::$cli = self::is_cli();

		// Setup Config Data Autoloader and Configure Filters
		self::configure($args['config']);

		if(empty(self::$config->salt))
		{
			$response_codes = self::get_core_response_codes();

			// Logger may not be setup so trigger php notice
			trigger_error('Spry: '.$response_codes[5002]['en']);

			self::stop(5002, null, $response_codes[5002]['en']);
		}

		// Configure Hook
		self::run_hook('configure');

		// Return Data Immediately if is a PreFlight OPTIONS Request
		if(!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS')
		{
			self::send_output();
		}

		self::$path = (!empty($args['path']) ? $args['path'] : self::get_path());

		// Set Path Hook
		self::run_hook('set_path');

		self::set_routes();

		self::set_params(self::fetch_params($args['params']));

		// IF Test Data then set currnt transaction as Test
		if(!empty(self::$params['test_data'])) self::$test = true;

		if($args['controller'])
		{
			$controller = self::get_controller($args['controller']);
		}
		else
		{
			self::$route = self::get_route(self::$path);
			$controller = self::get_controller(self::$route['controller']);
		}

		if(self::$cli)
		{
			$response = self::get_response($controller, self::$params);
		}
		else
		{
			$response = self::get_response($controller, self::validate_params());
		}

		self::send_response($response);
	}

	public static function is_cli()
	{
		return (php_sapi_name() === 'cli' || (!empty($_SERVER['argc']) && is_numeric($_SERVER['argc']) && $_SERVER['argc'] > 0));
	}

	public static function is_test()
	{
		return self::$test;
	}

	public static function get_version()
	{
		return self::$version;
	}

	public static function get_config_file()
	{
		return self::$config_file;
	}
	
	public static function get_request_id()
	{
		return self::$request_id;
	}

	public static function get_method()
	{
		$method = strtoupper(trim(!empty($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'POST'));
		if(in_array($method, ['POST', 'GET', 'PUT', 'DELETE']))
		{
			return $method;
		}
		return null;
	}

	public static function configure($config_data='')
	{
		if(empty(self::$request_id))
		{
			self::$request_id = md5(uniqid('', true));
		}

		if(!empty(self::$config))
		{
			return false;
		}

		if(is_object($config_data))
		{
			$config = $config_data;
		}
		else
		{
			$config = new stdClass();
			$config->hooks = new stdClass();
			$config->filters = new stdClass();
			$config->db = new stdClass();

			require($config_data);
			self::$config_file = $config_data;
		}

		$config->response_codes = array_replace(self::get_core_response_codes(), (!empty($config->response_codes) ? $config->response_codes : []));
		self::$config = $config;

		// Set AutoLoaders for Components, Providers and Plugins
		spl_autoload_register(array(__CLASS__, 'autoloader'));

		// Configure Filter
		self::$config = self::run_filter('configure', self::$config);
	}

	private static function get_core_response_codes()
	{
		return [
			2000 => ['en' => 'Success!'],
			4000 => ['en' => 'No Results'],
			5000 => ['en' => 'Error: Unknown Error'],
			5001 => ['en' => 'Error: Missing Config File'],
			5002 => ['en' => 'Error: Missing Salt in Config File'],
			5003 => ['en' => 'Error: Unknown configuration error on run'],

			5011 => ['en' => 'Error: Route Not Found.'],
			5012 => ['en' => 'Error: Class Not Found.'],
			5013 => ['en' => 'Error: Class Method Not Found.'],
			5014 => ['en' => 'Error: Returned Data is not in JSON format.'],
			5015 => ['en' => 'Error: Class Method is not Callable. Make sure it is Public.'],
			5016 => ['en' => 'Error: Controller Not Found.'],
			5017 => ['en' => 'Error: Method not allowed by Route.'],

			5020 => ['en' => 'Error: Field did not Validate.'],

			/* DB */
			2030 => ['en' => 'Database Migrate Ran Successfully'],
			5030 => ['en' => 'Error: Database Migrate had an Error'],
			5031 => ['en' => 'Error: Database Connect Error.'],
			5032 => ['en' => 'Error: Missing Database Credentials from config.'],
			5033 => ['en' => 'Error: Database Provider not found.'],

			/* Log */
			5040 => ['en' => 'Error: Log Provider not found.'],

			/* Tests */
			2050 => ['en' => 'Test Passed Successfully'],
			5050 => ['en' => 'Error: Test Failed'],
			5051 => ['en' => 'Error: Retrieving Tests'],
			5052 => ['en' => 'Error: No Tests Configured'],
			5053 => ['en' => 'Error: No Test with that name Configured'],

			/* Background Process */
			5060 => ['en' => 'Error: Background Process did not return Process ID'],
			5061 => ['en' => 'Error: Background Process could not find autoload'],
			5062 => ['en' => 'Error: Unknown response from Background Process'],

		];
	}



	/**
	 * Adds all the Routes to allow.
 	 *
 	 * @access 'private'
 	 * @return void
	 */

	private static function set_routes()
	{
		foreach(self::$config->routes as $route_path => $route)
		{
			if(!empty($route) && (!isset($route['active']) || !empty($route['active'])))
			{
				self::add_route($route_path, $route);
			}
		}

		// Route Hooks
		self::run_hook('set_routes');
	}



	/**
	 * Gets the response Type.
 	 *
 	 * @access 'private'
 	 * @return string
	 */

	private static function response_type($code='')
	{
		if(!empty($code) && is_numeric($code))
		{
			switch (substr($code, 0, 1))
			{
				case 2:
				case 4:
					return 'success';

				break;


				case 5:
					return 'error';

				break;

			}
		}

		return 'unknown';
	}



	/**
	 * Sets all the response Codes available for the App.
 	 *
 	 * @access 'private'
 	 * @return array
	 */

	private static function response_codes($code=0)
	{
		$lang = 'en';
		$type = 4;

		if(self::params('lang'))
		{
			$lang = self::params('lang');
		}

		if(strlen($code))
		{
			$type = substr($code, 0, 1);
		}

		if(isset(self::$config->response_codes[$code][$lang]))
		{
			$response = self::$config->response_codes[$code][$lang];
		}
		else if(isset(self::$config->response_codes[$code]['en']))
		{
			$response = self::$config->response_codes[$code]['en'];
		}
		else if(isset(self::$config->response_codes[$code]) && is_string(self::$config->response_codes[$code]))
		{
			$response = self::$config->response_codes[$code];
		}
		else if($type === 4 && isset(self::$config->response_codes[4000][$lang]))
		{
			$code = 4000;
			$response = self::$config->response_codes[$code][$lang];
		}
		else if($type === 4 && isset(self::$config->response_codes[4000]['en']))
		{
			$code = 4000;
			$response = self::$config->response_codes[$code]['en'];
		}
		else if($type === 4 && isset(self::$config->response_codes[4000]) && is_string(self::$config->response_codes[4000]))
		{
			$code = 4000;
			$response = self::$config->response_codes[$code];
		}

		if(!empty($response))
		{
			return ['status' => self::response_type($code), 'code' => (int) $code, 'messages' => [$response]];
		}

		return ['status' => 'unknown', 'code' => $code, 'messages' => ['Unkown Response Code']];
	}



	/**
	 * Adds a route to the allowed list.
 	 *
 	 * @param string $path
 	 * @param string $controller
 	 *
 	 * @access 'private'
 	 * @return void
	 */

	private static function add_route($path, $route)
	{
		$path = self::clean_path($path);

		if(!is_array($route))
		{
			$route = [
				'label' => ucwords(preg_replace('/\W|_/', ' ', $path)),
				'controller' => $route
			];
		}

		$route = array_merge([
			'controller' => '',
			'active' => true,
			'public' => true,
			'label' => '',
			'params' => [],
		], $route);

		self::$routes[$path] = $route;
	}



	/**
	 * Adds a route to the allowed list.
 	 *
 	 * @param string $path
 	 * @param string $controller
 	 *
 	 * @access 'private'
 	 * @return void
	 */

	public static function validate_params($path=null, $params=null, $args=null)
	{
		if(!empty($path))
		{
			$route = self::get_route($path);
		}

		if(empty($route))
		{
			$route = (self::$route ? self::$route : self::get_route());
		}

		if(is_null($params))
		{
			$params = self::params();
		}

		if(empty($route['params']))
		{
			$new_params = $params;
		}
		else
		{
			$new_params = [];

			if(!empty($params['test_data']))
			{
				$new_params['test_data'] = $params['test_data'];
			}

			$message_fields = [
				'required',
				'minlength',
				'maxlength',
				'length',
				'betweenlength',
				'min',
				'max',
				'between',
				'matches',
				'notmatches',
				'startswith',
				'notstartswith',
				'endswith',
				'notendswith',
				'array',
				'int',
				'integer',
				'number',
				'float',
				'digits',
				'ccnum',
				'ip',
				'email',
				'date',
				'mindate',
				'maxdate',
				'url',
				'oneof',
				'callback',
			];

			foreach($route['params'] as $param_key => $param_settings)
			{
				if(is_int($param_key) && $param_settings && is_string($param_settings))
				{
					$param_key = $param_settings;
					$param_settings = [];
				}

				if(!isset($param_settings['trim']) && !empty($route['params_trim']))
				{
					$param_settings['trim'] = true;
				}

				$required = (empty($param_settings['required']) ? false : true);

				if(!empty($param_settings['required']) && is_array($param_settings['required']))
				{
					reset($param_settings['required']);
					while($required_field = each($param_settings['required']))
					{
						if(!isset($params[$required_field['key']]) || $params[$required_field['key']] !== $required_field['value'])
						{
							$required = false;
						}
					}
				}

				// Skip if not Required or Param is not present
				if(empty($required) && !isset($params[$param_key]))
				{
					continue;
				}

				// Set Default
				if(!isset($params[$param_key]) && isset($param_settings['default']))
				{
					$params[$param_key] = $param_settings['default'];
				}

				$messages = [];

				foreach ($message_fields as $field)
				{
					$messages[$field] = (!empty($param_settings['messages'][$field]) ? $param_settings['messages'][$field] : null);
				}

				// Construct Validator
				$validator = self::validator($params);

				if($required)
				{
					$validator->required($messages['required']);
				}

				if(isset($param_settings['minlength']))
				{
					$validator->minLength($param_settings['minlength'], $messages['minlength']);
				}

				if(isset($param_settings['maxlength']))
				{
					$validator->maxLength($param_settings['maxlength'], $messages['maxlength']);
				}

				if(isset($param_settings['length']))
				{
					$validator->length($param_settings['length'], $messages['length']);
				}

				if(isset($param_settings['min']))
				{
					$validator->min($param_settings['min'], true, $messages['min']);
				}

				if(isset($param_settings['max']))
				{
					$validator->max($param_settings['max'], true, $messages['max']);
				}

				if(isset($param_settings['between']))
				{
					$validator->between(
						$param_settings['between'][0],
						$param_settings['between'][1],
						true,
						$messages['between']
					);
				}

				if(isset($param_settings['betweenlength']))
				{
					$validator->betweenlength(
						$param_settings['betweenlength'][0],
						$param_settings['betweenlength'][1],
						$messages['betweenlength']
					);
				}

				if(isset($param_settings['matches']))
				{
					$validator->matches(
						$param_settings['matches'],
						ucwords($param_settings['matches']),
						$messages['matches']
					);
				}

				if(isset($param_settings['notmatches']))
				{
					$validator->notmatches(
						$param_settings['notmatches'],
						ucwords($param_settings['notmatches']),
						$messages['notmatches']
					);
				}

				if(isset($param_settings['startswith']))
				{
					$validator->startsWith($param_settings['startswith'], $messages['startswith']);
				}

				if(isset($param_settings['notstartswith']))
				{
					$validator->notstartsWith($param_settings['notstartswith'], $messages['notstartswith']);
				}

				if(isset($param_settings['endswith']))
				{
					$validator->endsWith($param_settings['endswith'], $messages['endswith']);
				}

				if(isset($param_settings['notendswith']))
				{
					$validator->notendsWith($param_settings['notendswith'], $messages['notendswith']);
				}

				if(isset($param_settings['array']))
				{
					$validator->isarray($messages['array']);
				}

				if(isset($param_settings['integer']))
				{
					$validator->integer($messages['integer']);
				}

				// Alias of Integer
				if(isset($param_settings['int']))
				{
					$validator->integer($messages['int']);
				}

				if(isset($param_settings['float']))
				{
					$validator->float($messages['float']);
				}

				// Alias of Float
				if(isset($param_settings['number']))
				{
					$validator->float($messages['number']);
				}

				// Alias of Float
				if(isset($param_settings['num']))
				{
					$validator->float($messages['num']);
				}

				if(isset($param_settings['digits']))
				{
					$validator->digits($messages['digits']);
				}

				if(isset($param_settings['ccnum']))
				{
					$validator->ccnum($messages['ccnum']);
				}

				if(isset($param_settings['email']))
				{
					$validator->email($messages['email']);
				}

				if(isset($param_settings['date']))
				{
					$validator->date($messages['date']);
				}

				if(isset($param_settings['mindate']))
				{
					$validator->minDate($param_settings['mindate'], null, $messages['mindate']);
				}

				if(isset($param_settings['maxdate']))
				{
					$validator->maxDate($param_settings['maxdate'], null, $messages['maxdate']);
				}

				if(isset($param_settings['url']))
				{
					$validator->url($messages['url']);
				}

				if(isset($param_settings['ip']))
				{
					$validator->ip($messages['ip']);
				}

				if(isset($param_settings['oneof']))
				{
					$validator->oneOf($param_settings['oneof'], $messages['oneof']);
				}

				if(isset($param_settings['callback']))
				{
					$validator->callback($param_settings['callback'], $messages['callback']);
				}

				if(isset($param_settings['filter']))
				{
					$validator->filter($param_settings['filter']);
				}

				if(!empty($param_settings['validateonly']))
				{
					$validator->validate($param_key);
				}
				else
				{
					$new_params[$param_key] = $validator->validate($param_key);

					if(is_array($new_params[$param_key]) || is_object($new_params[$param_key]))
					{
						if(!empty($param_settings['trim']))
						{
							$new_params[$param_key] = array_values(array_filter(array_map('trim', $new_params[$param_key])));
						}

						if(!empty($param_settings['unique']))
						{
							$new_params[$param_key] = array_values(array_unique($new_params[$param_key]));
						}
					}
					else
					{
						if(!empty($param_settings['trim']))
						{
							$new_params[$param_key] = trim($new_params[$param_key]);
						}
					}
				}
			}
		}

		$new_params = self::run_filter('validate_params', $new_params);

		return $new_params;
	}


	/**
	 * Returns the Route including path and attached controller.
 	 *
 	 * @param string $path
 	 *
 	 * @access 'public'
 	 * @return array
	 */

	public static function get_route($path=null)
 	{
 		if(!$path)
 		{
 			$path = self::$path;
 		}

 		$path = self::clean_path($path);

 		if(!empty($path))
 		{
 			if(empty(self::$routes[$path]['controller']))
 			{
				foreach(self::$routes as $route_url => $route) 
				{
					if(is_string($route))
					{
						$route_url = $route;
					}
	
					$route_reg = '/'.str_replace('/', '\\/', preg_replace('/\{[^\}]+\}/', '(.*)', $route_url)).'/';
	
					if(preg_match($route_reg, $path))
					{
						$path = $route_url;
						break;
					}
				}
			}

			if(empty(self::$routes[$path]['controller']))
			{
				foreach(self::$routes as $route_url => $route) 
				{
					if(is_string($route))
					{
						$route_url = $route;
					}

					if(strpos($route_url, '{') !== false && strpos($route_url, '}'))
					{
						$stripped_path = preg_replace('/\{[^\}]+\}\/?/', '', $route_url);
	
						if($stripped_path === $path)
						{
							$path = $route_url;
							break;
						}
					}
				}
			}
			
			if(empty(self::$routes[$path]['controller']))
			{
				self::stop(5011);
			}

			$route = self::$routes[$path];
 		}

 		if(!empty($route))
 		{
			$route['methods'] = array_map('trim', array_map('strtoupper', (!empty($route['methods']) ? (is_string($route['methods']) ? [$route['methods']] : $route['methods']) : ['POST'])));

			// If Methods are configured for route then check if method is allowed
			if(!empty($route['methods']) && is_array($route['methods']) && !in_array(self::get_method(), $route['methods']))
			{
				self::stop(5017, $_POST); // Methoed not allowed
			}

			$route = self::run_filter('get_route', $route);
 			return $route;
 		}

 		self::stop(5011); // Request Not Found
 	}


	/**
	 * Returns the public Routes available.
 	 *
 	 * @access 'public'
 	 * @return array
	 */

	public static function get_routes()
	{
		$public_routes = [];

		if(!empty(self::$routes))
		{
			foreach (self::$routes as $route_path => $route)
			{
				if(!isset($route['public']) || !empty($route['public']))
				{
					$public_routes[$route_path] = $route;
				}
			}
		}

		return $public_routes;
	}



	/**
	 * Sets the Autoloader for the Extra Classed needed for the API.
 	 *
 	 * @access 'public'
 	 * @return void
	 */

	public static function autoloader($class)
	{
		$autoloader_directories = [];

		if(!empty(self::$config->components_dir))
		{
			$autoloader_directories[] = self::$config->components_dir;
		}

		if(!empty($autoloader_directories))
		{
			foreach($autoloader_directories as $dir)
			{
				foreach(glob(rtrim($dir, '/') . '/*')  as $file)
				{
					if(strtolower(basename(str_replace('\\', '/', $class))).'.php' === strtolower(basename($file)))
					{
						require_once $file;
						return;
					}
				}
			}
		}
	}


	/**
	 * Returns DB Provider.
 	 *
 	 * @access 'public'
 	 * @return object
	 */

	public static function db()
	{
		if(!self::$db)
		{
			if(empty(self::$config->db['username']) || empty(self::$config->db['database_name']))
			{
				self::stop(5032);
			}

			if(empty(self::$config->db['provider']) || !class_exists(self::$config->db['provider']))
			{
				self::stop(5033);
			}

			$class = self::$config->db['provider'];

			self::$db = new $class(self::$config->db);

			// Database Hooks
			self::run_hook('database');
		}

		return self::$db;
	}



	/**
	 * Returns Logger Provider.
 	 *
 	 * @access 'public'
 	 * @return object
	 */

	public static function log($message='')
	{
		if(!self::$log)
		{
			if(empty(self::$config->logger))
			{
				self::stop(5040);
			}

			if(!class_exists(self::$config->logger))
			{
				self::stop(5040);
			}

			$class = self::$config->logger;

			self::$log = new $class();
		}

		if($message)
		{
			if(method_exists(self::$log,'log'))
			{
				return self::$log->log($message);
			}
			else
			{
				trigger_error('Spry: Log Provider missing method "log".', E_USER_WARNING);
			}
		}

		return self::$log;
	}


	/**
	 * Returns Validator Extension.
 	 *
 	 * @access 'public'
 	 * @return object
	 */

	public static function validator($params=null)
	{
		if(is_null($params))
		{
			$params = self::$params;
		}

		if(empty(self::$validator))
		{
			self::$validator = new SpryProvider\SpryValidator($params);
		}
		else
		{
			self::$validator->setData($params);
		}

		return self::$validator;
	}



	/**
	 * Kills the Request and returns immediate error.
 	 *
 	 * @param int $response_code
 	 * @param mixed $data
 	 *
 	 * @access 'public'
 	 * @return void
	 */

	public static function stop($response_code=0, $data=null, $messages=[])
	{
		if(!empty($messages) && (is_string($messages) || is_numeric($messages)))
		{
			$messages = [$messages];
		}

		$params = [
			'code' => $response_code,
			'data' => $data,
			'messages' => $messages
		];

		self::run_hook('stop', $params);

		$response = self::build_response($response_code, $data, $messages);

		self::send_response($response);
	}



	/**
	 * Sets the Auth object.
 	 *
 	 * @access 'public'
 	 * @return object
	 */

	public static function set_auth($object)
	{
		self::$auth = $object;
	}



	/**
	 * Returns the Auth object.
 	 *
 	 * @access 'public'
 	 * @return object
	 */

	public static function auth()
	{
		return self::$auth;
	}



	/**
	 * Returns the Config Parameters from the Singleton Class.
 	 *
 	 * @access 'public'
 	 * @return object
	 */

	public static function config()
	{
		return self::$config;
	}



	/**
	 * Gets the Data sent in the API Call and converts it to Parameters.
	 * Then returns the converted Parameters as array.
	 * Throughs stop() on failure.
 	 *
 	 * @access 'private'
 	 * @return array
	 */

	private static function fetch_params($params=null)
	{
		if(!is_null($params))
		{
			$data = $params;
		}
		else
		{
			$data = trim(file_get_contents('php://input'));

			if(empty($data) && self::$cli)
			{
				$data = trim(file_get_contents('php://stdin'));
			}

			if(empty($data) && self::get_method() === 'GET' && !empty($_GET))
			{
				$data = $_GET;
			}

			if(empty($data) && self::get_method() === 'POST' && !empty($_POST))
			{
				$data = $_POST;
			}

			foreach(self::$routes as $route_url => $route) 
			{	
				if(is_string($route))
				{
					$route_url = $route;
				}

				$route_reg = '/'.str_replace('/', '\\/', preg_replace('/\{[^\}]+\}/', '(.*)', $route_url)).'/';

				preg_match_all('/\{([^\}]+)\}/', $route_url, $match_params);
				preg_match_all($route_reg, self::$path, $match_values);

				if(preg_match($route_reg, self::$path) && !empty($match_params[1]) && !empty($match_values[1]))
				{
					foreach ($match_params[1] as $match_param_key => $match_param)
					{
						$data[$match_param] = $match_values[1][$match_param_key];
					}
					
				}
			}
		}

		if($data && is_string($data))
		{
			if(in_array(substr($data, 0, 1), ['[','{']))
			{
				$data = json_decode($data, true);
			}
			else
			{
				# TODO
				echo '<pre>';print_r($data);echo '</pre>';
				exit;
			}
		}

		if(!empty($data))
		{
			$data = self::run_filter('params', $data);
		}

		if(!empty($data) && !is_array($data))
		{
			self::stop(5014); // Returned Data is not in JSON format
		}

		return $data;
	}




	/**
	 * Gets the Data sent in the API Call and converts it to Parameters.
	 * Then returns the converted Parameters as array.
	 * Throughs stop() on failure.
 	 *
 	 * @access 'public'
 	 * @return array
	 */

	public static function params($param='')
	{
		if($param)
		{
			// Check for Multi-Demension Parameter
			if(strpos($param, '.'))
			{
				$nested_param = self::$params;
				$param_items = explode('.', $param);
				foreach ($param_items as $param_items_key => $param_item)
				{
					if($nested_param !== null && isset($nested_param[$param_item]))
					{
						$nested_param = $nested_param[$param_item];
					}
					else
					{
						$nested_param = null;
					}
				}

				return $nested_param;
			}

			if(isset(self::$params[$param]))
			{
				return self::$params[$param];
			}

			return null;
		}

		return self::$params;
	}




	/**
	 * Sets the Param Data
 	 *
 	 * @access 'public'
 	 * @return bool
	 */

	public static function set_params($params=[])
	{
		if(!empty($params))
		{
			self::$params = array_merge(self::$params, $params);
		}

		if(is_array(self::$params))
		{
			self::$params = self::run_filter('params', self::$params);
		}

		// Set Param Hooks
		self::run_hook('set_params');

		return true;
	}



	/**
	 * Gets the URL Path of the current API Call.
 	 *
 	 * @access 'public'
 	 * @return string
	 */

	public static function get_path()
	{
		$path = '';

		if(isset($_SERVER['REQUEST_URI']))
		{
			$path = explode('?', strtolower($_SERVER['REQUEST_URI']), 2);
			$path = self::clean_path($path[0]);
		}
		else if(isset($_SERVER['SCRIPT_FILENAME']) && strpos($_SERVER['SCRIPT_FILENAME'], 'SpryCli.php'))
		{
			$path = '::spry_cli';
		}
		else if(self::$cli)
		{
			$path = '::cli';
		}

		$path = self::run_filter('get_path', $path);
		return $path;
	}



	/**
	 * Cleans the Path given to a specified format.
 	 *
 	 * @access 'private'
 	 * @return string
	 */

	private static function clean_path($path)
	{
		return '/'.trim($path, " \t\n\r\0\x0B\/").'/';
	}



	/**
	 * Returns the Controller Object and Method by name.
	 * Throughs stop() on failure.
	 *
	 * @param string $controller
 	 *
 	 * @access 'private'
 	 * @return array
	 */

	private static function get_controller($controller='')
 	{
 		if(!empty($controller))
 		{
			if(!is_string($controller) && is_callable($controller))
			{
				return ['function' => $controller, 'class' => null, 'method' => null];
			}

 			$response_codes = self::get_core_response_codes();

 			list($class, $method) = explode('::', $controller);

 			$paths = [
 				'',
 				'Spry\\SpryComponent\\'
 			];

 			foreach($paths as $path)
 			{
 				if(class_exists($path.$class))
 				{
 					if(method_exists($path.$class, $method))
 					{
 						return ['class' => $path.$class, 'method' => $method];
 					}

 					// No Method for that Class
 					self::send_output(['status' => 'error', 'code' => 5013, 'messages' => [$response_codes[5013]['en'], $path.$class.'::'.$method]], false);
 				}
 			}

 			// No Classes Found
 			self::send_output(['status' => 'error', 'code' => 5012, 'messages' => [$response_codes[5012]['en'], $class]], false);
 		}

 		// No Controller
 		self::send_output(['status' => 'error', 'code' => 5016, 'messages' => [$response_codes[5016]['en'], $controller]], false);
 	}



	/**
	 * Determines whether a Controller Exists.
	 *
	 * @param string $controller
 	 *
 	 * @access 'public'
 	 * @return boolean
	 */

	public static function controller_exists($controller='')
	{
		if(!empty($controller))
		{
			list($class, $method) = explode('::', $controller);

			if(class_exists($class))
			{
				if(method_exists($class, $method))
				{
					return true;
				}
			}
			else if(class_exists('Spry\\SpryComponent\\'.$class))
			{
				if(method_exists('Spry\\SpryComponent\\'.$class, $method))
				{
					return true;
				}
			}
		}

		return false;
	}



	/**
	 * Return just the body of the request is successfull.
	 *
 	 * @param string $result
 	 *
 	 * @access 'public'
 	 * @return mixed
	 */

	public static function get_body($result)
	{
		if(!empty($result['status']) && $result['status'] === 'success' && isset($result['body']))
		{
			return $result['body'];
		}

		return null;
	}


	public static function run_filter($filter_key=null, $data=null)
	{
		if(!empty(self::$config->filters->$filter_key) && is_array(self::$config->filters->$filter_key))
		{
			foreach(self::$config->filters->$filter_key as $filter => $filter_data)
			{
				if(is_int($filter))
				{
					$filter = $filter_data;
					$data = self::get_response(self::get_controller($filter), $data);
				}
				else
				{
					$data = self::get_response(self::get_controller($filter), $data, $filter_data);
				}

			}
		}

		return $data;
	}


	public static function run_hook($hook_key=null, $data=null)
	{
		if(!empty(self::$config->hooks->$hook_key) && is_array(self::$config->hooks->$hook_key))
		{
			foreach(self::$config->hooks->$hook_key as $hook => $hook_data)
			{
				if(is_int($hook))
				{
					$hook = $hook_data;
				}

				// Skip Get Controller if Contrller not exists only for STOP
				// As it could cause a seg fault loop
				if($hook_key === 'stop' && !self::controller_exists($hook))
				{
					$response = self::build_response(5016, null, $hook);
					self::send_response($response);
					exit;
				}

				if($hook === $hook_data)
				{
					self::get_response(self::get_controller($hook), $data);
				}
				else
				{
					self::get_response(self::get_controller($hook), $data, $hook_data);
				}
			}
		}
	}



	/**
	 * Formats the Results given by a Controller method.
	 *
	 * @param int $response_code
	 * @param mixed $data
 	 *
 	 * @access 'public'
 	 * @return array
	 */

	public static function response($response_code=0, $data=null, $messages=[])
	{
		$response_code = strval($response_code);

		if(strlen($response_code) < 2)
		{
			$response_code = '00'.$response_code;
		}

		if(strlen($response_code) < 3)
		{
			$response_code = '0'.$response_code;
		}

		if(strlen($response_code) > 3)
		{
			return self::build_response($response_code, $data, $messages);
		}

		if(!empty($data) || $data === 0)
		{
			return self::build_response('2' . $response_code, $data, $messages);
		}

		// if(empty($data) && $data !== null && $data !== 0 && (!self::$db || (self::$db && method_exists(self::$db, 'hasError') && !self::$db->hasError())))
		if(empty($data) && $data !== null && $data !== 0)
		{
			return self::build_response('4' . $response_code, $data, $messages);
		}

		return self::build_response('5' . $response_code, null, $messages);
	}



	/**
	 * Formats the Response before given to the Output Method
	 *
	 * @param int $response_code
	 * @param mixed $data
 	 *
 	 * @access 'private'
 	 * @return array
	 */

	private static function build_response($response_code=0, $data=null, $messages=[])
	{
		$response = self::response_codes($response_code);

		if($data !== null)
		{
			$response['hash'] = md5($response_code.serialize($data));
			$response['body'] = $data;
		}

		if(!empty($messages) && (is_string($messages) || is_numeric($messages)))
		{
			$messages = [$messages];
		}

		if(!empty($messages))
		{
			$response['messages'] = array_merge($response['messages'], $messages);
		}

		$response = self::run_filter('build_response', $response);

		return $response;
	}



	/**
	 * Returns the Response from a given Controller method
	 *
	 * @param array $controller
	 * @param null $params			Params as Filtered items or from hook
	 * @param array $options		Options sent from Filter or Hook
 	 *
 	 * @access 'private'
 	 * @return mixed
	 */

	private static function get_response($controller=array(), $params=null, $options=null)
	{
		if(isset($controller['function']) && is_callable($controller['function']))
		{
			if($options)
			return call_user_func($controller['function'], $params, $options);

			if($params)
			return call_user_func($controller['function'], $params);

			return call_user_func($controller['function']);
		}

		if(!is_callable(array($controller['class'], $controller['method'])))
		{
			self::stop(5015, null, $controller['class'].'::'.$controller['method']);
		}

		if($options)
		return call_user_func(array($controller['class'], $controller['method']), $params, $options);

		if($params)
		return call_user_func(array($controller['class'], $controller['method']), $params);

		return call_user_func(array($controller['class'], $controller['method']));
	}



	/**
	 * Formats the Response and Sends
	 * it to the Output Method.
	 *
	 * @param array $response
 	 *
 	 * @access 'public'
 	 * @return void
	 */

	public static function send_response($response=array())
	{
		if(empty($response['status']) || empty($response['code']))
		{
			$response = self::build_response('', $response);
		}

		$response = self::run_filter('response', $response);

		self::send_output($response);
	}



	/**
	 * Formats the Response for output and
	 * sets the appropriate headers.
	 *
	 * @param array $output
 	 *
 	 * @access 'private'
 	 * @return void
	 */

	private static function send_output($response=array(), $run_filters=true)
	{
		$default_response_headers = [
			'Access-Control-Allow-Origin: *',
			'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS',
			'Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization'
		];

		$headers = (isset(self::$config->response_headers) ? self::$config->response_headers : $default_response_headers);

		$output = array_merge(
			[
				'status' => '',
				'code' => '',
				'method' => self::get_method(),
				'time' => number_format(microtime(true) - self::$timestart, 6),
				'request_id' => self::get_request_id(),
				'hash' => '',
				'messages' => '',
				'body' => '',
			],
			$response
		);

		$response = ['headers' => $headers, 'body' => json_encode($output)];

		$response = self::run_filter('output', $response);

		if(!empty($response['headers']))
		{
			foreach ($response['headers'] as $header)
			{
				header($header);
			}
		}

		if(!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS')
		{
			echo '';
		}
		else if(!empty($response['body']))
		{
			echo $response['body'];
		}

		exit;
	}

}
