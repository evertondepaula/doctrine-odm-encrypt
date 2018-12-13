<?php

namespace Epsoftware\Doctrine\OMD\Encrypt\Subscribers;

use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\EventSubscriber;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Doctrine\ODM\MongoDB\Event\OnFlushEventArgs;
use Doctrine\ODM\MongoDB\Event\PostFlushEventArgs;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Events;
use Epsoftware\Doctrine\OMD\Encrypt\Configuration\Encrypted;
use Epsoftware\Doctrine\OMD\Encrypt\Encryptors\EncryptorInterface;

/**
 * Doctrine event subscriber which encrypt/decrypt entities
 */
class DoctrineEncryptSubscriber implements EventSubscriber
{
    /**
     * Encryptor interface namespace
     */
    const ENCRYPTOR_INTERFACE_NS = EncryptorInterface::class;

    /**
     * Encrypted annotation full name
     */
    const ENCRYPTED_ANN_NAME = Encrypted::class;

    /**
     * Encryptor
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * Annotation reader
     * @var \Doctrine\Common\Annotations\Reader
     */
    private $annReader;

    /**
     * Registr to avoid multi decode operations for one entity
     * @var array
     */
    private $decodedRegistry = array();

    /**
     * Caches information on an entity's encrypted fields in an array keyed on
     * the entity's class name. The value will be a list of Reflected fields that are encrypted.
     *
     * @var array
     */
    private $encryptedFieldCache = array();

    /**
     * Before flushing the objects out to the database, we modify their password value to the
     * encrypted value. Since we want the password to remain decrypted on the entity after a flush,
     * we have to write the decrypted value back to the entity.
     * @var array
     */
    private $postFlushDecryptQueue = array();

    public function __construct(Reader $annReader, EncryptorInterface $encryptor)
    {
        $this->annReader = $annReader;
        $this->encryptor = $encryptor;
    }

    /**
     * Encrypt the password before it is written to the database.
     *
     * Notice that we do not recalculate changes otherwise the password will be written
     * every time (Because it is going to differ from the un-encrypted value)
     */
    public function onFlush(OnFlushEventArgs $args)
    {
        $dm = $args->getDocumentManager();
        $unitOfWork = $dm->getUnitOfWork();

        $this->postFlushDecryptQueue = array();

        foreach ($unitOfWork->getScheduledDocumentInsertions() as $document) {
            $this->documentOnFlush($document, $dm);
            $unitOfWork->recomputeSingleDocumentChangeSet($dm->getClassMetadata(get_class($document)), $document);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $document) {
            $this->documentOnFlush($document, $dm);
            $unitOfWork->recomputeSingleDocumentChangeSet($dm->getClassMetadata(get_class($document)), $document);
        }
    }


    /**
     * Processes the entity for an onFlush event.
     *
     * @param object $entity
     */
    private function documentOnFlush($document, DocumentManager $dm)
    {
        $objId = spl_object_hash($document);

        $fields = array();

        foreach ($this->getEncryptedFields($document, $dm) as $field) {
            $fields[$field->getName()] = array(
                'field' => $field,
                'value' => $field->getValue($document),
            );
        }

        $this->postFlushDecryptQueue[$objId] = array(
            'document' => $document,
            'fields'   => $fields,
        );

        $this->processFields($document, $dm);
    }

    /**
     * After we have persisted the entities, we want to have the
     * decrypted information available once more.
     */
    public function postFlush(PostFlushEventArgs $args)
    {
        $unitOfWork = $args->getDocumentManager()->getUnitOfWork();

        foreach ($this->postFlushDecryptQueue as $pair) {
            $fieldPairs = $pair['fields'];
            $document = $pair['document'];
            $oid = spl_object_hash($document);

            foreach ($fieldPairs as $fieldPair) {
                /** @var \ReflectionProperty $field */
                $field = $fieldPair['field'];

                $field->setValue($document, $fieldPair['value']);
                $unitOfWork->setOriginalDocumentProperty($oid, $field->getName(), $fieldPair['value']);
            }

            $this->addToDecodedRegistry($document);
        }

        $this->postFlushDecryptQueue = array();
    }

    /**
     * Listen a postLoad lifecycle event. Checking and decrypt entities
     * which have @Encrypted annotations
     */
    public function postLoad(LifecycleEventArgs $args)
    {
        $document = $args->getDocument();
        $dm = $args->getDocumentManager();

        if (! $this->hasInDecodedRegistry($document)) {
            if ($this->processFields($document, $dm, false)) {
                $this->addToDecodedRegistry($document);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::postLoad,
            Events::onFlush,
            Events::postFlush,
        ];
    }

    public static function capitalize(string $word): string
    {
        if (is_array($word)) {
            $word = $word[0];
        }

        return str_replace(' ', '', ucwords(str_replace(array('-', '_'), ' ', $word)));
    }

    /**
     * Process (encrypt/decrypt) entities fields
     *
     * @param object $document Some doctrine document
     */
    private function processFields($document, DocumentManager $dm, $isEncryptOperation = true): bool
    {
        $properties = $this->getEncryptedFields($document, $dm);

        $unitOfWork = $dm->getUnitOfWork();
        $oid = spl_object_hash($document);

        foreach ($properties as $refProperty) {
            $value = $refProperty->getValue($document);
            $value = $value === null ? '' : $value;

            $value = $isEncryptOperation ?
                $this->encryptor->encrypt($value) :
                $this->encryptor->decrypt($value);

            $refProperty->setValue($document, $value);

            if (! $isEncryptOperation) {
                //we don't want the object to be dirty immediately after reading
                $unitOfWork->setOriginalDocumentProperty($oid, $refProperty->getName(), $value);
            }
        }

        return ! empty($properties);
    }

    /**
     * Check if we have document in decoded registry
     *
     * @param object $document Some doctrine document
     */
    private function hasInDecodedRegistry($document): bool
    {
        return isset($this->decodedRegistry[spl_object_hash($document)]);
    }

    /**
     * Adds document to decoded registry
     *
     * @param object $document Some doctrine document
     */
    private function addToDecodedRegistry($document)
    {
        $this->decodedRegistry[spl_object_hash($document)] = true;
    }


    /**
     * @param bool $document
     * @return \ReflectionProperty[]
     */
    private function getEncryptedFields($document, DocumentManager $dm)
    {
        $className = get_class($document);

        if (isset($this->encryptedFieldCache[$className])) {
            return $this->encryptedFieldCache[$className];
        }

        $meta = $dm->getClassMetadata($className);

        $encryptedFields = array();

        foreach ($meta->getReflectionProperties() as $refProperty) {
            /** @var \ReflectionProperty $refProperty */

            if ($this->annReader->getPropertyAnnotation($refProperty, self::ENCRYPTED_ANN_NAME)) {
                $refProperty->setAccessible(true);
                $encryptedFields[] = $refProperty;
            }
        }

        $this->encryptedFieldCache[$className] = $encryptedFields;

        return $encryptedFields;
    }
}
