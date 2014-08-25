<?php

/**
 * Bootstrap
 * @copyright Copyright (c) 2011 - 2014 Aleksandr Torosh (http://wezoom.com.ua)
 * @author Aleksandr Torosh <webtorua@gmail.com>
 */
class Bootstrap
{

    public function run()
    {
        $di = new \Phalcon\DI\FactoryDefault();


        $config = include APPLICATION_PATH . '/config/application.php';
        $di->set('config', $config);


        $registry = new \Phalcon\Registry();


        $loader = new \Phalcon\Loader();
        $loader->registerNamespaces($config->loader->namespaces->toArray());
        $loader->register();
        $loader->registerDirs(array(APPLICATION_PATH . "/plugins/"));


        $db = new \Phalcon\Db\Adapter\Pdo\Mysql(array(
            "host" => $config->database->host,
            "username" => $config->database->username,
            "password" => $config->database->password,
            "dbname" => $config->database->dbname,
            "charset" => $config->database->charset,
        ));
        $di->set('db', $db);


        $view = new \Phalcon\Mvc\View();

        define('MAIN_VIEW_PATH', '../../../views/');
        $view->setMainView(MAIN_VIEW_PATH . 'main');
        $view->setLayoutsDir(MAIN_VIEW_PATH . '/layouts/');
        $view->setPartialsDir(MAIN_VIEW_PATH . '/partials/');

        $volt = new \Phalcon\Mvc\View\Engine\Volt($view, $di);
        $volt->setOptions(array('compiledPath' => APPLICATION_PATH . '/cache/volt/'));

        $phtml = new \Phalcon\Mvc\View\Engine\Php($view, $di);
        $viewEngines = array(
            ".volt" => $volt,
            ".phtml" => $phtml,
        );
        $registry->viewEngines = $viewEngines;

        $view->registerEngines($viewEngines);

        if (isset($_GET['_ajax']) && $_GET['_ajax']) {
            $view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_LAYOUT);
        }

        $di->set('view', $view);


        $viewSimple = new \Phalcon\Mvc\View\Simple();
        $viewSimple->registerEngines($viewEngines);
        $di->set('viewSimple', $viewSimple);


        $url = new \Phalcon\Mvc\Url();
        $url->setBasePath('/');
        $url->setBaseUri('/');

        $cacheFrontend = new \Phalcon\Cache\Frontend\Data(array(
            "lifetime" => 60,
            "prefix" => HOST_HASH,
        ));

        switch ($config->cache) {
            case 'file':
                $cache = new \Phalcon\Cache\Backend\File($cacheFrontend, array(
                    "cacheDir" => __DIR__ . "/cache/backend/"
                ));
                break;
            case 'memcache':
                $cache = new \Phalcon\Cache\Backend\Memcache(
                    $cacheFrontend, array(
                    "host" => "localhost",
                    "port" => "11211"
                ));
                break;
        }
        $di->set('cache', $cache);
        $di->set('modelsCache', $cache);


        switch ($config->metadata_cache) {
            case 'memory':
                $modelsMetadata = new \Phalcon\Mvc\Model\Metadata\Memory();
                break;
            case 'apc':
                $modelsMetadata = new \Phalcon\Mvc\Model\MetaData\Apc(array(
                    "lifetime" => 60,
                    "prefix" => HOST_HASH,
                ));
                break;
        }
        $di->set('modelsMetadata', $modelsMetadata);

        /**
         * CMS Конфигурация
         */
        $cmsModel = new \Cms\Model\Configuration();
        $cms = $cmsModel->getConfig();
        $registry->cms = $cms; // Отправляем в Registry


        $application = new \Phalcon\Mvc\Application();

        $application->registerModules($config->modules->toArray());


        $router = new \Application\Mvc\Router\DefaultRouter();
        foreach ($application->getModules() as $module) {
            $className = str_replace('Module', 'Routes', $module['className']);
            if (class_exists($className)) {
                $class = new $className();
                $router = $class->init($router);
            }
        }
        $di->set('router', $router);

        $eventsManager = new \Phalcon\Events\Manager();
        $dispatcher = new \Phalcon\Mvc\Dispatcher();


