<?php declare(strict_types = 1);

namespace Phpsed\Cache\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\HttpKernel\Kernel;

class Configuration implements ConfigurationInterface
{
    /**
     * @var string
     */
    private $alias;

    /**
     * @param string $alias
     */
    public function __construct(string $alias)
    {
        $this->alias = $alias;
    }

    /**
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {

        if (Kernel::VERSION_ID >= 40200) {
            $treeBuilder = new TreeBuilder($this->getAlias());
            $node = $treeBuilder->getRootNode();
        } else {
            $treeBuilder = new TreeBuilder();
            $node = $treeBuilder->root($this->getAlias());
        }

        // @formatter:off
        $node
            ->canBeEnabled()
            ->children()
            ->arrayNode('providers')
                ->variablePrototype()
                ->end()
            ->end()
        ->end();
        // @formatter:on

        return $treeBuilder;
    }

    /**
     * @return string
     */
    public function getAlias(): string
    {
        return $this->alias;
    }
}
