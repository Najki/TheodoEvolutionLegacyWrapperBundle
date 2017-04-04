<?php

/**
 * The namespace must be defined this way to allow to define the
 * get_instance function later (see the bottom of the file)
 */
namespace Theodo\Evolution\Bundle\LegacyWrapperBundle\Kernel {

    use Symfony\Component\DependencyInjection\ContainerInterface;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Theodo\Evolution\Bundle\LegacyWrapperBundle\Autoload\LegacyClassLoaderInterface;
    use Theodo\Evolution\Bundle\LegacyWrapperBundle\Exception\KohanaException;

    class KohanaKernel extends LegacyKernel
    {
        /**
         * @var ContainerInterface
         */
        private $container;

        /**
         * {@inheritdoc}
         */
        public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true)
        {
            $session = $request->getSession();
            if ($session->isStarted()) {
                $session->save();
            }

            $_GET['kohana_uri'] = $request->getPathInfo();

            $response = new Response();

            $stopwatch = $this->container->get('debug.stopwatch');
            $stopwatchId = __METHOD__;
            $stopwatch->start($stopwatchId);

            ob_start();

            // Load benchmarking support
            require SYSPATH.'core/Benchmark'.EXT;

            // Start total_execution
            \Benchmark::start(SYSTEM_BENCHMARK.'_total_execution');

            // Start kohana_loading
            \Benchmark::start(SYSTEM_BENCHMARK.'_kohana_loading');

            // Load core files
            require SYSPATH.'core/utf8'.EXT;
            require SYSPATH.'core/Event'.EXT;
            require SYSPATH.'core/Kohana'.EXT;

            // Prepare the environment
            \Kohana::setup();

            // End kohana_loading
            \Benchmark::stop(SYSTEM_BENCHMARK.'_kohana_loading');

            // Start system_initialization
            \Benchmark::start(SYSTEM_BENCHMARK.'_system_initialization');

            // Prepare the system
            \Event::run('system.ready');

            // Set the error handler of CodeIgniter
//            set_error_handler('_exception_handler');

            // Determine routing
            \Event::run('system.routing');

            // End system_initialization
            \Benchmark::stop(SYSTEM_BENCHMARK.'_system_initialization');

            // Make the magic happen!
            \Event::run('system.execute');

            // Clean up and exit
            \Event::run('system.shutdown');

            $content = ob_get_clean(); // ob_end_clean();

            // Restore the Symfony2 error handler
            restore_error_handler();

            // Restart the Symfony 2 session
            $session->migrate();

            if (404 !== $response->getStatusCode()) {
                $response->setContent($content);
            }

            $stopwatch->stop($stopwatchId);

            return $response;



            // ---- old

            global $CFG, $RTR, $BM, $EXT, $CI, $URI, $OUT;

            ob_start();

            // Set the error handler of CodeIgniter
            set_error_handler('_exception_handler');

            // Load the app controller and local controller
            require_once BASEPATH . 'core/Controller.php';

            if (file_exists(APPPATH . 'core/' . $CFG->config['subclass_prefix'] . 'Controller.php')) {
                require_once APPPATH . 'core/' . $CFG->config['subclass_prefix'] . 'Controller.php';
            }

            // Load the local application controller
            // Note: The Router class automatically validates the controller path using the router->_validate_request().
            // If this include fails it means that the default controller in the Routes.php file is not resolving to something valid.
            if (!file_exists(APPPATH . 'controllers/' . $RTR->fetch_directory() . $RTR->fetch_class() . '.php')) {
                throw new KohanaException('Unable to load your default controller. Please make sure the controller specified in your Routes.php file is valid.');
            }

            include(APPPATH . 'controllers/' . $RTR->fetch_directory() . $RTR->fetch_class() . '.php');

            // Set a mark point for benchmarking
            $BM->mark('loading_time:_base_classes_end');

            // Security check
            $class = $RTR->fetch_class();
            $method = $RTR->fetch_method();

            if (!class_exists($class)
                OR strncmp($method, '_', 1) == 0
                OR in_array(strtolower($method), array_map('strtolower', get_class_methods('CI_Controller')))
            ) {
                if (!empty($RTR->routes['404_override'])) {
                    $x = explode('/', $RTR->routes['404_override']);
                    $class = $x[0];
                    $method = (isset($x[1]) ? $x[1] : 'index');
                    if (!class_exists($class)) {
                        if (!file_exists(APPPATH . 'controllers/' . $class . '.php')) {
                            $response->setStatusCode(404);
                            $response->setContent("The {$class}/{$method} does not exist in Kohana.");
                        }

                        include_once(APPPATH . 'controllers/' . $class . '.php');
                    }
                } else {
                    $response->setStatusCode(404);
                    $response->setContent("The {$class}/{$method} does not exist in Kohana.");
                }
            }

            // Is there a "pre_controller" hook?
            $EXT->_call_hook('pre_controller');

            // Instantiate the requested controller
            // Mark a start point so we can benchmark the controller
            $BM->mark('controller_execution_time_( ' . $class . ' / ' . $method . ' )_start');

            $CI = new $class();

            // Is there a "post_controller_constructor" hook?
            $EXT->_call_hook('post_controller_constructor');

            // Call the requested method
            // Is there a "remap" function? If so, we call it instead
            if (method_exists($CI, '_remap')) {
                $CI->_remap($method, array_slice($URI->rsegments, 2));
            } else {
                // is_callable() returns TRUE on some versions of PHP 5 for private and protected
                // methods, so we'll use this workaround for consistent behavior
                if (!in_array(strtolower($method), array_map('strtolower', get_class_methods($CI)))) {
                    // Check and see if we are using a 404 override and use it.
                    if (!empty($RTR->routes['404_override'])) {
                        $x = explode('/', $RTR->routes['404_override']);
                        $class = $x[0];
                        $method = (isset($x[1]) ? $x[1] : 'index');
                        if (!class_exists($class)) {
                            if (!file_exists(APPPATH . 'controllers/' . $class . '.php')) {
                                $response->setStatusCode(404);
                                $response->setContent("The {$class}/{$method} does not exist in Kohana.");
                            }

                            include_once(APPPATH . 'controllers/' . $class . '.php');
                            unset($CI);
                            $CI = new $class();
                        }
                    } else {
                        $response->setStatusCode(404);
                        $response->setContent("The {$class}/{$method} does not exist in Kohana.");
                    }
                }

                // Call the requested method.
                // Any URI segments present (besides the class/function) will be passed to the method for convenience
                call_user_func_array(array(&$CI, $method), array_slice($URI->rsegments, 2));
            }

            // Mark a benchmark end point
            $BM->mark('controller_execution_time_( ' . $class . ' / ' . $method . ' )_end');

            // Is there a "post_controller" hook?
            $EXT->_call_hook('post_controller');

            // Send the final rendered output to the browser
            if ($EXT->_call_hook('display_override') === false) {
                $OUT->_display();
            }

            // Is there a "post_system" hook?
            $EXT->_call_hook('post_system');

            // Close the DB connection if one exists
            if (class_exists('CI_DB') AND isset($CI->db)) {
                $CI->db->close();
            }

            ob_end_clean();

            // Restore the Symfony2 error handler
            restore_error_handler();

            // Restart the Symfony 2 session
            $session->migrate();

            if (404 !== $response->getStatusCode()) {
                $response->setContent($OUT->get_output());
            }

            return $response;
        }

