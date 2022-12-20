<?php

declare(strict_types=1);

namespace Almaviacx\Bundle\Ibexa\WordPress\DependencyInjection;

use Ibexa\Bundle\Core\DependencyInjection\Configuration\SiteAccessAware\ConfigurationProcessor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Yaml\Yaml;

class WordPressIbexaExtension extends Extension implements PrependExtensionInterface
{
    public const EXTENSION_NAME = 'word_press_ibexa';

    public function getAlias(): string
    {
        return self::EXTENSION_NAME;
    }

    /**
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);
        $processor     = new ConfigurationProcessor($container, Configuration::NAMESPACE);
        $processor->mapSetting('url', $config);
        $processor->mapConfigArray('posts', $config/* , ContextualizerInterface::MERGE_FROM_SECOND_LEVEL */);
        $processor->mapConfigArray('users', $config/* , ContextualizerInterface::MERGE_FROM_SECOND_LEVEL */);
        $processor->mapConfigArray('pages', $config/* , ContextualizerInterface::MERGE_FROM_SECOND_LEVEL */);
        $processor->mapConfigArray('categories', $config/* , ContextualizerInterface::MERGE_FROM_SECOND_LEVEL */);
        $processor->mapConfigArray('tags', $config/* , ContextualizerInterface::MERGE_FROM_SECOND_LEVEL */);
        $processor->mapConfigArray('image', $config/* , ContextualizerInterface::MERGE_FROM_SECOND_LEVEL */);
        $processor->mapConfigArray('media', $config/* , ContextualizerInterface::MERGE_FROM_SECOND_LEVEL */);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');
        // $config = Yaml::parse(file_get_contents(__DIR__ . '/../Resources/config/doctrine.yaml'));
        // $container->prependExtensionConfig('doctrine', $config);
    }

    /**
     * @throws \Exception
     */
    public function prepend(ContainerBuilder $container)
    {
        $configPath = __DIR__.'/../Resources/config';
        $loader     = new Loader\YamlFileLoader($container, new FileLocator($configPath));
        $loader->load('default_settings.yaml');
        $loader->load('logger.yaml');

        $this->prependExtension($container, __DIR__.'/../Resources/config/ibexa.yaml', 'ibexa');
    }

    private function prependExtension(ContainerBuilder $container, string $configFile, string $extensionName)
    {
        $config = Yaml::parse(file_get_contents($configFile));
        $container->prependExtensionConfig($extensionName, $config);
    }
}
