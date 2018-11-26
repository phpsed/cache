<?php

declare(strict_types = 1);

namespace Phpsed\Cache;

use Phpsed\Cache\DependencyInjection\PhpsedCacheExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class PhpsedCache extends Bundle
{
    /**
     * @return PhpsedCacheExtension|ExtensionInterface|null
     */
    public function getContainerExtension()
    {
        if (\null === $this->extension) {
            return new PhpsedCacheExtension();
        }

        return $this->extension;
    }

    /**
     * @return string|null
     */
    public function getParent(): ?string
    {
        return \null;
    }
}