        /**
         * {@inheritdoc}
         */
        public function boot(ContainerInterface $container)
        {
            if (empty($this->options)) {
                throw new \RuntimeException('You must provide options for the Kohana kernel.');
            }

            $this->container = $container;
            $sfKernel = $this->container->get('kernel');

            // Defines constants as it is done in the index.php file of your Kohana project.
            define('IN_SYMFONY', true);
            define('IN_PRODUCTION', $sfKernel->getEnvironment() == 'prod');

            // Content from Kohana's front controller

//            $ep = IN_PRODUCTION ? 0 : E_ALL ^ E_STRICT;
//            error_reporting($ep);
//            ini_set('display_errors', !IN_PRODUCTION);
            define('EXT', '.php');

            chdir($this->options['base_dir']);
            define('DOCROOT', $this->options['base_dir'].DIRECTORY_SEPARATOR);
            define('KOHANA', DOCROOT.'index.php');//  $kohana_pathinfo['basename']);

            // If the front controller is a symlink, change to the real docroot
            is_link(KOHANA) and chdir(dirname(realpath(__FILE__)));

            // Define application and system paths
            define('APPPATH', str_replace('\\', '/', realpath(DOCROOT.'application')).'/');
            define('MODPATH', str_replace('\\', '/', realpath(DOCROOT.'modules')).'/');
            define('SYSPATH', str_replace('\\', '/', realpath(DOCROOT.'system')).'/');
            define('KOHANA_VERSION',  '2.3.4');
            define('KOHANA_CODENAME', 'buteo regalis');
            define('KOHANA_IS_WIN', DIRECTORY_SEPARATOR === '\\');
            define('SYSTEM_BENCHMARK', 'system_benchmark');

            if (empty($this->classLoader)) {
                throw new \RuntimeException('You must provide a class loader to the Kohana kernel.');
            }

            if (!$this->classLoader->isAutoloaded()) {
                $this->classLoader->autoload();
            }

            $this->isBooted = true;
        }

        /**
         * Return the name of the kernel.
         *
         * @return string
         */
        public function getName()
        {
            return 'Kohana';
        }

        /**
         * @return ContainerInterface
         */
        public function getContainer()
        {
            return $this->container;
        }
    }
}

namespace {
    function &get_instance()
    {
        return \Kohana::instance();
    }
}
