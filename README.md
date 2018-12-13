# [DoctrineEncrypt](https://github.com/evertondepaula/doctrine-odm-encrypt)

Package encrypts and decrypts Doctrine fields through life cycle events. This version of the Doctrine Encrypt package
distinguishes itself with the following features:

- Superior Annotation parsing & caching using Doctrine's built in libraries for superior performance
- Totally transparent field encryption: the value will only be encrypted in the database, never in the value
- Unit testing

## Integrations

The package supports the following integrations:

- Laravel

## Upgrading

If you're upgrading from a previous version you can find some help with that in [the upgrading guide](UPGRADING.md).

## Installation

```bash
composer require 51systems/doctrine-encrypt
```

## Configuration

### Laravel

Add the subscriber in the `boot` method of a service provider.

```php
<?php

$encrypter = $this->app->make(\Illuminate\Contracts\Encryption\Encrypter::class);

$subscriber = new DoctrineEncryptSubscriber(
    new \Doctrine\Common\Annotations\AnnotationReader,
    new \DoctrineEncrypt\Encryptors\LaravelEncryptor($encrypter)
);

$eventManager = $em->getEventManager();
$eventManager->addEventSubscriber($subscriber);
```

## Usage

```php
<?php

namespace Your\Namespace;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

use DoctrineEncrypt\Configuration\Encrypted;

/**
 * @ODM\Document
 */
class Document
{
    /**
     * @ODM\Id
    */
    private $id;

    /**
     * @ODM\Field(type="string")
     * @Encrypted
     * @var string
     */
    private $secretData;
}
```

## License

This bundle is under [the MIT license](LICENSE.md).

## Versions

I'm using Semantic Versioning like described [here](http://semver.org).
