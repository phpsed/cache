<?php

namespace Phpsed\Cache\EventListener;

use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\EntityManagerInterface;
use Phpsed\Cache\Annotation\Cache;
use Phpsed\Cache\DependencyInjection\PhpsedCacheExtension;
use Predis\Client;
use ReflectionClass;
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

class CacheListener implements EventSubscriberInterface
{
    public const CACHE_HEADER = 'PS-CACHE';
    public const DISABLE_CACHE = 'PS-CACHE-DISABLE';

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

    public function __construct(Reader $reader, ContainerInterface $container)
    {
        $this->enabled = $container->getParameter(\sprintf('%s.enabled', PhpsedCacheExtension::ALIAS));
        $this->reader = $reader;
        $this->client = new ChainAdapter($this->createAdapters($container, $container->getParameter(\sprintf('%s.providers', PhpsedCacheExtension::ALIAS))));
        $this->client->prune();
    }

    private function createAdapters(ContainerInterface $container, array $providers = []): array
    {
        $adapters = [];

        foreach ($providers as $provider) {
            if ($client = $container->get($provider)) {
                if ($client instanceof Client) {
                    $adapters[] = new RedisAdapter($client, '', 0);
                } elseif ($client instanceof EntityManagerInterface) {
                    $table = \sprintf('%s_items', PhpsedCacheExtension::EXTENSION);
                    $adapter = new PdoAdapter($client->getConnection(), '', 0, ['db_table' => $table]);
                    $schema = $client->getConnection()->getSchemaManager();
                    if (!$schema->tablesExist([$table])) {
                        $adapter->createTable();
                    }
                    $adapters[] = $adapter;
                }
            }
        }

        return $adapters;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', -100],
            KernelEvents::RESPONSE => 'onKernelResponse',
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelController(FilterControllerEvent $event): void
    {
        if (!$this->check($event)) {
            return;
        }

        if ($annotation = $this->getAnnotation($event)) {
            if ($this->client) {
                $annotation->setData($event->getRequest()->attributes->get('_route_params') + $event->getRequest()->request->all());
                /* @var $annotation Cache */
                $response = $this->getCache($annotation->getKey($event->getRequest()->get('_route')));
                if (\null !== $response) {
                    $event->setController(function() use ($response) {
                        return $response;
                    });
                }
            }
        }
    }

    private function check(KernelEvent $event): bool
    {
        $result = \true;

        if (!$this->enabled) {
            $result = \false;
        } elseif (!$event->isMasterRequest()) {
            $result = \false;
        } elseif (\method_exists($event, 'getResponse') && $event->getResponse() && !$event->getResponse()->isSuccessful()) {
            $result = \false;
        } elseif (empty($controller = $this->getController($event))) {
            $result = \false;
        } elseif (!\class_exists($controller[0])) {
            $result = \false;
        }

        return $result;
    }

    private function getController(KernelEvent $event): array
    {
        if (\is_array($controller = \explode('::', $event->getRequest()->get('_controller'))) && isset($controller[1])) {
            return $controller;
        }

        return [];
    }

    private function getAnnotation(KernelEvent $event): ?Cache
    {
        $controller = $this->getController($event);
        $controllerClass = new ReflectionClass($controller[0]);

        if ($method = $controllerClass->getMethod($controller[1])) {
            return $this->reader->getMethodAnnotation($method, Cache::class);
        }

        return \null;
    }

    private function getCache($key)
    {
        $cache = $this->client->getItem($key);
        if ($cache->isHit()) {
            return $cache->get();
        }

        return \null;
    }

    public function onKernelRequest(GetResponseEvent $event): void
    {
        if (!$this->check($event)) {
            return;
        }

        $disabled = $event->getRequest()->headers->get(self::CACHE_HEADER);
        if (\null !== $disabled) {
            $headers = \array_map('trim', explode(',', $disabled));
            if (\in_array(self::DISABLE_CACHE, $headers, \true) && $annotation = $this->getAnnotation($event)) {
                $annotation->setData($event->getRequest()->attributes->get('_route_params') + $event->getRequest()->request->all());
                $key = $annotation->getKey($event->getRequest()->get('_route'));
                $this->client->deleteItem($key);
            }
        }
    }

    public function onKernelResponse(FilterResponseEvent $event): void
    {
        if (!$this->check($event)) {
            return;
        }

        if ($annotation = $this->getAnnotation($event)) {
            $annotation->setData($event->getRequest()->attributes->get('_route_params') + $event->getRequest()->request->all());
            $key = $annotation->getKey($event->getRequest()->get('_route'));
            $cache = $this->getCache($key);
            if (\null === $cache) {
                $this->setCache($key, $event->getResponse(), $annotation->getExpires());
            }
        }
    }

    private function setCache(string $key, $value, int $expires): void
    {
        $cache = $this->client->getItem($key);
        $cache->set($value);
        $cache->expiresAfter($expires);
        $this->client->save($cache);
    }
}
