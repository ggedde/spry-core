<?php

/**
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

namespace Spry;

use stdClass;

/**
 *
 * Spry Framework
 * https://github.com/ggedde/spry
 *
 * Copyright 2016, GGedde
 * Released under the MIT license
 *
 */
class Spry
{
    private static $auth;
    private static $cli = false;
    private static $config;
    private static $configFile = '';
    private static $db = null;
    private static $filters = [];
    private static $hooks = [];
    private static $params = [];
    private static $path = null;
    private static $requestId = '';
    private static $route = null;
    private static $routes = [];
    private static $test = false;
    private static $timestart;
    private static $validator;
    private static $version = "1.0.4";

    /**
     * Initiates the API Call.
     *
     * @param mixed $args
     *
     * @access public
     *
     * @return void
     */
    public static function run($args = [])
    {
        self::$timestart = microtime(true);
        self::$requestId = md5(uniqid('', true));

        if ($args && is_string($args)) {
            if (file_exists($args)) {
                $args = ['config' => $args];
            } else {
                // Check if it is not Json and if so then base64_decode it.
                if (!preg_match('/[\"\[\{]+/', $args)) {
                    $args = base64_decode($args);
                }

                $args = json_decode($args, true);
            }

            if (empty($args) || !is_array($args)) {
                $responseCodes = self::getCoreResponseCodes();

                $responseCode = 503;

                // Logger may not be setup so trigger php notice
                trigger_error('Spry ERROR: '.$responseCodes[$responseCode]);

                self::stop($responseCode);
            }
        }

        // Set Defaults
        $args = array_merge(
            [
                'config' => '',
                'path' => '',
                'controller' => '',
                'params' => null,
            ],
            $args
        );

        if (empty($args['config']) || (is_string($args['config']) && !file_exists($args['config']))) {
            $responseCodes = self::getCoreResponseCodes();

            $responseCode = 501;

            // Logger may not be setup so trigger php notice
            trigger_error('Spry ERROR: '.$responseCodes[$responseCode]);

            self::stop($responseCode);
        }

        self::$cli = self::isCli();

        // Setup Config Data Autoloader and Configure Filters
        self::configure($args['config']);

        if (empty(self::$config->projectPath) && is_string($args['config']) && file_exists($args['config'])) {
            self::$config->projectPath = dirname($args['config']);
        }

        if (empty(self::$config->salt)) {
            $responseCodes = self::getCoreResponseCodes();

            $responseCode = 502;

            // Logger may not be setup so trigger php notice
            trigger_error('Spry: '.$responseCodes[$responseCode]);

            self::stop($responseCode);
        }

        // Configure Hook
        self::runHook('configure');

        // Return Data Immediately if is a PreFlight OPTIONS Request
        if (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            self::sendOutput();
        }

        self::$path = (!empty($args['path']) ? $args['path'] : self::getPath());

        // Set Path Hook
        self::runHook('setPath');

        self::setRoutes();

        self::setParams(self::fetchParams($args['params']));

        // IF Test Data then set currnt transaction as Test
        if (!empty(self::$params['test_data'])) {
            self::$test = true;
        }

        if ($args['controller']) {
            $controller = self::getController($args['controller']);
        } else {
            self::$route = self::getRoute(self::$path);
            $controller = self::getController(self::$route['controller']);
        }

        if (self::$cli) {
            $response = self::getResponse($controller, self::$params);
        } else {
            $response = self::getResponse($controller, self::validateParams());
        }

        self::sendResponse($response);
    }

    /**
     * @access public
     *
     * @return boolean
     */
    public static function isCli()
    {
        return (
            php_sapi_name() === 'cli' ||
            (!empty($_SERVER['argc']) && is_numeric($_SERVER['argc']) && $_SERVER['argc'] > 0)
        );
    }

