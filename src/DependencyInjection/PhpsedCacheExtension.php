<?php declare(strict_types = 1);

namespace Phpsed\Cache\DependencyInjection;

use Exception;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Vairogs\Utils\Utils\Iter;

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

        foreach (Iter::makeOneDimension($configs[0], self::ALIAS) as $key => $value) {
            $container->setParameter($key, $value);
        }
    }
}
