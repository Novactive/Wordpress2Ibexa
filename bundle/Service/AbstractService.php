<?php

declare(strict_types=1);

namespace Almaviacx\Bundle\Ibexa\WordPress\Service;

use Almaviacx\Bundle\Ibexa\WordPress\DependencyInjection\Configuration;
use Almaviacx\Bundle\Ibexa\WordPress\Exceptions\Exception;
use Almaviacx\Bundle\Ibexa\WordPress\Service\Traits\ConfigResolverTrait;
use Almaviacx\Bundle\Ibexa\WordPress\Service\Traits\HttpClientTrait;
use Almaviacx\Bundle\Ibexa\WordPress\Service\Traits\IbexaRepositoryTrait;
use Almaviacx\Bundle\Ibexa\WordPress\Service\Traits\LoggerTrait;
use Almaviacx\Bundle\Ibexa\WordPress\ValueObject\OrderBy;
use Almaviacx\Bundle\Ibexa\WordPress\ValueObject\WPObject;
use ArrayObject;
use DateTime;
use Ibexa\Contracts\Core\Repository\Exceptions\BadStateException;
use Ibexa\Contracts\Core\Repository\Exceptions\ContentFieldValidationException;
use Ibexa\Contracts\Core\Repository\Exceptions\ContentValidationException;
use Ibexa\Contracts\Core\Repository\Exceptions\InvalidArgumentException;
use Ibexa\Contracts\Core\Repository\Exceptions\NotFoundException;
use Ibexa\Contracts\Core\Repository\Exceptions\UnauthorizedException;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use ZipArchive;

abstract class AbstractService implements ServiceInterface
{
    use ConfigResolverTrait;
    use LoggerTrait;
    use IbexaRepositoryTrait;
    use HttpClientTrait;

    protected const NAMESPACE    = Configuration::NAMESPACE;
    private const SERVICE_PREFIX = 'wp-json/wp/v2';
    public const DATATYPE        = '';

    public const SERVICE_URL = '';
    public const ROOT        = '';

    protected string $objectClass;
    protected string $exceptionClass;
    protected StorageInterface $storage;
    protected ContentInterface $contentInterface;

    public function __construct(StorageInterface $storage, ContentInterface $contentInterface)
    {
        $this->storage          = $storage;
        $this->contentInterface = $contentInterface;
    }

    /**
     * @throws BadStateException
     * @throws ContentFieldValidationException
     * @throws ContentValidationException
     * @throws NotFoundException
     * @throws UnauthorizedException
     * @throws InvalidArgumentException
     */
    public function createAsSubObject(int $objectId, bool $update = true): Content
    {
        $wpObject = $this->getOne($objectId);
        if (null === $wpObject) {
            throw new RuntimeException('Cannot find '.static::DATATYPE.' Id '.$objectId);
        }
        $wpObjectContent = $this->createContent($wpObject, $update);
        if (null === $wpObjectContent) {
            throw new RuntimeException('Cannot create '.static::DATATYPE.' Id '.$objectId);
        }

        return $wpObjectContent;
    }

    final public function import(?int $perPage = null, ?int $page = null, ?array $options = null): ArrayObject
    {
        $this->storage->clearAll();
        $perPage       = $perPage > 0 ? $perPage : null;
        $page          = abs($page ?? 1);
        $postCount     = 0;
        $importedCount = 0;
        while (true) {
            $objects = $this->get($page, $perPage);
            if (0 === count($objects)) {
                break;
            }
            $postCount += count($objects);
            foreach ($objects as $object) {
                /* @var WPObject $object */
                try {
                    $this->createContent($object);
                    ++$importedCount;
                } catch (\Exception $exception) {
                    $this->error(__METHOD__, ['e' => $exception, 'object' => $object]);
                }
            }
            $this->info('iteration:'.$page);
            ++$page;
        }
        if (true === ($options['export-images'] ?? false)) {
            $this->exportImages();
        }
        $this->storage->clearAll();
        $this->info('Total content:'.$postCount);
        $this->info('Imported content:'.$importedCount);

        return new ArrayObject(
            [
                'success' => $importedCount,
                'total' => $postCount,
            ],
            ArrayObject::STD_PROP_LIST | ArrayObject::ARRAY_AS_PROPS
        );
    }

    /**
     * @throws Exception
     */
    final protected function fetch(int $page = 1, ?int $perPage = null, array $options = []): array
    {
        $requestURL = $this->getRequestedUrl(
            static::SERVICE_URL,
            self::SERVICE_PREFIX,
            self::NAMESPACE
        );
        if (null === $perPage) {
            $perPage = max(1, $this->getConfigurationField('per_page'));
        }
        $this->getOrderBy($options);
        $headers                      = $options['headers'] ?? [];
        $options['headers']['Accept'] = $headers['Accept'] ?? 'application/json';
        $options['query']['per_page'] = $options['query']['per_page'] ?? $perPage;
        $options['query']['page']     = $options['query']['page'] ?? max($page, 1);

        try {
            $response = $this->client->request(
                Request::METHOD_GET,
                $requestURL,
                $options
            );

            return $response->toArray();
        } catch (\Exception|ExceptionInterface $exception) {
            throw new Exception($requestURL, $options, $exception);
        }
    }

