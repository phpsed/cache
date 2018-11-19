# phpsed/cache

[![Latest Stable Version](https://poser.pugx.org/phpsed/cache/v/stable?format=flat-square)](https://packagist.org/packages/phpsed/cache)
[![Latest Unstable Version](https://poser.pugx.org/phpsed/cache/v/unstable?format=flat-square)](https://packagist.org/packages/phpsed/cache)
[![License](https://poser.pugx.org/phpsed/cache/license?format=flat-square)](https://packagist.org/packages/phpsed/cache)
[![Total Downloads](https://poser.pugx.org/phpsed/cache/downloads?format=flat-square)](https://packagist.org/packages/phpsed/cache)  

Phpsed cache is annotation based controller response cache for Symfony framework.
It generates route specific key from GET and POST parameters and saves it in provided cache clients.  

Currently supported clients are Predis and DoctrineOrm.  
***
```yaml
phpsed_cache:
    enabled: true|FALSE
    providers:
        - snc_redis.session
        - doctrine.orm.default_entity_manager
```
**enabled** parameter by default is set to false, which means that @Cache annotation won't work without setting it to true.  
**providers** must be either Predis or Doctrine Orm clients.  
In the example above, two providers are provided:   
Predis client from snc/redis-bundle - snc_redis.session and Doctrine entity manager - doctrine.orm.default_entity_manager.  

If **enabled** parameters is set as true, at least one valid provider must be provided.

Phpsed\Cache uses Symfony\Cache [ChainAdapter][1]
```text
When an item is not found in the first adapter but is found in the next ones, this adapter ensures that the fetched item is saved to all the adapters where it was previously missing.
```
***
```php
use Phpsed\Cache\Annotation\Cache;
use Symfony\Component\Routing\Annotation\Route;

@Route("/{id}", name="default", defaults = {"id" = 0})
@Cache(
    expires = 3600,
    attributes = {"id"}
)
```
By default @Cache annotation doesn't need any parameters.  
Without **attributes** parameter, cache key for route is made from all of GET and POST parameters.  
Without **expires** parameter, cache is saved for unlimited ttl which means that it will be valid until deleted.  

If the same parameter exist in GET and POST, value of GET parameter will be used.   

@Cache annotation takes two optional parameters: expires and attributes.  
**expires** sets maximum ttl for given cache.   
In the example above cache will be saved for 3600 seconds and after 3600 seconds it will be invalidated.  
If the **attributes** parameter is set and given attribute(s) exist in GET or POST parameters, 
only given parameters will be used in cache key. 
Only valid attributes will be used in creation of key. If none of given attributes exist, key will be made without any parameters.
***
```yaml
PS-CACHE: PS-CACHE-DISABLE
```
In order to invalidate and delete the cache for endpoint, you must call this endpoint with specific header.
For this you need to set **PS-CACHE** header with **PS-CACHE-DISABLE** value.

[1]: https://symfony.com/doc/current/components/cache/adapters/chain_adapter.html
