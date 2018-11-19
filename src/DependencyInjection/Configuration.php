<?php

namespace Phpsed\Cache\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    private $alias;

    public function __construct(string $alias)
    {
        $this->alias = $alias;
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root($this->getAlias());

        // @formatter:off
        $node
            ->canBeEnabled()
            ->children()
            ->arrayNode('providers')
                ->variablePrototype()->end()
            ->end()
        ->end();
        // @formatter:on

        return $treeBuilder;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }
}
