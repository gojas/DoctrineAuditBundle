<?php

namespace DH\DoctrineAuditBundle\Helper;

use DH\DoctrineAuditBundle\Configuration;
use DH\DoctrineAuditBundle\User\UserInterface;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

class AuditHelper
{
    /**
     * @var \DH\DoctrineAuditBundle\Configuration
     */
    private $configuration;

    /**
     * @param Configuration $configuration
     */
    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @return \DH\DoctrineAuditBundle\Configuration
     */
    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    /**
     * Returns the primary key value of an entity.
     *
     * @param EntityManagerInterface $em
     * @param object                 $entity
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return mixed
     */
    public function id(EntityManagerInterface $em, $entity)
    {
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $pk = $meta->getSingleIdentifierFieldName();

        if (isset($meta->fieldMappings[$pk])) {
            $type = Type::getType($meta->fieldMappings[$pk]['type']);

            return $this->value($em, $type, $meta->getReflectionProperty($pk)->getValue($entity));
        }

        /**
         * Primary key is not part of fieldMapping.
         *
         * @see https://github.com/DamienHarper/DoctrineAuditBundle/issues/40
         * @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/composite-primary-keys.html#identity-through-foreign-entities
         * We try to get it from associationMapping (will throw a MappingException if not available)
         */
        $targetEntity = $meta->getReflectionProperty($pk)->getValue($entity);

        $mapping = $meta->getAssociationMapping($pk);

        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata($mapping['targetEntity']);
        $pk = $meta->getSingleIdentifierFieldName();
        $type = Type::getType($meta->fieldMappings[$pk]['type']);

        return $this->value($em, $type, $meta->getReflectionProperty($pk)->getValue($targetEntity));
    }

    /**
     * Computes a usable diff.
     *
     * @param EntityManagerInterface $em
     * @param object                 $entity
     * @param array                  $ch
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return array
     */
    public function diff(EntityManagerInterface $em, $entity, array $ch): array
    {
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $diff = [];

        foreach ($ch as $fieldName => list($old, $new)) {
            $o = null;
            $n = null;

            if (
                $meta->hasField($fieldName) &&
                !isset($meta->embeddedClasses[$fieldName]) &&
                $this->configuration->isAuditedField($entity, $fieldName)
            ) {
                $mapping = $meta->fieldMappings[$fieldName];
                $type = Type::getType($mapping['type']);
                $o = $this->value($em, $type, $old);
                $n = $this->value($em, $type, $new);
            } elseif (
                $meta->hasAssociation($fieldName) &&
                $meta->isSingleValuedAssociation($fieldName) &&
                $this->configuration->isAuditedField($entity, $fieldName)
            ) {
                $o = $this->summarize($em, $old);
                $n = $this->summarize($em, $new);
            }

            if ($o !== $n) {
                $diff[$fieldName] = [
                    'old' => $o,
                    'new' => $n,
                ];
            }
        }
        ksort($diff);

        return $diff;
    }

    /**
     * Blames an audit operation.
     *
     * @return array
     */
    public function blame(): array
    {
        $user_id = null;
        $username = null;
        $client_ip = null;
        $user_fqdn = null;
        $user_firewall = null;

        $request = $this->configuration->getRequestStack()->getCurrentRequest();
        if (null !== $request) {
            $client_ip = $request->getClientIp();
            $user_firewall = null === $this->configuration->getFirewallMap()->getFirewallConfig($request) ? null : $this->configuration->getFirewallMap()->getFirewallConfig($request)->getName();
        }

        $user = null === $this->configuration->getUserProvider() ? null : $this->configuration->getUserProvider()->getUser();
        if ($user instanceof UserInterface) {
            $user_id = $user->getId();
            $username = $user->getUsername();
            $user_fqdn = DoctrineHelper::getRealClassName($user);
        }

        return [
            'user_id' => $user_id,
            'username' => $username,
            'client_ip' => $client_ip,
            'user_fqdn' => $user_fqdn,
            'user_firewall' => $user_firewall,
        ];
    }

    /**
     * Returns an array describing an entity.
     *
     * @param EntityManagerInterface $em
     * @param object                 $entity
     * @param mixed                  $id
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return array
     */
    public function summarize(EntityManagerInterface $em, $entity = null, $id = null): ?array
    {
        if (null === $entity) {
            return null;
        }

        $em->getUnitOfWork()->initializeObject($entity); // ensure that proxies are initialized
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $pkName = $meta->getSingleIdentifierFieldName();
        $pkValue = $id ?? $this->id($em, $entity);
        // An added guard for proxies that fail to initialize.
        if (null === $pkValue) {
            return null;
        }

        if (method_exists($entity, '__toString')) {
            $label = (string) $entity;
        } else {
            $label = DoctrineHelper::getRealClassName($entity).'#'.$pkValue;
        }

        return [
            'label' => $label,
            'class' => $meta->name,
            'table' => $meta->getTableName(),
            $pkName => $pkValue,
        ];
    }

    public static function paramToNamespace(string $entity): string
    {
        return str_replace('-', '\\', $entity);
    }

    public static function namespaceToParam(string $entity): string
    {
        return str_replace('\\', '-', $entity);
    }

    /**
     * Type converts the input value and returns it.
     *
     * @param EntityManagerInterface $em
     * @param Type                   $type
     * @param mixed                  $value
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return mixed
     */
    private function value(EntityManagerInterface $em, Type $type, $value)
    {
        if (null === $value) {
            return null;
        }

        $platform = $em->getConnection()->getDatabasePlatform();

        switch ($type->getName()) {
            case DoctrineHelper::getDoctrineType('BIGINT'):
                $convertedValue = (string) $value;

                break;
            case DoctrineHelper::getDoctrineType('INTEGER'):
            case DoctrineHelper::getDoctrineType('SMALLINT'):
                $convertedValue = (int) $value;

                break;
            case DoctrineHelper::getDoctrineType('DECIMAL'):
            case DoctrineHelper::getDoctrineType('FLOAT'):
            case DoctrineHelper::getDoctrineType('BOOLEAN'):
                $convertedValue = $type->convertToPHPValue($value, $platform);

                break;
            default:
                $convertedValue = $type->convertToDatabaseValue($value, $platform);
        }

        return $convertedValue;
    }
}
