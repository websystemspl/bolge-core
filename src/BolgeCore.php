<?php
declare(strict_types = 1);

namespace Websystems\BolgeCore;

use stdClass;
use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Yaml\Yaml;
use Doctrine\ORM\Tools\SchemaTool;
use Websystems\BolgeCore\Event\BootEvent;
use Websystems\BolgeCore\Event\ActivateEvent;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\HttpKernel;
use Websystems\BolgeCore\DoctrineExtensions\TablePrefix;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouteCollection;
use Websystems\BolgeCore\Event\HttpKernelRequestEvent;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\HttpKernel\Controller\ContainerControllerResolver;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader as ServicesYamlLoader;

class BolgeCore extends Singleton
{ 
    private stdClass $settings;
    private RouteCollection $routes;    
    private Request $request;
    private ?Response $response;
    private ContainerBuilder $containerBuilder;
    private string $tablePrefix = '';
    private string $dirPath = '';
    private string $databaseDriver;
    private string $databaseUser;
    private string $databasePassword;
    private string $databaseDbName;
    private string $databaseHost;

    /**
     * Start App
     *
     * @return void
     */
    public function boot(Request $request): void
    {
        $this->request  = $request;
        $this->routes   = $this->getRoutes();
        $this->settings = $this->getSettings();
        $this->response = $this->handle($this->request);
        
        /**
         * @var BootEvent
         */
        $this->containerBuilder
            ->get('Symfony\Component\EventDispatcher\EventDispatcherInterface')
            ->dispatch(new BootEvent($this->request, $this->response), BootEvent::NAME)
        ;
    }

    /**
     * Http Kernel
     *
     * @param Request $request
     * @return ?Response
     */
    private function handle(Request $request)//: ?Response
    {        
        $this->containerBuilder = new ContainerBuilder();
        $dispatcher = new EventDispatcher();

        /** Load core services */
        $loader = new ServicesYamlLoader($this->containerBuilder, new FileLocator(__DIR__ . '/../config'));
        $loader->load('services.yaml');

        /** Load services */
        $loader = new ServicesYamlLoader($this->containerBuilder, new FileLocator($this->dirPath . '/config'));
        $loader->load('services.yaml');

        /** Doctrine table prefix */
        $tablePrefix = new TablePrefix($this->tablePrefix);
        $evm = new \Doctrine\Common\EventManager;
        $evm->addEventListener(\Doctrine\ORM\Events::loadClassMetadata, $tablePrefix);

        /** Doctrine configuration */
        $this->containerBuilder
            ->register('doctrine.setup')
            ->setClass('Doctrine\ORM\Tools\Setup')
            ->addArgument([DIR_PATH . "/App/Entity"])
            ->addArgument(true)
            ->addArgument(null)
            ->addArgument(null)
            ->addArgument(false)
            ->setFactory(array(Setup::class, 'createAnnotationMetadataConfiguration'))
        ;        

        /** Doctrine Entity Manager */
        $this->containerBuilder
            ->register('doctrine.orm.entity_manager')
            ->setClass('Doctrine\ORM\EntityManager')
            ->addArgument(array(
                'driver'   => $this->databaseDriver,
                'user'     => $this->databaseUser,
                'password' => $this->databasePassword,
                'dbname'   => $this->databaseDbName,
                'host'     => $this->databaseHost,
            ))
            ->addArgument(new Reference('doctrine.setup'))
            ->addArgument($evm)
            ->setFactory(array(EntityManager::class, 'create'))
        ;

        /** Settings from yaml */
        $this->containerBuilder->setParameter('core.settings', $this->settings);
        $this->containerBuilder->setParameter('core.settings.array', $this->getSettings(true));
        $this->containerBuilder->setParameter('routes', $this->getRoutes());
        $this->containerBuilder->compile();

        $dispatcher->addSubscriber(
            new RouterListener(
                new UrlMatcher(
                    $this->routes,
                    new RequestContext()
                ),
                new RequestStack()
            )
        );

        $kernel = new HttpKernel(
            $dispatcher,
            new ContainerControllerResolver($this->containerBuilder),
            new RequestStack(),
            new ArgumentResolver()
        );

        /**
         * @var HttpKernelRequestEvent
         */        
        $this->containerBuilder
            ->get('Symfony\Component\EventDispatcher\EventDispatcherInterface')
            ->dispatch(new HttpKernelRequestEvent($request), HttpKernelRequestEvent::NAME)
        ;

        try {
            $response = $kernel->handle($request);
        } catch (ResourceNotFoundException $exception) {
            $response = null;
        } catch (\Exception $exception) {
            $response = null;
        } catch (NotFoundHttpException  $exception) {
            $response = null;
        }

        if($this->settings->env === 'dev') {
            if(isset($exception)) {
                dump($exception);
            }
        }
        
        return $response;
    }

