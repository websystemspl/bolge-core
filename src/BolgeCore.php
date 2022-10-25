<?php
declare(strict_types = 1);

namespace Websystems\BolgeCore;

use stdClass;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Yaml\Yaml;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Config\FileLocator;
use Websystems\BolgeCore\Event\BootEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Websystems\BolgeCore\BolgeCoreInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Websystems\BolgeCore\Event\ActivateEvent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Websystems\BolgeCore\Event\HttpKernelRequestEvent;
use Websystems\BolgeCore\DoctrineExtensions\TablePrefix;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\HttpKernel\Controller\ContainerControllerResolver;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader as ServicesYamlLoader;

/**
 * Core class which control HTTP stream using symfony components
 *
 * To run app use instance of BolgeCore class and set:
 * - setTablePrefix (not required)
 * - setDirPath (required)
 * - setDbConnectionParams (required)
 * and then use boot method with @var Request
 *
 */
class BolgeCore extends Singleton implements BolgeCoreInterface
{
    private stdClass $settings;
    private RouteCollection $routes;
    private Request $request;
    private ?Response $response;
    private ContainerBuilder $containerBuilder;
    private string $tablePrefix = '';
    private string $dirPath = '';
    private ?string $databaseDriver = null;
    private ?string $databaseUser = null;
    private ?string $databasePassword = null;
    private ?string $databaseDbName = null;
    private ?string $databaseHost = null;

    /**
     * Boot App
     *
     * @return void
     */
    public function boot(Request $request)//: void
    {
		$this->loadEnvoironmentVariables();

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
    private function handle(Request $request): ?Response
    {
        $this->containerBuilder = new ContainerBuilder();
        $dispatcher = new EventDispatcher();

        /** Load core services */
        $loader = new ServicesYamlLoader($this->containerBuilder, new FileLocator(__DIR__ . '/../config'));
        $loader->load('services.yaml');

        /** Load services */
        $loader = new ServicesYamlLoader($this->containerBuilder, new FileLocator($this->dirPath . '/config'));
        $loader->load('services.yaml');

		if($this->databaseDriver !== null && $this->databaseUser !== null && $this->databasePassword !== null && $this->databaseDbName !== null && $this->databaseHost !== null) {
			$this->createDoctrineService();
		}

		foreach ($this->containerBuilder->getDefinitions() as $id => $definition) {
			$definition->setPublic(true);
		}

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

        if(isset($_ENV['ENV']) && $_ENV['ENV'] === 'dev') {
            if(isset($exception)) {
                dump($exception);
            }
        }

        return $response;
    }

	private function createDoctrineService()
	{

        /** Doctrine table prefix */
        $tablePrefix = new TablePrefix($this->tablePrefix);
        $evm = new \Doctrine\Common\EventManager;
        $evm->addEventListener(\Doctrine\ORM\Events::loadClassMetadata, $tablePrefix);

        /** Doctrine configuration */
        $this->containerBuilder
            ->register('doctrine.setup')
            ->setClass('Doctrine\ORM\Tools\Setup')
            ->addArgument([$this->dirPath . "/App/Entity"])
            ->addArgument(true)
            ->addArgument(null)
            ->addArgument(null)
            ->addArgument(false)
            ->setFactory(array(ORMSetup::class, 'createAnnotationMetadataConfiguration'))
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
	}

	/**
	 * Get app response
	 *
	 * @return Response|null
	 */
    public function getResponse(): ?Response
    {
        return $this->response;
    }

    /**
     * Setter for database table prefix
     *
     * @param string $prefix
     * @return void
     */
    public function setTablePrefix(string $prefix): void
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

	/**
	 * Setup Doctrine connection params
	 *
	 * @param string $databaseDriver
	 * @param string $databaseUser
	 * @param string $databasePassword
	 * @param string $databaseDbName
	 * @param string $databaseHost
	 * @return void
	 */
    public function setDbConnectionParams(string $databaseDriver, string $databaseUser, string $databasePassword, string $databaseDbName, string $databaseHost): void
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
    public function setDirPath(string $dirpath): void
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
	 * Load .env variables
	 *
	 * Use $_ENV to get variables
	 *
	 * @return void
	 */
	public function loadEnvoironmentVariables()
	{
		if(file_exists($this->dirPath . '/.env')) {
			$dotenv = new Dotenv();
			$dotenv->load($this->dirPath . '/.env');
		}
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
     * Get routes from yaml routes
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
		$conn = $entityManager->getConnection();
		$conn->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');
        $schemaTool = new SchemaTool($entityManager);
        $entities = $entityManager->getConfiguration()->getMetadataDriverImpl()->getAllClassNames();

		$metadata = [];
        foreach($entities as $entity) {
            $metadata[] = $entityManager->getClassMetadata($entity);
        }

        $sqlDiff = $schemaTool->getUpdateSchemaSql($metadata, true);
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
