<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Targeting\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Pimcore\Targeting\Model\VisitorInfo;
use Pimcore\Targeting\Storage\Traits\TimestampsTrait;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DbStorage implements TargetingStorageInterface
{
    use TimestampsTrait;

    /**
     * @var Connection
     */
    private $db;

    /**
     * @var string
     */
    private $tableName = 'targeting_storage';

    public function __construct(Connection $db, array $options = [])
    {
        $this->db = $db;

        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);

        $this->handleOptions($resolver->resolve($options));
    }

    protected function handleOptions(array $options)
    {
        $this->tableName = $options['tableName'];
    }

    protected function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'tableName' => 'targeting_storage'
        ]);

        $resolver->setRequired(['tableName']);
        $resolver->setAllowedTypes('tableName', 'string');
    }

    public function all(VisitorInfo $visitorInfo, string $scope): array
    {
        if (!$visitorInfo->hasVisitorId()) {
            return [];
        }

        $stmt = $this->db->executeQuery(
            'SELECT name, value FROM ' . $this->tableName . ' WHERE visitorId = :visitorId AND scope = :scope AND name != :metaKey',
            [
                'visitorId' => $visitorInfo->getVisitorId(),
                'scope'     => $scope,
                'metaKey'   => self::STORAGE_KEY_META_ENTRY,
            ]
        );

        $result = $stmt->fetchAll();

        $data = [];
        foreach ($result as $row) {
            $data[$row['name']] = json_decode($row['value'], true);
        }

        return $data;
    }

    public function has(VisitorInfo $visitorInfo, string $scope, string $name): bool
    {
        if (!$visitorInfo->hasVisitorId()) {
            return false;
        }

        $stmt = $this->db->executeQuery(
            'SELECT COUNT(name) AS count FROM ' . $this->tableName . ' WHERE visitorId = :visitorId AND scope = :scope AND name = :name',
            [
                'visitorId' => $visitorInfo->getVisitorId(),
                'scope'     => $scope,
                'name'      => $name
            ]
        );

        $result = (int)$stmt->fetchColumn();

        return 1 === $result;
    }

    public function set(VisitorInfo $visitorInfo, string $scope, string $name, $value)
    {
        if (!$visitorInfo->hasVisitorId()) {
            return;
        }

        $json = json_encode($value);

        $query = <<<EOF
INSERT INTO {$this->tableName}
    (visitorId, scope, name, value, creationDate, modificationDate)
VALUES
    (:visitorId, :scope, :name, :value, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    value = :value, modificationDate = NOW();
EOF;

        $this->db->executeQuery(
            $query,
            [
                'visitorId' => $visitorInfo->getVisitorId(),
                'scope'     => $scope,
                'name'      => $name,
                'value'     => $json,
            ]
        );
    }

    public function get(VisitorInfo $visitorInfo, string $scope, string $name, $default = null)
    {
        if (!$visitorInfo->hasVisitorId()) {
            return $default;
        }

        $stmt = $this->db->executeQuery(
            'SELECT value FROM ' . $this->tableName . ' WHERE visitorId = :visitorId AND scope = :scope AND name = :name',
            [
                'visitorId' => $visitorInfo->getVisitorId(),
                'scope'     => $scope,
                'name'      => $name
            ]
        );

        $result = $stmt->fetchColumn();

        if (!$result) {
            return $default;
        }

        $decoded = json_decode($result, true);
        if (!$decoded) {
            return $default;
        }

        return $decoded;
    }

    public function clear(VisitorInfo $visitorInfo, string $scope = null)
    {
        if (!$visitorInfo->hasVisitorId()) {
            return;
        }

        if (null === $scope) {
            $this->db->executeQuery(
                'DELETE FROM ' . $this->tableName . ' WHERE visitorId = :visitorId',
                [
                    'visitorId' => $visitorInfo->getVisitorId()
                ]
            );
        } else {
            $this->db->executeQuery(
                'DELETE FROM ' . $this->tableName . ' WHERE visitorId = :visitorId AND scope = :scope',
                [
                    'visitorId' => $visitorInfo->getVisitorId(),
                    'scope'     => $scope
                ]
            );
        }
    }

    public function migrateFromStorage(TargetingStorageInterface $storage, VisitorInfo $visitorInfo, string $scope)
    {
        // only allow migration if a visitor ID is available as otherwise the fallback
        // would clear the original storage although data was not stored
        if (!$visitorInfo->hasVisitorId()) {
            throw new \LogicException('Can\'t migrate to DB storage as no visitor ID is set');
        }

        $values = $storage->all($visitorInfo, $scope);

        $this->db->beginTransaction();

        try {
            foreach ($values as $name => $value) {
                $this->set($visitorInfo, $scope, $name, $value);
            }

            $this->updateTimestamps(
                $visitorInfo,
                $scope,
                $storage->getCreatedAt($visitorInfo, $scope),
                $storage->getUpdatedAt($visitorInfo, $scope)
            );

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    public function getCreatedAt(VisitorInfo $visitorInfo, string $scope)
    {
        if (!$visitorInfo->hasVisitorId()) {
            return null;
        }

        $stmt = $this->db->executeQuery(
            'SELECT MIN(creationDate) FROM ' . $this->tableName . ' WHERE visitorId = :visitorId AND scope = :scope',
            [
                'visitorId' => $visitorInfo->getVisitorId(),
                'scope'     => $scope
            ]
        );

        return $this->convertToDateTime($stmt->fetchColumn());
    }

    public function getUpdatedAt(VisitorInfo $visitorInfo, string $scope)
    {
        if (!$visitorInfo->hasVisitorId()) {
            return null;
        }

        $stmt = $this->db->executeQuery(
            'SELECT MAX(modificationDate) FROM ' . $this->tableName . ' WHERE visitorId = :visitorId AND scope = :scope',
            [
                'visitorId' => $visitorInfo->getVisitorId(),
                'scope'     => $scope
            ]
        );

        return $this->convertToDateTime($stmt->fetchColumn());
    }

    private function convertToDateTime($result = null)
    {
        if (!$result) {
            return null;
        }

        $dateTime = $this->db->convertToPHPValue($result, Type::DATETIME);

        return \DateTimeImmutable::createFromMutable($dateTime);
    }

    private function updateTimestamps(
        VisitorInfo $visitorInfo,
        string $scope,
        \DateTimeInterface $createdAt = null,
        \DateTimeInterface $updatedAt = null
    )
    {
        $timestamps = $this->normalizeTimestamps($createdAt, $updatedAt);

        $query = <<<EOF
INSERT INTO {$this->tableName}
    (visitorId, scope, name, value, creationDate, modificationDate)
VALUES
    (:visitorId, :scope, :name, :value, :creationDate, :modificationDate)
ON DUPLICATE KEY UPDATE
    value = :value, creationDate = :creationDate, modificationDate = :modificationDate;
EOF;

        $this->db->executeQuery(
            $query,
            [
                'visitorId'        => $visitorInfo->getVisitorId(),
                'scope'            => $scope,
                'name'             => self::STORAGE_KEY_META_ENTRY,
                'value'            => 1,
                'creationDate'     => $timestamps['createdAt'],
                'modificationDate' => $timestamps['updatedAt'],
            ], [
                'creationDate'     => Type::DATETIME,
                'modificationDate' => Type::DATETIME,
            ]
        );
    }
}