    public function generateTemplate()
    {
        echo $this->response->getContent();
    }

    /**
     * Setter for database table prefix
     *
     * @param string $prefix
     * @return void
     */
    public function setTablePrefix(string $prefix)
    {
        $this->tablePrefix = $prefix;
    }

    /**
     * Access to database table prefix
     *
     * @return string
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    public function setDbConnectionParams(string $databaseDriver, string $databaseUser, string $databasePassword, string $databaseDbName, string $databaseHost)
    {
        $this->databaseDriver = $databaseDriver;
        $this->databaseUser = $databaseUser;
        $this->databasePassword = $databasePassword;
        $this->databaseDbName = $databaseDbName;
        $this->databaseHost = $databaseHost;
    }

    /**
     * Setter for dir path
     *
     * @param string $prefix
     * @return void
     */
    public function setDirPath(string $dirpath)
    {
        $this->dirPath = $dirpath;
    }

    /**
     * Access to dir path
     *
     * @return string
     */
    public function getDirPath(): string
    {
        return $this->dirPath;
    }

    /**
     * Get data from yaml settings
     * 
     * @param bool $as_array - return as array (default @var stdClass)
     */   
    public function getSettings(bool $as_array = false)
    {
        ($as_array === false) ? $type = Yaml::PARSE_OBJECT_FOR_MAP : $type = 0;
        return Yaml::parseFile($this->dirPath . '/config/settings.yaml', $type);
    }

    /**
     * Get routes from config
     *
     * @return RouteCollection
     */
    public function getRoutes(): RouteCollection
    {
        $locator = new FileLocator($this->dirPath . '/config');
        $loader = new YamlFileLoader($locator);
        return $loader->load('routes.yaml');        
    }

    /**
     * Get route path
     *
     * @param string $route
     * @param array $values
     * @return string
     */
    public function getUrlFromRoute(string $route, array $values = []): string
    {
        $generator = new UrlGenerator($this->getRoutes(), new RequestContext());
        return $generator->generate($route, $values);
    }

    /**
     * Generate url to admin page by route
     *
     * @param string $route
     * @param array $values
     * @return ?string
     */
    public function getAdminUrlFromRoute(string $route, array $values = []): ?string
    {
        $generator = new UrlGenerator($this->getRoutes(), new RequestContext());
        $generated = $generator->generate($route, $values);
        $generated = explode("/", $generated);
        $new = '/wp-admin/admin.php?';
        foreach($generated as $key => $par) {
            if($key === 2) {
                $new .= 'page='.$par;
            }
            if($key === 3) {
                $new .= '&action='.$par;
            }
        }
        
        $defaults = @$this->getRoutes()->all()[$route] ?: null;

        foreach($defaults->getDefaults() as $key => $param) {
            if(isset($values[$key])) {
                if($values[$key] === "") {
                    return null;
                }
                $new .= '&'.$key.'='.$values[$key];
            }
        }
        
        return $new;
    }

    /**
     * Run on plugin activate
     *
     * @return void
     */
    public function pluginActivate(): void
    {
        /**
         * Install or update schema by doctrine
         */
        $entityManager = $this->containerBuilder->get('doctrine.orm.entity_manager');
        $schemaTool = new SchemaTool($entityManager);
        $entities = $entityManager->getConfiguration()->getMetadataDriverImpl()->getAllClassNames();
        $metadata = [];
        foreach($entities as $entity) {
            $metadata[] = $entityManager->getClassMetadata($entity);
        }
        $sqlDiff = $schemaTool->getUpdateSchemaSql($metadata, true);
        $conn = $entityManager->getConnection();
        foreach($sqlDiff as $sql) {
            $stmt = $conn->prepare($sql);
            $stmt->executeQuery();
        } 

        /**
         * @var ActivateEvent
         */
        $this->containerBuilder
            ->get('Symfony\Component\EventDispatcher\EventDispatcherInterface')
            ->dispatch(new ActivateEvent(null), ActivateEvent::NAME)
        ;        
    }
}
