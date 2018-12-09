<?php declare(strict_types = 1);

namespace Phpsed\Cache\DependencyInjection;

use Exception;
use Phpsed\Cache\Annotation\Cache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class PhpsedCacheExtension extends Extension
{
    /**
     * @var string
     */
    public const ALIAS = 'phpsed.cache';

    /**
     * @var string
     */
    public const EXTENSION = 'phpsed_cache';

    /**
     * @return string
     */
    public function getAlias(): string
    {
        return self::EXTENSION;
    }

    /**
     * @param array $configs
     * @param ContainerBuilder $container
     *
     * @throws Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration($this->getAlias());
        $this->processConfiguration($configuration, $configs);
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');

        $enabled = $configs[0]['enabled'] ?? \false;
        $container->setParameter(\sprintf('%s.enabled', self::ALIAS), $enabled);
        if ($providers = $configs[0]['providers']) {
            $container->setParameter(\sprintf('%s.providers', self::ALIAS), $providers);
        } elseif (\true === $enabled) {
            throw new InvalidArgumentException(\sprintf('At least one provider must be configured to use %s annotation', Cache::class));
        }
    }
}