    /**
     * @throws Exception
     */
    final protected function fetchOne(int $id, array $options = []): array
    {
        $requestURL = $this->getRequestedUrl(
            static::SERVICE_URL,
            self::SERVICE_PREFIX,
            self::NAMESPACE
        );
        $options['headers']['Accept'] = $options['headers']['Accept'] ?? 'application/json';
        $requestURL                   = rtrim($requestURL, '/').'/'.$id;
        try {
            $response = $this->client->request(
                Request::METHOD_GET,
                $requestURL,
                $options
            );

            return $response->toArray();
        } catch (\Exception|ExceptionInterface $exception) {
            throw new Exception($requestURL, $options, $exception);
        }
    }

    protected function createObject(array $data): ?WPObject
    {
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $object = new $this->objectClass($data);
            $this->storage->store((string) $id, static::DATATYPE, $object);

            return $object;
        }

        return null;
    }

    public function get(int $page = 1, ?int $perPage = null, array $options = []): array
    {
        try {
            $elements = $this->fetch($page, $perPage, $options);
            if (empty($elements) || !empty($elements['code']) || !empty($elements['message'])) {
                return [];
            }
            $objects = [];
            foreach ($elements as $element) {
                $objects[] = $this->createObject($element);
            }

            return $objects;
        } catch (Exception $exception) {
            return [];
        }
    }

    public function getOne(int $id, bool $force = false): ?WPObject
    {
        $url = static::SERVICE_URL.'/'.$id;
        if (false === $force) {
            $data = $this->storage->load((string) $id, static::DATATYPE);
            if (null !== $data) {
                $this->debug('['.__METHOD__.']found('.$data->getWPObjectId().')');

                return $data;
            }
        }
        try {
            $data = $this->fetchOne($id);
            if (empty($data)) {
                throw new Exception($url);
            }
        } catch (Exception $exception) {
            throw new $this->exceptionClass($url);
        }

        return $this->createObject($data);
    }

    /**
     * @throws BadStateException
     * @throws ContentFieldValidationException
     * @throws ContentValidationException
     * @throws NotFoundException
     * @throws UnauthorizedException
     * @throws InvalidArgumentException
     */
    public function createContent(WPObject $object, bool $update = false): ?Content
    {
        $values           = $this->getConfigurationValues();
        $remoteId         = static::DATATYPE.'-'.$object->getWPObjectId();
        $parentLocationId = $values['parent_location'] ?? null;

        return $this->innerCreateContent($object, $values, $remoteId, $parentLocationId, $update);
    }

    public function getContentTypeIdentifier()
    {
        return $this->getConfigurationField('content_type');
    }

    public function getSlugFieldIdentifier()
    {
        return $this->getConfigurationField('slug_field');
    }

    /**
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws BadStateException
     * @throws ContentValidationException
     * @throws UnauthorizedException
     * @throws ContentFieldValidationException
     */
    protected function innerCreateContent(
        WPObject $object,
        array $values,
        string $remoteId,
        int $parentLocationId,
        bool $update = false
    ): ?Content {
        $content = $this->contentInterface->createContent(
            $object,
            $values,
            $remoteId,
            $parentLocationId,
            $update
        );
        if ($content) {
            $this->info(
                'created (content) => '.$content->getName()
                .'('.$content->id.')('.$content->contentInfo->remoteId.')'
            );
        }

        return $content;
    }

    private function getOrderBy(array &$options)
    {
        $orderBy = (new OrderBy($options))->format();
        unset($options['order'], $options['orderby']);
        if (!empty($orderBy['orderby'])) {
            $options['orderby'] = $orderBy['orderby'];
            $options['order']   = $orderBy['order'];
        }
        $options = $orderBy + $options;
    }

    private function getConfigurationField(string $field)
    {
        $values = $this->getConfigurationValues();

        return $values[$field] ?? null;
    }

    private function getConfigurationValues(): array
    {
        return (array) $this->configResolver->getParameter(static::ROOT, self::NAMESPACE);
    }

    private function exportImages(): void
    {
        $dateTime   = new DateTime();
        $folderName = '/tmp/exportimages_'.$dateTime->format('d-m-Y').'.zip';
        $this->zipDirectory($this->getLocalImageStorageDir(), $folderName);
    }

    private function zipDirectory($directory, $zipName): void
    {
        try {
            $zip    = new ZipArchive();
            $finder = new Finder();
            if (true !== $zip->open($zipName, \ZipArchive::CREATE)) {
                throw new FileException('Zip file could not be created/opened.');
            }
            $finder->files()->in($directory);
            foreach ($finder as $file) {
                $zip->addFile($file->getRealpath(), basename($file->getRealpath()));
            }
            if (!$zip->close()) {
                throw new FileException('Zip file could not be closed.');
            }
            $this->info('Zip '.$zipName.' created');
        } catch (\Exception $exception) {
            $this->error(__METHOD__, ['e' => $exception]);
        }
    }
}