    /**
     * Loads the components
     *
     * @access public
     *
     * @return string
     */
    public static function getComponents()
    {
        if (!empty(self::$config->componentsDir) && is_dir(self::$config->componentsDir)) {
            foreach (glob(rtrim(self::$config->componentsDir, '/').'/*') as $file) {
                $componentName = str_replace('.php', '', basename($file));
                $class = '\\Spry\\SpryComponent\\'.$componentName;
                include_once $file;

                if (method_exists($class, 'setup')) {
                    $class::setup();
                }

                if (method_exists($class, 'getRoutes')) {
                    $routes = $class::getRoutes();
                    if (!empty($routes)) {
                        foreach ($routes as $routeKey => $route) {
                            self::$config->routes[$routeKey] = $route;
                        }
                    }
                }

                if (method_exists($class, 'getSchema')) {
                    $schemas = $class::getSchema();
                    if (!empty($schemas)) {
                        foreach ($schemas as $schemaKey => $schema) {
                            self::$config->db['schema']['tables'][$schemaKey] = $schema;
                        }
                    }
                }

                if (method_exists($class, 'getTests')) {
                    $tests = $class::getTests();
                    if (!empty($tests)) {
                        foreach ($tests as $testKey => $test) {
                            self::$config->tests[$testKey] = $test;
                        }
                    }
                }

                if (method_exists($class, 'getCodes')) {
                    $componentCodes = $class::getCodes();
                    if (!empty($componentCodes)) {
                        foreach ($componentCodes as $codeGroup => $codes) {
                            if (isset(self::$config->responseCodes[$codeGroup])) {
                                trigger_error('Spry Response Group Code ('.$codeGroup.') on Component ('.$class.') is already in use by another Component.');
                                self::log('Spry Response Group Code ('.$codeGroup.') on Component ('.$class.') is already in use by another Component.');
                            }
                            if (!empty($codes) && is_array($codes)) {
                                foreach ($codes as $codeKey => $code) {
                                    if (isset(self::$config->responseCodes[$codeGroup][$codeKey])) {
                                        trigger_error('Spry Response Code ('.$codeKey.') on Component ('.$class.') is already in use.');
                                        self::log('Spry Response Code ('.$codeKey.') on Component ('.$class.') is already in use.');
                                    }
                                    self::$config->responseCodes[$codeGroup][$codeKey] = $code;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @access public
     *
     * @return boolean
     */
    public static function isTest()
    {
        return self::$test;
    }

    /**
     * @access public
     *
     * @return string
     */
    public static function getVersion()
    {
        return self::$version;
    }

    /**
     * Returns that Path to the Spry Project.
     * Requires the config file to be loaded.
     *
     * @access public
     *
     * @return string
     */
    public static function getProjectPath()
    {
        return self::$config->projectPath;
    }

    /**
     * @access public
     *
     * @return string
     */
    public static function getConfigFile()
    {
        return self::$configFile;
    }

    /**
     * @access public
     *
     * @return string
     */
    public static function getRequestId()
    {
        return self::$requestId;
    }

    /**
     * @access public
     *
     * @return string
     */
    public static function getMethod()
    {
        $method = strtoupper(trim(!empty($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'POST'));
        if (in_array($method, ['POST', 'GET', 'PUT', 'DELETE'])) {
            return $method;
        }

        return null;
    }

    /**
     * @param mixed $configData
     *
     * @access public
     *
     * @return void
     */
    public static function configure($configData = '')
    {
        if (empty(self::$requestId)) {
            self::$requestId = md5(uniqid('', true));
        }

        if (!empty(self::$config)) {
            return false;
        }

        if (is_object($configData)) {
            $config = $configData;
        } else {
            $config = new stdClass();
            $config->hooks = new stdClass();
            $config->filters = new stdClass();
            $config->db = new stdClass();
            $config->logger = new stdClass();

            include $configData;
            self::$configFile = $configData;
        }

        $config->responseCodes = array_replace(
            self::getCoreResponseCodes(),
            (!empty($config->responseCodes) ? $config->responseCodes : [])
        );
        self::$config = $config;

        // Set AutoLoaders for Components, Providers and Plugins
        spl_autoload_register(array(__CLASS__, 'autoloader'));

        self::getComponents();

        // Configure Filter
        self::$config = self::runFilter('configure', self::$config);
    }

    /**
     * Returns the Route including path and attached controller.
     *
     * @param string $path
     *
     * @access public
     *
     * @return array
     */
    public static function getRoute($path = null)
    {
        if (!$path) {
            $path = self::$path;
        }

        $path = self::cleanPath($path);

        if (!empty($path)) {
            if (empty(self::$routes[$path]['controller'])) {
                foreach (self::$routes as $routeUrl => $route) {
                    if (is_string($route)) {
                        $routeUrl = $route;
                    }

                    $routeReg = '/'.str_replace('/', '\\/', preg_replace('/\{[^\}]+\}/', '(.*)', $routeUrl)).'/';

                    if (preg_match($routeReg, $path)) {
                        $path = $routeUrl;
                        break;
                    }
                }
            }

            if (empty(self::$routes[$path]['controller'])) {
                foreach (self::$routes as $routeUrl => $route) {
                    if (is_string($route)) {
                        $routeUrl = $route;
                    }

                    if (strpos($routeUrl, '{') !== false && strpos($routeUrl, '}')) {
                        $strippedPath = preg_replace('/\{[^\}]+\}\/?/', '', $routeUrl);

                        if ($strippedPath === $path) {
                            $path = $routeUrl;
                            break;
                        }
                    }
                }
            }

            if (empty(self::$routes[$path]['controller'])) {
                self::stop(511);
            }

            $route = self::$routes[$path];
        }

        if (!empty($route)) {
            $route['methods'] = array_map(
                'trim',
                array_map(
                    'strtoupper',
                    (!empty($route['methods']) ? (
                        is_string($route['methods']) ? [$route['methods']] : $route['methods']
                    )
                            :
                        ['POST'])
                )
            );

            // If Methods are configured for route then check if method is allowed
            if (!empty($route['methods']) &&
                is_array($route['methods']) &&
                !in_array(self::getMethod(), $route['methods'])
            ) {
                self::stop(517, $_POST); // Methoed not allowed
            }

            $route = self::runFilter('getRoute', $route);

            return $route;
        }

        self::stop(511); // Request Not Found
    }

    /**
     * Returns the public Routes available.
     *
     * @access public
     *
     * @return array
     */
    public static function getRoutes()
    {
        $publicRoutes = [];

        if (!empty(self::$routes)) {
            foreach (self::$routes as $routePath => $route) {
                if (!isset($route['public']) || !empty($route['public'])) {
                    $publicRoutes[$routePath] = $route;
                }
            }
        }

        return $publicRoutes;
    }

    /**
     * Sets the Autoloader for the Extra Classed needed for the API.
     *
     * @param string $class
     *
     * @access public
     *
     * @return void
     */
    public static function autoloader($class)
    {
        $autoloaderDirectories = [];

        if (!empty(self::$config->componentsDir)) {
            $autoloaderDirectories[] = self::$config->componentsDir;
        }

        if (!empty($autoloaderDirectories)) {
            foreach ($autoloaderDirectories as $dir) {
                foreach (glob(rtrim($dir, '/').'/*') as $file) {
                    if (strtolower(basename(str_replace('\\', '/', $class))).'.php' === strtolower(basename($file))) {
                        include_once $file;

                        return;
                    }
                }
            }
        }
    }

    /**
     * Returns DB Provider.
     *
     * @access public
     *
     * @return \Spry\SpryProvider\SpryDB
     */
    public static function db()
    {
        if (!self::$db) {
            if (empty(self::$config->dbProvider) || !class_exists(self::$config->dbProvider)) {
                self::stop(533);
            }

            $class = self::$config->dbProvider;

            self::$db = new $class(self::$config->db);

            // Database Hooks
            self::runHook('database');
        }

        return self::$db;
    }

    /**
     * Returns Logger Provider.
     *
     * @param string $message
     *
     * @access public
     *
     * @return object
     */
    public static function log($message = '')
    {
        if (empty(self::$config->loggerProvider)) {
            self::stop(540);
        }

        $class = self::$config->loggerProvider;

        if (!class_exists($class)) {
            self::stop(540);
        }

        if ($message) {
            if (!method_exists($class, 'log')) {
                trigger_error('Spry: Log Provider missing method "log".', E_USER_WARNING);
            }

            return $class::log($message);
        }

        return null;
    }

    /**
     * Returns Validator Extension.
     *
     * @param mixed $params
     *
     * @access public
     *
     * @return object
     */
    public static function validator($params = null)
    {
        if (is_null($params)) {
            $params = self::$params;
        }

        if (empty(self::$validator)) {
            self::$validator = new SpryProvider\SpryValidator($params);
        } else {
            self::$validator->setData($params);
        }

        return self::$validator;
    }

    /**
     * Kills the Request and returns immediate error.
     *
     * @param int|string $responseCode
     * @param mixed      $data
     * @param array      $messages
     * @param array      $privateData
     *
     * @access public
     *
     * @return void
     */
    public static function stop($responseCode = '', $data = null, $messages = [], $privateData = null)
    {
        if (!empty($messages) && (is_string($messages) || is_numeric($messages))) {
            $messages = [$messages];
        }

        $params = [
            'code' => $responseCode,
            'data' => $data,
            'messages' => $messages,
            'private_data' => $privateData,
        ];

        self::runHook('stop', $params);

        $response = self::buildResponse($responseCode, $data, $messages);

        self::sendResponse($response);
    }

    /**
     * Sets the Auth object.
     *
     * @param mixed $object
     *
     * @access public
     *
     * @return object
     */
    public static function setAuth($object)
    {
        self::$auth = $object;
    }

    /**
     * Returns the Auth object.
     *
     * @access public
     *
     * @return object
     */
    public static function auth()
    {
        return self::$auth;
    }

    /**
     * Returns the Config Parameters from the Singleton Class.
     *
     * @access public
     *
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
     * @param string $param
     *
     * @access public
     *
     * @return array
     */
    public static function params($param = '')
    {
        if ($param) {
            // Check for Multi-Demension Parameter
            if (strpos($param, '.')) {
                $nestedParam = self::$params;
                $paramItems = explode('.', $param);
                foreach ($paramItems as $paramItem) {
                    if (!is_null($nestedParam) && isset($nestedParam[$paramItem])) {
                        $nestedParam = $nestedParam[$paramItem];
                    } else {
                        $nestedParam = null;
                    }
                }

                return $nestedParam;
            }

            if (isset(self::$params[$param])) {
                return self::$params[$param];
            }

            return null;
        }

        return self::$params;
    }

    /**
     * Sets the Param Data
     *
     * @param array $params
     *
     * @access public
     *
     * @return bool
     */
    public static function setParams($params = [])
    {
        if (!empty($params)) {
            self::$params = array_merge(self::$params, $params);
        }

        if (is_array(self::$params)) {
            self::$params = self::runFilter('params', self::$params);
        }

        // Set Param Hooks
        self::runHook('setParams');

        return true;
    }

    /**
     * Gets the URL Path of the current API Call.
     *
     * @access public
     *
     * @return string
     */
    public static function getPath()
    {
        $path = '';

        if (isset($_SERVER['REQUEST_URI'])) {
            $path = explode('?', strtolower($_SERVER['REQUEST_URI']), 2);
            $path = self::cleanPath($path[0]);
        } elseif (isset($_SERVER['SCRIPT_FILENAME']) && strpos($_SERVER['SCRIPT_FILENAME'], 'SpryCli.php')) {
            $path = '::spry_cli';
        } elseif (self::$cli) {
            $path = '::cli';
        }

        $path = self::runFilter('getPath', $path);

        return $path;
    }

    /**
     * Determines whether a Controller Exists.
     *
     * @param string $controller
     *
     * @access public
     *
     * @return boolean
     */
    public static function controllerExists($controller = '')
    {
        if (!empty($controller)) {
            if (!is_string($controller) || strpos($controller, '::') === false) {
                return false;
            }

            list($class, $method) = explode('::', $controller);

            if (class_exists($class)) {
                if (method_exists($class, $method)) {
                    return true;
                }
            } elseif (class_exists('Spry\\SpryComponent\\'.$class)) {
                if (method_exists('Spry\\SpryComponent\\'.$class, $method)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Return just the body of the request if successfull.
     *
     * @param mixed $result
     *
     * @access public
     *
     * @return mixed
     */
    public static function getBody($result)
    {
        if (!empty($result['status']) && $result['status'] === 'success' && isset($result['body'])) {
            return $result['body'];
        }

        return null;
    }

    /**
     * Adds Hook to Spry Hooks
     *
     * @param string $filterKey
     * @param mixed  $controller
     * @param array  $extraData
     * @param int    $order
     *
     * @return mixed
     */
    public static function addFilter($filterKey = '', $controller = null, $extraData = [], $order = 0)
    {
        if (empty(self::$filters[$filterKey]) || !is_array(self::$filters[$filterKey])) {
            self::$filters[$filterKey] = [];
        }
        self::$filters[$filterKey][] = [
            'controller' => $controller,
            'extraData' => $extraData,
            'order' => $order,
        ];
    }

    /**
     * @param string $filterKey
     * @param mixed  $data
     *
     * @return mixed
     */
    public static function runFilter($filterKey = null, $data = null)
    {
        if (!empty(self::$filters[$filterKey]) && is_array(self::$filters[$filterKey])) {
            array_multisort(array_column(self::$filters[$filterKey], 'order'), SORT_ASC, self::$filters[$filterKey]);
            foreach (self::$filters[$filterKey] as $filter) {
                if (!empty($filter['controller'])) {
                    $data = self::getResponse(self::getController($filter['controller']), $data, $filter['extraData'] ?? null);
                }
            }
        }

        return $data;
    }

    /**
     * Adds Hook to Spry Hooks
     *
     * @param string $hookKey
     * @param mixed  $controller
     * @param array  $extraData
     * @param int    $order
     *
     * @return mixed
     */
    public static function addHook($hookKey = '', $controller = null, $extraData = [], $order = 0)
    {
        if (empty(self::$hooks[$hookKey]) || !is_array(self::$hooks[$hookKey])) {
            self::$hooks[$hookKey] = [];
        }
        self::$hooks[$hookKey][] = [
            'controller' => $controller,
            'extraData' => $extraData,
            'order' => $order,
        ];
    }

    /**
     * @param string $hookKey
     * @param mixed  $data
     *
     * @return void
     */
    public static function runHook($hookKey = null, $data = null)
    {
        if (!empty(self::$hooks[$hookKey]) && is_array(self::$hooks[$hookKey])) {
            array_multisort(array_column(self::$hooks[$hookKey], 'order'), SORT_ASC, self::$hooks[$hookKey]);
            foreach (self::$hooks[$hookKey] as $hook) {
                if (!empty($hook['controller'])) {
                    // Skip Get Controller if Contrller not exists only for STOP
                    // As it could cause a seg fault loop
                    if (strval($hookKey) === 'stop' && !is_callable($hook['controller']) && !self::controllerExists($hook['controller'])) {
                        $response = self::buildResponse(516, null, is_string($hook['controller']) ? $hook['controller'] : $hookKey);
                        self::sendResponse($response);
                        exit;
                    }
                    $data = self::getResponse(self::getController($hook['controller']), $data, $hook['extraData'] ?? null);
                }
            }
        }
    }

    /**
     * Formats the Results given by a Controller method.
     *
     * @param int|string $responseCodeGroup
     * @param int|string $responseCode
     * @param mixed      $data
     * @param array      $messages
     * @param array|null $meta
     *
     * @access public
     *
     * @return array
     */
    public static function response($responseCodeGroup = 0, $responseCode = 0, $data = null, $messages = [], $meta = null)
    {
        $responseCodeGroup = trim(strval($responseCodeGroup));
        $responseCode = trim(strval($responseCode));

        // print_r(debug_backtrace(0, 2));
        if (strlen($responseCode) < 2) {
            $responseCode = '0'.$responseCode;
        }

        if (strlen($responseCode) > 2) {
            $type = '';
        } elseif (!empty($data) || 0 === $data) {
            $type = '2';
        } elseif (empty($data) && !is_null($data) && 0 !== $data) {
            $type = '4';
        } else {
            $type = '5';
        }

        return self::buildResponse($responseCodeGroup.'-'.$type.$responseCode, $data, $messages, $meta);
    }

    /**
     * Formats the Response and Sends
     * it to the Output Method.
     *
     * @param array $response
     *
     * @access public
     *
     * @return void
     */
    public static function sendResponse($response = array())
    {
        if (empty($response['status']) || empty($response['code'])) {
            $response = self::buildResponse('', $response);
        }

        $response = self::runFilter('response', $response);

        self::sendOutput($response);
    }

    /**
     * @access public
     *
     * @return array
     */
    private static function getCoreResponseCodes()
    {
        return [
            0 => [
                /* Initial Config */
                200 => 'Success!',
                400 => 'Unknown Results',
                500 => 'Error: Unknown Error',
                501 => 'Error: Missing Config File',
                502 => 'Error: Missing Salt in Config File',
                503 => 'Error: Unknown configuration error on run',

                /* Routes, Paths, Controllers */
                511 => 'Error: Route Not Found.',
                512 => 'Error: Class Not Found.',
                513 => 'Error: Class Method Not Found.',
                514 => 'Error: Returned Data is not in JSON format.',
                515 => 'Error: Class Method is not Callable. Make sure it is Public.',
                516 => 'Error: Controller Not Found.',
                517 => 'Error: Method not allowed by Route.',

                /* DB */
                520 => 'Error: Field did not Validate.',

                /* DB */
                230 => 'Database Migrate Ran Successfully',
                530 => 'Error: Database Migrate had an Error',
                531 => 'Error: Database Connect Error.',
                532 => 'Error: Missing Database Credentials from config.',
                533 => 'Error: Database Provider not found.',

                /* Log */
                540 => 'Error: Log Provider not found.',

                /* Tests */
                250 => 'Test Passed Successfully',
                550 => 'Error: Test Failed',
                551 => 'Error: Retrieving Tests',
                552 => 'Error: No Tests Configured',
                553 => 'Error: No Test with that name Configured',

                /* Background Process */
                560 => 'Error: Background Process did not return Process ID',
                561 => 'Error: Background Process could not find autoload',
                562 => 'Error: Unknown response from Background Process',
            ],
        ];
    }

    /**
     * Adds all the Routes to allow.
     *
     * @access private
     *
     * @return void
     */
    private static function setRoutes()
    {
        foreach (self::$config->routes as $routePath => $route) {
            if (!empty($route) && (!isset($route['active']) || !empty($route['active']))) {
                self::addRoute($routePath, $route);
            }
        }

        // Route Hooks
        self::runHook('setRoutes');
    }

    /**
     * Gets the response Type.
     *
     * @param string | int $code
     *
     * @access private
     *
     * @return string
     */
    private static function responseType($code = '')
    {
        $pos = strpos($code, '-');

        if (false !== $pos) {
            $code = substr($code, ($pos + 1));
        }

        if (!empty($code) && is_numeric($code)) {
            switch (substr($code, 0, 1)) {
                case 1:
                    return 'info';
                case 2:
                case 4:
                    return 'success';

                case 5:
                    return 'error';
            }
        }

        return 'unknown';
    }

    /**
     * Sets all the response Codes available for the App.
     *
     * @param int|string $code
     *
     * @access private
     *
     * @return array
     */
    private static function responseCodes($code = '')
    {
        $lang = 'en';

        if (self::params('lang')) {
            $lang = self::params('lang');
        }

        $group = 0;
        $code = trim(strval($code));

        if ($pos = strpos($code, '-')) {
            $group = substr($code, 0, $pos);
            $code = substr($code, ($pos + 1));
        }

        $codes = self::$config->responseCodes;

        if (isset($codes[$group][$code][$lang]) && is_string($codes[$group][$code][$lang])) {
            $response = $codes[$group][$code][$lang];
        } elseif (isset($codes[$group][$code]) && is_string($codes[$group][$code])) {
            $response = $codes[$group][$code];
        } elseif (isset($codes['400'][$lang]) && is_string($codes['400'][$lang])) {
            $code = '400';
            $response = $codes[$group][$code][$lang];
        } elseif (isset($codes['400']) && is_string($codes['400'])) {
            $code = '400';
            $response = $codes[$group][$code];
        }

        $code = $group.'-'.$code;

        if (!empty($response)) {
            return ['status' => self::responseType($code), 'code' => $code, 'messages' => [$response]];
        }

        return ['status' => 'unknown', 'code' => $code, 'messages' => ['Unkown Response Code']];
    }

    /**
     * Adds a route to the allowed list.
     *
     * @param string $path
     * @param string $route
     *
     * @access private
     *
     * @return void
     */
    private static function addRoute($path, $route)
    {
        $path = self::cleanPath($path);

        if (!is_array($route)) {
            $route = [
                'label' => ucwords(preg_replace('/\W|_/', ' ', $path)),
                'controller' => $route,
            ];
        }

        $route = array_merge(
            [
                'controller' => '',
                'active' => true,
                'public' => true,
                'label' => '',
                'params' => [],
            ],
            $route
        );

        self::$routes[$path] = $route;
    }

    /**
     * Adds a route to the allowed list.
     *
     * @param string $path
     * @param mixed  $params
     * @param mixed  $args
     *
     * @access private
     *
     * @return void
     */
    private static function validateParams($path = null, $params = null, $args = null)
    {
        if (!empty($path)) {
            $route = self::getRoute($path);
        }

        if (empty($route)) {
            $route = (self::$route ? self::$route : self::getRoute());
        }

        if (is_null($params)) {
            $params = self::params();
        }

        if (empty($route['params'])) {
            $newParams = $params;
        } else {
            $newParams = [];

            if (!empty($params['test_data'])) {
                $newParams['test_data'] = $params['test_data'];
            }

            $messageFields = [
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
                'string',
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
                'in',
                'callback',
                'hassymbols',
                'hasnumbers',
                'hasletters',
                'haslowercase',
                'hasuppercase',
            ];

            foreach ($route['params'] as $paramKey => $paramSettings) {
                if (is_int($paramKey) && $paramSettings && is_string($paramSettings)) {
                    $paramKey = $paramSettings;
                    $paramSettings = [];
                }

                if (!isset($paramSettings['trim']) && !empty($route['params_trim'])) {
                    $paramSettings['trim'] = true;
                }

                $required = (empty($paramSettings['required']) ? false : true);

                if (!empty($paramSettings['required']) && is_array($paramSettings['required'])) {
                    foreach ($paramSettings['required'] as $requiredFieldKey => $requiredFieldValue) {
                        if (!isset($params[$requiredFieldKey]) ||
                            $params[$requiredFieldKey] !== $requiredFieldValue
                        ) {
                            $required = false;
                        }
                    }
                }

                // Skip if not Required or Param is not present
                if (empty($required) && !isset($params[$paramKey])) {
                    continue;
                }

                // Set Default
                if (!isset($params[$paramKey]) && isset($paramSettings['default'])) {
                    $params[$paramKey] = $paramSettings['default'];
                }

                $messages = [];

                foreach ($messageFields as $field) {
                    $messages[$field] = (
                        !empty($paramSettings['messages'][$field])
                            ?
                        $paramSettings['messages'][$field]
                            :
                        null
                    );
                }

                // Construct Validator
                $validator = self::validator($params);

                if ($required) {
                    $validator->required($messages['required']);
                }

                if (isset($paramSettings['type'])) {
                    switch ($paramSettings['type']) {
                        case 'int':
                        case 'integer':
                            $validator->integer($messages['integer']);
                            break;

                        case 'number':
                        case 'num':
                        case 'float':
                            $validator->float($messages['float']);
                            break;

                        case 'array':
                            $validator->isarray($messages['array']);
                            break;

                        case 'cardNumber':
                            $validator->ccnum($messages['ccnum']);
                            break;

                        case 'date':
                            $validator->date($messages['date']);
                            break;

                        case 'email':
                            $validator->email($messages['email']);
                            break;

                        case 'url':
                            $validator->url($messages['url']);
                            break;

                        case 'ip':
                            $validator->ip($messages['ip']);
                            break;

                        case 'domain':
                            $validator->domain($messages['domain']);
                            break;

                        case 'string':
                            $validator->string($messages['string']);
                            break;

                        case 'boolean':
                        case 'bool':
                            $validator->boolean($messages['string']);
                            break;

                        case 'password':
                            $validator->string($messages['string']);
                            $validator->minLength(10, $messages['minlength']);
                            $validator->hasSymbols(1, $messages['hassymbols']);
                            $validator->hasNumbers(1, $messages['hasnumbers']);
                            $validator->hasLetters(1, $messages['hasletters']);
                            $validator->hasLowercase(1, $messages['haslowercase']);
                            $validator->hasUppercase(1, $messages['hasuppercase']);
                            break;
                    }
                }

                if (isset($paramSettings['minLength'])) {
                    $validator->minLength($paramSettings['minLength'], $messages['minlength']);
                }

                if (isset($paramSettings['maxLength'])) {
                    $validator->maxLength($paramSettings['maxLength'], $messages['maxlength']);
                }

                if (isset($paramSettings['length'])) {
                    $validator->length($paramSettings['length'], $messages['length']);
                }

                if (isset($paramSettings['min'])) {
                    $validator->min($paramSettings['min'], true, $messages['min']);
                }

                if (isset($paramSettings['max'])) {
                    $validator->max($paramSettings['max'], true, $messages['max']);
                }

                if (isset($paramSettings['between'])) {
                    $validator->between(
                        $paramSettings['between'][0],
                        $paramSettings['between'][1],
                        true,
                        $messages['between']
                    );
                }

                if (isset($paramSettings['betweenLength'])) {
                    $validator->betweenlength(
                        $paramSettings['betweenLength'][0],
                        $paramSettings['betweenLength'][1],
                        $messages['betweenlength']
                    );
                }

                if (isset($paramSettings['matches'])) {
                    $validator->matches(
                        $paramSettings['matches'],
                        ucwords($paramSettings['matches']),
                        $messages['matches']
                    );
                }

                if (isset($paramSettings['notMatches'])) {
                    $validator->notmatches(
                        $paramSettings['notMatches'],
                        ucwords($paramSettings['notMatches']),
                        $messages['notmatches']
                    );
                }

                if (isset($paramSettings['startsWith'])) {
                    $validator->startsWith($paramSettings['startsWith'], $messages['startswith']);
                }

                if (isset($paramSettings['notStartsWith'])) {
                    $validator->notstartsWith($paramSettings['notStartsWith'], $messages['notstartswith']);
                }

                if (isset($paramSettings['endsWith'])) {
                    $validator->endsWith($paramSettings['endsWith'], $messages['endswith']);
                }

                if (isset($paramSettings['notEndsWith'])) {
                    $validator->notendsWith($paramSettings['notEndsWith'], $messages['notendswith']);
                }

                if (isset($paramSettings['numbersOnly'])) {
                    $validator->digits($messages['digits']);
                }

                if (isset($paramSettings['minDate'])) {
                    $validator->minDate($paramSettings['minDate'], null, $messages['mindate']);
                }

                if (isset($paramSettings['maxDate'])) {
                    $validator->maxDate($paramSettings['maxDate'], null, $messages['maxdate']);
                }

                if (isset($paramSettings['in'])) {
                    $validator->in($paramSettings['in'], $messages['in']);
                }

                if (isset($paramSettings['has'])) {
                    $validator->has($paramSettings['has'], $messages['has']);
                }

                if (isset($paramSettings['hasSymbols'])) {
                    $validator->hasSymbols($paramSettings['hasSymbols'], $messages['hasSymbols']);
                }

                if (isset($paramSettings['hasNumbers'])) {
                    $validator->hasNumbers($paramSettings['hasNumbers'], $messages['hasNumbers']);
                }

                if (isset($paramSettings['hasLetters'])) {
                    $validator->hasLetters($paramSettings['hasLetters'], $messages['hasLetters']);
                }

                if (isset($paramSettings['hasLowercase'])) {
                    $validator->hasLowercase($paramSettings['hasLowercase'], $messages['hasLowercase']);
                }

                if (isset($paramSettings['hasUppercase'])) {
                    $validator->hasUppercase($paramSettings['hasUppercase'], $messages['hasUppercase']);
                }

                if (isset($paramSettings['callback'])) {
                    $validator->callback($paramSettings['callback'], $messages['callback']);
                }

                if (isset($paramSettings['filter'])) {
                    $validator->filter($paramSettings['filter']);
                }

                if (!empty($paramSettings['validateOnly'])) {
                    $validator->validate($paramKey);
                } else {
                    $newParams[$paramKey] = $validator->validate($paramKey);

                    if (is_array($newParams[$paramKey]) || is_object($newParams[$paramKey])) {
                        if (!empty($paramSettings['trim'])) {
                            $newParams[$paramKey] = array_values(
                                array_filter(array_map('trim', $newParams[$paramKey]))
                            );
                        }

                        if (!empty($paramSettings['unique'])) {
                            $newParams[$paramKey] = array_values(array_unique($newParams[$paramKey]));
                        }
                    } else {
                        if (!empty($paramSettings['trim'])) {
                            $newParams[$paramKey] = trim($newParams[$paramKey]);
                        }
                    }
                }
            }
        }

        $newParams = self::runFilter('validateParams', $newParams);

        return $newParams;
    }

    /**
     * Gets the Data sent in the API Call and converts it to Parameters.
     * Then returns the converted Parameters as array.
     * Throughs stop() on failure.
     *
     * @param mixed $params
     *
     * @access private
     *
     * @return array
     */
    private static function fetchParams($params = null)
    {
        if (!is_null($params)) {
            $data = $params;
        } else {
            $data = trim(file_get_contents('php://input'));

            if (empty($data) && self::$cli) {
                $data = trim(file_get_contents('php://stdin'));
            }

            if (empty($data) && self::getMethod() === 'GET' && !empty($_GET)) {
                $data = $_GET;
            }

            if (empty($data) && self::getMethod() === 'POST' && !empty($_POST)) {
                $data = $_POST;
            }

            if (empty($data)) {
                $data = [];
            }

            foreach (self::$routes as $routeUrl => $route) {
                if (is_string($route)) {
                    $routeUrl = $route;
                }

                $routeReg = '/'.str_replace('/', '\\/', preg_replace('/\{[^\}]+\}/', '(.*)', $routeUrl)).'/';

                preg_match_all('/\{([^\}]+)\}/', $routeUrl, $matchParams);
                preg_match_all($routeReg, self::$path, $matchValues);

                if (preg_match($routeReg, self::$path) && !empty($matchParams[1]) && !empty($matchValues[1])) {
                    foreach ($matchParams[1] as $matchParamKey => $matchParam) {
                        $data[$matchParam] = $matchValues[1][$matchParamKey];
                    }
                }
            }
        }

        if ($data && is_string($data)) {
            if (in_array(substr($data, 0, 1), ['[', '{'])) {
                $data = json_decode($data, true);
            } else {
                // TODO
                echo '<pre>';
                print_r($data);
                echo '</pre>';
                exit;
            }
        }

        if (!empty($data)) {
            $data = self::runFilter('params', $data);
        }

        if (!empty($data) && !is_array($data)) {
            self::stop(514); // Returned Data is not in JSON format
        }

        return $data;
    }

    /**
     * Cleans the Path given to a specified format.
     *
     * @param string $path
     *
     * @access private
     *
     * @return string
     */
    private static function cleanPath($path)
    {
        return '/'.trim($path, " \t\n\r\0\x0B\/").'/';
    }

    /**
     * Returns the Controller Object and Method by name.
     * Throughs stop() on failure.
     *
     * @param string $controller
     *
     * @access private
     *
     * @return array
     */
    private static function getController($controller = '')
    {
        if (!empty($controller)) {
            if (!is_string($controller) && is_callable($controller)) {
                return ['function' => $controller, 'class' => null, 'method' => null];
            }

            $responseCodes = self::getCoreResponseCodes();

            list($class, $method) = explode('::', $controller);

            $paths = [
                '',
                'Spry\\SpryComponent\\',
            ];

            foreach ($paths as $path) {
                if (class_exists($path.$class)) {
                    if (method_exists($path.$class, $method)) {
                        return ['class' => $path.$class, 'method' => $method];
                    }

                    $responseCode = 513;

                    // No Method for that Class
                    self::sendOutput(
                        [
                            'status' => 'error',
                            'code' => $responseCode,
                            'messages' => [$responseCodes[$responseCode],
                                $path.$class.'::'.$method, ],
                        ],
                        false
                    );
                }
            }

            $responseCode = 512;

            // No Classes Found
            self::sendOutput([
                'status' => 'error',
                'code' => $responseCode,
                'messages' => [$responseCodes[$responseCode],
                    $class, ],
            ], false);
        }

        $responseCode = 516;

        // No Controller
        self::sendOutput([
            'status' => 'error',
            'code' => $responseCode,
            'messages' => [$responseCodes[$responseCode],
                $controller, ],
        ], false);
    }

    /**
     * Formats the Response before given to the Output Method
     *
     * @param int|string   $responseCode
     * @param mixed        $data
     * @param mixed        $messages
     * @param array | null $meta
     *
     * @access private
     *
     * @return array
     */
    private static function buildResponse($responseCode = '', $data = null, $messages = [], $meta = null)
    {
        $responseCode = trim(strval($responseCode));
        $response = self::responseCodes($responseCode);

        if (!is_null($data)) {
            $response['hash'] = md5($responseCode.serialize($data));
            $response['body'] = $data;
        }

        if (!empty($messages) && (is_string($messages) || is_numeric($messages))) {
            $messages = [$messages];
        }

        if (!empty($messages)) {
            $response['messages'] = array_merge($response['messages'], $messages);
        }

        if (!empty($meta) && is_array($meta)) {
            foreach ($meta as $key => $value) {
                $response[$key] = $value;
            }
        }

        $response = self::runFilter('buildResponse', $response);

        return $response;
    }

    /**
     * Returns the Response from a given Controller method
     *
     * @param array $controller
     * @param null  $params     Params as Filtered items or from hook
     * @param array $options    Options sent from Filter or Hook
     *
     * @access private
     *
     * @return mixed
     */
    private static function getResponse($controller = array(), $params = null, $options = null)
    {
        if (isset($controller['function']) && is_callable($controller['function'])) {
            if ($options) {
                return call_user_func($controller['function'], $params, $options);
            }

            if ($params) {
                return call_user_func($controller['function'], $params);
            }

            return call_user_func($controller['function']);
        }

        if (!is_callable(array($controller['class'], $controller['method']))) {
            self::stop(515, null, $controller['class'].'::'.$controller['method']);
        }

        if ($options) {
            return call_user_func(array($controller['class'], $controller['method']), $params, $options);
        }

        if ($params) {
            return call_user_func(array($controller['class'], $controller['method']), $params);
        }

        return call_user_func(array($controller['class'], $controller['method']));
    }

    /**
     * Formats the Response for output and
     * sets the appropriate headers.
     *
     * @param array $response
     * @param array $runFilters
     *
     * @access private
     *
     * @return void
     */
    private static function sendOutput($response = array(), $runFilters = true)
    {
        $defaultResponseHeaders = [
            'Access-Control-Allow-Origin: *',
            'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization',
        ];

        $headers = isset(self::$config->responseHeaders) ? self::$config->responseHeaders : $defaultResponseHeaders;

        $output = array_merge(
            [
                'status' => '',
                'code' => '',
                'method' => self::getMethod(),
                'time' => number_format(microtime(true) - self::$timestart, 6),
                'requestId' => self::getRequestId(),
                'hash' => '',
                'messages' => '',
                'body' => '',
            ],
            $response
        );

        $response = ['headers' => $headers, 'body' => json_encode($output)];

        $response = self::runFilter('output', $response);

        if (!empty($response['headers'])) {
            foreach ($response['headers'] as $header) {
                header($header);
            }
        }

        if (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            echo '';
        } elseif (!empty($response['body'])) {
            echo $response['body'];
        }

        exit;
    }
}
