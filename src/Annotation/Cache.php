<?php

declare(strict_types=1);

namespace Phpsed\Cache\Annotation;

use function array_flip;
use function array_intersect_key;
use function hash;
use function http_build_query;
use function is_array;
use function str_replace;

/**
 * @Annotation
 */
class Cache
{
    private const ALGORITHM = 'sha512';

    /** @var int */
    public $expires;

    public $data;
    public $attributes = [];

    public function getKey(string $prefix = '') : string
    {
        $value = $this->getData();
        if (! is_array($value)) {
            $key = $value ?: '';
        } else {
            if (! empty($this->getAttributes())) {
                $flipped = array_flip($this->getAttributes());
                $value   = array_intersect_key($value, $flipped);
            }

            $key = str_replace('=', '_', http_build_query($value, null, '_'));
        }

        return hash(self::ALGORITHM, $prefix . '_' . $key);
    }

    public function getData()
    {
        return $this->data;
    }

    public function setData($data) : void
    {
        $this->data = $data;
    }

    public function getAttributes() : array
    {
        return $this->attributes;
    }

    public function getExpires() : ?int
    {
        return $this->expires;
    }
}
