<?php declare(strict_types = 1);

namespace Phpsed\Cache\EventListener;

use Doctrine\Common\Annotations\Reader;
use Doctrine\DBAL\DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Phpsed\Cache\Annotation\Cache;
use Phpsed\Cache\DependencyInjection\PhpsedCacheExtension;
use Predis\Client;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use function array_map;
use function class_exists;
use function explode;
use function in_array;
use function is_array;
use function method_exists;
use function sprintf;
use const false;
use const null;
use const true;

class CacheListener implements EventSubscriberInterface
{
    /**
     * @var string
     */
    public const CACHE_HEADER = 'PS-CACHE';

    /**
     * @var string
     */
    public const DISABLE_CACHE = 'PS-CACHE-DISABLE';

    /**
     * @var string
     */
    private const ROUTE = '_route';

    /**
     * @var ChainAdapter
     */
    protected $client;

    /**
     * @var Reader
     */
    protected $reader;

    /**
     * @var bool
     */
    protected $enabled;

    /**
     * @param Reader $reader
     * @param ContainerInterface $container
     *
     * @throws DBALException
     */
    public function __construct(Reader $reader, ContainerInterface $container)
    {
        $this->enabled = $container->getParameter(sprintf('%s.enabled', PhpsedCacheExtension::ALIAS));
        if ($this->enabled) {
            $this->reader = $reader;
            $this->client = new ChainAdapter($this->createAdapters($container, $container->getParameter(sprintf('%s.providers', PhpsedCacheExtension::ALIAS))));
            $this->client->prune();
        }
    }

    /**
     * @param ContainerInterface $container
     * @param array $providers
     *
     * @return array
     * @throws DBALException
     */
    private function createAdapters(ContainerInterface $container, array $providers = []): array
    {
        $adapters = [];

        foreach ($providers as $provider) {
            if ($instance = $container->get($provider)) {
                if ($instance instanceof Client) {
                    $adapters[] = new RedisAdapter($instance, '', 0);
                } elseif ($instance instanceof EntityManagerInterface) {
                    $table = sprintf('%s_items', PhpsedCacheExtension::EXTENSION);
                    $adapter = new PdoAdapter($instance->getConnection(), '', 0, ['db_table' => $table]);
                    $schema = $instance->getConnection()
                        ->getSchemaManager();
                    if (!$schema->tablesExist([$table])) {
                        $adapter->createTable();
                    }
                    $adapters[] = $adapter;
                }
            }
        }

        return $adapters;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => [
                'onKernelController',
                -100,
            ],
            KernelEvents::RESPONSE => 'onKernelResponse',
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    /**
     * @param FilterControllerEvent $event
     *
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function onKernelController(FilterControllerEvent $event): void
    {
        if (!$this->check($event)) {
            return;
        }

        if ($annotation = $this->getAnnotation($event)) {
            $annotation->setData(self::getAttributes($event));
            /* @var $annotation Cache */
            $response = $this->getCache($annotation->getKey($event->getRequest()
                ->get(self::ROUTE)));
            if (null !== $response) {
                $event->setController(function () use ($response) {
                    return $response;
                });
            }
        }
    }

    /**
     * @param KernelEvent $event
     *
     * @return bool
     */
    private function check(KernelEvent $event): bool
    {
        if (!$this->enabled || !$this->client || !$event->isMasterRequest()) {
            return false;
        }

        if (method_exists($event, 'getResponse') && $event->getResponse() && !$event->getResponse()
                ->isSuccessful()) {
            return false;
        }

        if (empty($controller = $this->getController($event)) || !class_exists($controller[0])) {
            return false;
        }

        return true;
    }

    /**
     * @param KernelEvent $event
     *
     * @return array
     */
    private function getController(KernelEvent $event): array
    {
        if (is_array($controller = explode('::', $event->getRequest()
                ->get('_controller'))) && isset($controller[1])) {
            return $controller;
        }

        return [];
    }

    /**
     * @param KernelEvent $event
     *
     * @return mixed
     * @throws ReflectionException
     */
    private function getAnnotation(KernelEvent $event)
    {
        $controller = $this->getController($event);
        $controllerClass = new ReflectionClass($controller[0]);

        if ($method = $controllerClass->getMethod($controller[1])) {
            return $this->reader->getMethodAnnotation($method, Cache::class);
        }

        return null;
    }

    /**
     * @param KernelEvent $event
     *
     * @return array|mixed
     */
    private static function getAttributes(KernelEvent $event)
    {
        return $event->getRequest()->attributes->get('_route_params') + $event->getRequest()->request->all();
    }

    /**
     * @param $key
     *
     * @return mixed|null
     * @throws InvalidArgumentException
     */
    private function getCache($key)
    {
        $cache = $this->client->getItem($key);
        if ($cache->isHit()) {
            return $cache->get();
        }

        return null;
    }

    /**
     * @param GetResponseEvent $event
     *
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function onKernelRequest(GetResponseEvent $event): void
    {
        if (!$this->check($event)) {
            return;
        }

        $disabled = $event->getRequest()->headers->get(self::CACHE_HEADER);
        if (null !== $disabled) {
            $headers = array_map('trim', explode(',', $disabled));
            if (in_array(self::DISABLE_CACHE, $headers, true) && $annotation = $this->getAnnotation($event)) {
                $annotation->setData(self::getAttributes($event));
                $key = $annotation->getKey($event->getRequest()
                    ->get(self::ROUTE));
                $this->client->deleteItem($key);
            }
        }
    }

    /**
     * @param FilterResponseEvent $event
     *
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function onKernelResponse(FilterResponseEvent $event): void
    {
        if (!$this->check($event)) {
            return;
        }

        if ($annotation = $this->getAnnotation($event)) {
            $annotation->setData(self::getAttributes($event));
            $key = $annotation->getKey($event->getRequest()
                ->get(self::ROUTE));
            $cache = $this->getCache($key);
            if (null === $cache) {
                $this->setCache($key, $event->getResponse(), $annotation->getExpires());
            }
        }
    }

    /**
     * @param string $key
     * @param $value
     * @param int|null $expires
     *
     * @throws InvalidArgumentException
     */
    private function setCache(string $key, $value, ?int $expires): void
    {
        $cache = $this->client->getItem($key);
        $cache->set($value);
        $cache->expiresAfter($expires);
        $this->client->save($cache);
    }
}