        $eventsManager->attach("dispatch:beforeDispatchLoop", function ($event, $dispatcher, $di) use ($di) {
            new LocalizationPlugin($dispatcher);
            new AclPlugin($di->get('acl'), $dispatcher);
        });


        $profiler = new \Phalcon\Db\Profiler();

        $eventsManager->attach('db', function ($event, $db) use ($profiler) {
            if ($event->getType() == 'beforeQuery') {
                $profiler->startProfile($db->getSQLStatement());
            }
            if ($event->getType() == 'afterQuery') {
                $profiler->stopProfile();
            }
        });

        $db->setEventsManager($eventsManager);
        $di->set('profiler', $profiler);


        $dispatcher->setEventsManager($eventsManager);
        $di->set('dispatcher', $dispatcher);

        $session = new \Phalcon\Session\Adapter\Files();
        $session->start();
        $di->set('session', $session);

        $acl = new \Application\Acl\DefaultAcl();
        $di->set('acl', $acl);

        $assets = new \Phalcon\Assets\Manager();
        $di->set('assets', $assets);

        $flash = new \Phalcon\Flash\Session(array(
            'error' => 'ui red inverted segment',
            'success' => 'ui green inverted segment',
            'notice' => 'ui blue inverted segment',
            'warning' => 'ui orange inverted segment',
        ));
        $di->set('flash', $flash);

        $di->set('helper', new \Application\Mvc\Helper());

        $di->set('registry', $registry);

        $assetsManager = new \Phalcon\Assets\Manager();
        $di->set('assets', $assetsManager);


        $application->setDI($di);

        $this->dispatch($di);

    }

    private function dispatch($di)
    {
        $router = $di['router'];

        $router->handle();

        $view = $di['view'];

        $dispatcher = $di['dispatcher'];

        $response = $di['response'];

        $dispatcher->setModuleName($router->getModuleName());
        $dispatcher->setControllerName($router->getControllerName());
        $dispatcher->setActionName($router->getActionName());
        $dispatcher->setParams($router->getParams());

        $tmpModuleNameArr = explode('-', $router->getModuleName());
        $moduleName = '';
        foreach ($tmpModuleNameArr as $part) {
            $moduleName .= ucfirst($part);
        }

        $ModuleClassName = $moduleName . '\Module';
        if (class_exists($ModuleClassName)) {
            $module = new $ModuleClassName;
            $module->registerAutoloaders();
            $module->registerServices($di);
        }

        $view->start();

        try {
            $dispatcher->dispatch();
        } catch (\Phalcon\Exception $e) {

            if ($e instanceof Phalcon\Mvc\Dispatcher\Exception) {
                $module = new \Index\Module();
                $module->registerAutoloaders();
                $module->registerServices($di);

                $dispatcher->setModuleName('index');
                $dispatcher->setControllerName('error');
                $dispatcher->setActionName('error404');
                $dispatcher->setParam('e', $e);

                $dispatcher->dispatch();

            } else {
                $view->setViewsDir(__DIR__ . '/modules/Index/views/');
                $view->setPartialsDir('');
                $view->e = $e;

                $response->setHeader(503, 'Service Unavailable');
                $view->partial('error/error503');
                $response->sendHeaders();

                echo $response->getContent();
                return;

            }

        }

        $view->render(
            $dispatcher->getControllerName(),
            $dispatcher->getActionName(),
            $dispatcher->getParams()
        );

        $view->finish();

        $response = $di['response'];

        if (isset($_GET['_ajax']) && $_GET['_ajax']) {
            $contents = $view->getContent();

            $return = new \stdClass();
            $return->html = $contents;
            $return->title = $di->get('helper')->title()->get();
            $return->success = true;

            if ($view->bodyClass) {
                $return->bodyClass = $view->bodyClass;
            }

            $headers = $response->getHeaders()->toArray();
            if (isset($headers[404]) || isset($headers[503])) {
                $return->success = false;
            }
            $response->setContentType('application/json', 'UTF-8');
            $response->setContent(json_encode($return));
        } else {
            $response->setContent($view->getContent());
        }

        $response->sendHeaders();

        echo $response->getContent();
    }

}
