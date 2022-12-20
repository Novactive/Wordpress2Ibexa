<?php

namespace Almaviacx\Bundle\Ibexa\WordPress\Service\Traits;

use Almaviacx\Bundle\Ibexa\WordPress\DependencyInjection\Configuration;
use Almaviacx\Bundle\Ibexa\WordPress\Exceptions\Exception;
use DateTimeImmutable;
use eZ\Publish\Core\MVC\ConfigResolverInterface;

trait ConfigResolverTrait
{
    protected ConfigResolverInterface $configResolver;

    private string $dateTime;

    /**
     * @required
     */
    public function setConfigResolver(ConfigResolverInterface $configResolver)
    {
        $this->configResolver = $configResolver;
    }

    /**
     * @required
     */
    public function setDateTime()
    {
        $this->dateTime = (new DateTimeImmutable())->format('Y/m/d');
    }

    /**
     * @throws Exception
     */
    protected function getBaseURl(string $namespace): string
    {
        $baseUrl = (string) $this->configResolver->getParameter('url', $namespace);
        $scheme  = parse_url($baseUrl, PHP_URL_SCHEME);
        $host    = parse_url($baseUrl, PHP_URL_HOST);
        if (empty($scheme) || empty($host)) {
            throw new Exception($baseUrl ?? 'No base URL');
        }

        return $baseUrl;
    }

    /**
     * @throws Exception
     */
    public function getRequestedUrl(string $serviceURL, string $prefix, string $namespace): string
    {
        $baseUrl = $this->getBaseURl($namespace);

        return trim($baseUrl, '/').'/'.$prefix.'/'.trim($serviceURL, '/');
    }

    public function getRootLocationId(): int
    {
        return (int) $this->configResolver->getParameter('content.tree_root.location_id');
    }

    private function getCurrentLang()
    {
        $langs = $this->configResolver->getParameter('languages');

        return $langs[0] ?? 'eng-GB';
    }

    public function getImageRootDir(): string
    {
        return (string) $this->configResolver->getParameter('local_image_root_dir', Configuration::NAMESPACE);
    }

    public function getImageSubDir(): string
    {
        return $this->dateTime;
    }

    public function getLocalImageStorageDir(): string
    {
        return rtrim($this->getImageRootDir(), '/').'/'.$this->getImageSubDir();
    }

    public function getImageSeparator(): string
    {
        return (string) $this->configResolver->getParameter('image_separator', Configuration::NAMESPACE);
    }

    public function getImageName(string $remoteId, string $baseName): string
    {
        return $remoteId.$this->getImageSeparator().$baseName;
    }
}
