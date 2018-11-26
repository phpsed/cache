<?php

declare(strict_types = 1);

namespace Phpsed\Cache\Annotation;

/**
 * @Annotation
 */
class Cache
{
    /**
     * @var string
     */
    private const ALGORITHM = 'sha512';

    /**
     * @var integer
     */
    public $expires;

    /**
     * @var null|string|array
     */
    public $data;

    /**
     * @var array
     */
    public $attributes = [];

    /**
     * @param string $prefix
     *
     * @return string
     */
    public function getKey(string $prefix = ''): string
    {
        $value = $this->getData();
        if (!\is_array($value)) {
            $key = $value ?: '';
        } else {
            if (!empty($this->getAttributes())) {
                $flipped = \array_flip($this->getAttributes());
                $value = \array_intersect_key($value, $flipped);
            }

            $key = \str_replace('=', '_', \http_build_query($value, '', '_'));
        }

        return \hash(self::ALGORITHM, $prefix.'_'.$key);
    }

    /**
     * @return array|string|null
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param $data
     */
    public function setData($data): void
    {
        $this->data = $data;
    }

    /**
     * @return array
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return int|null
     */
    public function getExpires(): ?int
    {
        return $this->expires;
    }
}
