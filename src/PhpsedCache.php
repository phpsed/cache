<?php

declare(strict_types=1);

namespace Phpsed\Cache;

use Phpsed\Cache\DependencyInjection\PhpsedCacheExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class PhpsedCache extends Bundle
{
    public function getContainerExtension()
    {
        if ($this->extension === null) {
            return new PhpsedCacheExtension();
        }

        return $this->extension;
    }

    public function getParent() : ?string
    {
        return null;
    }
}
