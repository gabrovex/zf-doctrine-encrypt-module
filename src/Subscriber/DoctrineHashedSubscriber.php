<?php

namespace ZfDoctrineEncryptModule\Subscriber;

use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use ZfDoctrineEncryptModule\Annotation\Hashed;
use ZfDoctrineEncryptModule\Interfaces\HashInterface;
use ZfDoctrineEncryptModule\Interfaces\SaltInterface;
use ZfDoctrineEncryptModule\Options\EncryptModuleOptions;

class DoctrineHashedSubscriber implements EventSubscriber
{
    /**
     * Encryptor interface namespace
     */
    const HASHOR_INTERFACE_NS = HashInterface::class;

    /**
     * Encrypted annotation full name
     */
    const HASHED_ANNOTATION_NAME = Hashed::class;

    /**
     * @var HashInterface
     */
    private $hashor;

    /**
     * Annotation reader
     * @var \Doctrine\Common\Annotations\Reader
     */
    private $reader;

    /**
     * Caches information on an entity's hashed fields in an array keyed on
     * the entity's class name. The value will be a list of Reflected fields that are hashed.
     *
     * @var array
     */
    private $hashedFieldCache = [];

    /**
     * DoctrineHashedSubscriber constructor.
     * @param Reader $reader
     * @param HashInterface $hashor
     */
    public function __construct(Reader $reader, HashInterface $hashor)
    {
        $this->setReader($reader);
        $this->setHashor($hashor);
    }

    /**
     * Hash string before writing to the database.
     *
     * Notice that we do not recalculate changes otherwise the password will be written
     * every time (Because it is going to differ from the un-encrypted value)
     *
     * @param OnFlushEventArgs $args
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function onFlush(OnFlushEventArgs $args)
    {
        $em = $args->getEntityManager();
        $unitOfWork = $em->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $this->entityOnFlush($entity, $em);
            $unitOfWork->recomputeSingleEntityChangeSet($em->getClassMetadata(get_class($entity)), $entity);
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            $this->entityOnFlush($entity, $em);
            $unitOfWork->recomputeSingleEntityChangeSet($em->getClassMetadata(get_class($entity)), $entity);
        }
    }


    /**
     * Processes the entity for an onFlush event.
     *
     * @param \object $entity
     * @param EntityManager $em
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function entityOnFlush(object $entity, EntityManager $em)
    {
        $fields = [];

        foreach ($this->getHashableFields($entity, $em) as $field) {
            /** @var \ReflectionProperty $reflectionProperty */
            $reflectionProperty = $field['reflection'];

            $fields[$reflectionProperty->getName()] = [
                'field' => $reflectionProperty,
                'value' => $reflectionProperty->getValue($entity),
                'options' => $field['options'],
            ];
        }

        $this->processFields($entity, $em);
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents(): array
    {
        return [
            Events::onFlush,
        ];
    }

    /**
     * Process hashable entities fields
     *
     * @param $entity
     * @param EntityManager $em
     * @return bool
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \Exception
     */
    private function processFields($entity, EntityManager $em): bool
    {
        $properties = $this->getHashableFields($entity, $em);

        foreach ($properties as $property) {
            /** @var \ReflectionProperty $refProperty */
            $refProperty = $property['reflection'];
            /** @var Hashed $annotationOptions */
            $annotationOptions = $property['options'];
            /** @var boolean $nullable */
            $nullable = $property['nullable'];

            $value = $refProperty->getValue($entity);
            // If the value is 'null' && is nullable, don't hash it
            if (is_null($value) && $nullable) {

                continue;
            }

            $value = $this->addSalt($value, $annotationOptions, $entity);

            $refProperty->setValue($entity, $this->getHashor()->hash($value));
        }

        return ! empty($properties);
    }

    /**
     * Check if option 'salt' is set
     * If so, expect a related Entity on $entity for get{$options->getSalt()}()
     * Use related Entity to get Salt. getSalt() should exist due to implementation of SaltInterface
     *
     * @param string $value
     * @param Hashed $options
     * @param \object $entity
     * @return string
     */
    private function addSalt(string $value, Hashed $options, object $entity)
    {
        if (
            !is_null($options->getSalt())
            && method_exists($entity, 'get' . ucfirst($options->getSalt()))
            && $entity->{'get' . ucfirst($options->getSalt())}() instanceof SaltInterface
            && ($salt = $entity->{'get' . ucfirst($options->getSalt())}()->getSalt())
        ) {

            return $salt . $value;
        }

        return $value;
    }

    /**
     * @param \object $entity
     * @param EntityManager $em
     * @return array|mixed
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function getHashableFields(object $entity, EntityManager $em)
    {
        $className = get_class($entity);

        if (isset($this->hashedFieldCache[$className])) {
            return $this->hashedFieldCache[$className];
        }

        $meta = $em->getClassMetadata($className);

        $hashableFields = [];

        foreach ($meta->getReflectionProperties() as $refProperty) {
            /** @var \ReflectionProperty $refProperty */

            // Gets Encrypted object from property Annotation. Includes options and their values.
            $annotationOptions = $this->reader->getPropertyAnnotation($refProperty, $this::HASHED_ANNOTATION_NAME) ?: [];

            if (!empty($annotationOptions)) {
                $refProperty->setAccessible(true);
                $hashableFields[] = [
                    'reflection' => $refProperty,
                    'options' => $annotationOptions,
                    'nullable' => $meta->getFieldMapping($refProperty->getName())['nullable'],
                ];
            }
        }

        $this->hashedFieldCache[$className] = $hashableFields;

        return $hashableFields;
    }

    /**
     * @return HashInterface
     */
    public function getHashor(): HashInterface
    {
        return $this->hashor;
    }

    /**
     * @param HashInterface $hashor
     * @return DoctrineHashedSubscriber
     */
    public function setHashor(HashInterface $hashor): DoctrineHashedSubscriber
    {
        $this->hashor = $hashor;
        return $this;
    }

    /**
     * @return Reader
     */
    public function getReader(): Reader
    {
        return $this->reader;
    }

    /**
     * @param Reader $reader
     * @return DoctrineHashedSubscriber
     */
    public function setReader(Reader $reader): DoctrineHashedSubscriber
    {
        $this->reader = $reader;
        return $this;
    }

    /**
     * @return array
     */
    public function getHashedFieldCache(): array
    {
        return $this->hashedFieldCache;
    }

    /**
     * @param array $hashedFieldCache
     * @return DoctrineHashedSubscriber
     */
    public function setHashedFieldCache(array $hashedFieldCache): DoctrineHashedSubscriber
    {
        $this->hashedFieldCache = $hashedFieldCache;
        return $this;
    }

}