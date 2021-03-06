<?php declare(strict_types = 1);

namespace Phpsed\Cache\EventListener;

use Doctrine\Common\Annotations\Reader;
use Doctrine\DBAL\DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Phpsed\Cache\Annotation\Cache;
use Phpsed\Cache\Arrayable;
use Phpsed\Cache\DependencyInjection\PhpsedCacheExtension;
use Phpsed\Cache\PhpsedCache;
use Predis\Client;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
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
     * @var TokenStorageInterface
     */
    protected $storage;

    /**
     * @var array
     */
    protected $providers;

    /**
     * CacheListener constructor.
     *
     * @param Reader $reader
     * @param ContainerInterface $container
     * @param TokenStorageInterface $storage
     * @param array $providers
     *
     * @throws DBALException
     */
    public function __construct(
        Reader $reader, ContainerInterface $container, TokenStorageInterface $storage, ...$providers
    ) {
        $this->enabled = $container->getParameter(sprintf('%s.enabled', PhpsedCacheExtension::ALIAS));
        if ($this->enabled) {
            $this->providers = $providers;
            $this->reader = $reader;
            $this->client = new ChainAdapter($this->createAdapters());
            $this->client->prune();
            $this->storage = $storage;
        }
    }

    /**
     * @return array
     * @throws DBALException
     */
    private function createAdapters(): array
    {
        $adapters = [];

        foreach ($this->providers as $provider) {
            if ($provider instanceof Client) {
                $adapters[] = new RedisAdapter($provider, '', 0);
            } elseif ($provider instanceof EntityManagerInterface) {
                $table = sprintf('%s_items', PhpsedCacheExtension::EXTENSION);
                $adapter = new PdoAdapter($provider->getConnection(), '', 0, ['db_table' => $table]);
                $schema = $provider->getConnection()->getSchemaManager();
                if (!$schema->tablesExist([$table])) {
                    $adapter->createTable();
                }
                $adapters[] = $adapter;
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
     * @param ControllerEvent $event
     *
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function onKernelController(ControllerEvent $event): void
    {
        if (!$this->check($event)) {
            return;
        }

        if ($annotation = $this->getAnnotation($event)) {
            $annotation->setData($this->getAttributes($event));
            /* @var $annotation Cache */
            $response = $this->getCache($annotation->getKey($event->getRequest()->get(self::ROUTE)));
            if (null !== $response) {
                $event->setController(function () use (
                    $response
                ) {
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

        if (method_exists($event, 'getResponse') && $event->getResponse() && !$event->getResponse()->isSuccessful()) {
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
        if (is_array($controller = explode('::', $event->getRequest()->get('_controller'))) && isset($controller[1])) {
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
    private function getAnnotation(KernelEvent $event): ?Cache
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
     * @throws ReflectionException
     */
    private function getAttributes(KernelEvent $event)
    {
        $input = [];
        if ($annotation = $this->getAnnotation($event)) {
            $req = $event->getRequest();
            switch ($annotation->getStrategy()) {
                case Cache::GET:
                    $input = $req->attributes->get('_route_params') + $req->query->all();
                    break;
                case Cache::POST:
                    $input = $req->request->all();
                    break;
                case Cache::USER:
                    if ($this->storage->getToken() && $this->storage->getToken()->getUser() instanceof Arrayable) {
                        $input = $this->storage->getToken()->getUser()->toArray();
                    }
                    break;
                case Cache::MIXED:
                default:
                    $input = $req->attributes->get('_route_params') + $req->query->all() + $req->request->all();
                    if ($this->storage->getToken() && $this->storage->getToken()->getUser() instanceof Arrayable) {
                        $input += $this->storage->getToken()->getUser()->toArray();
                    }
                    break;
            }

            return $input;
        }
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
     * @param RequestEvent $event
     *
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->check($event)) {
            return;
        }

        $disabled = $event->getRequest()->headers->get(PhpsedCache::CACHE_HEADER);
        if (null !== $disabled) {
            $headers = array_map('trim', explode(',', $disabled));
            if (in_array(PhpsedCache::DISABLE_CACHE, $headers, true) && $annotation = $this->getAnnotation($event)) {
                $annotation->setData($this->getAttributes($event));
                $key = $annotation->getKey($event->getRequest()->get(self::ROUTE));
                $this->client->deleteItem($key);
            }
        }
    }

    /**
     * @param ResponseEvent $event
     *
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->check($event)) {
            return;
        }

        if ($annotation = $this->getAnnotation($event)) {
            $annotation->setData($this->getAttributes($event));
            $key = $annotation->getKey($event->getRequest()->get(self::ROUTE));
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
    private function setCache(
        string $key, $value, ?int $expires
    ): void {
        $cache = $this->client->getItem($key);
        $cache->set($value);
        $cache->expiresAfter($expires);
        $this->client->save($cache);
    }
}
