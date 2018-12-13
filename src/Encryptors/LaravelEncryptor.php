<?php

namespace Epsoftware\Doctrine\ODM\Encrypt\Encryptors;

use Illuminate\Contracts\Encryption\Encrypter;

class LaravelEncryptor implements EncryptorInterface
{
    /**
     * @var Encrypter
     */
    private $encrypter;

    public function __construct(Encrypter $encrypter)
    {
        $this->encrypter = $encrypter;
    }

    /**
     * {@inheritdoc}
     */
    public function encrypt($data)
    {
        return $this->encrypter->encrypt($data);
    }

    /**
     * {@inheritdoc}
     */
    public function decrypt($data)
    {
        return $this->encrypter->decrypt($data);
    }
}
