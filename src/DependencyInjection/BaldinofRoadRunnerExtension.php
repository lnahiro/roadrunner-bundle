<?php

declare(strict_types=1);

namespace Baldinof\RoadRunnerBundle\DependencyInjection;

use Baldinof\RoadRunnerBundle\Event\WorkerStartEvent;
use Baldinof\RoadRunnerBundle\EventListener\ConfigureVarDumperListener;
use Baldinof\RoadRunnerBundle\EventListener\DeclareMetricsListener;
use Baldinof\RoadRunnerBundle\EventListener\DoctrineMongoDBListener;
use Baldinof\RoadRunnerBundle\EventListener\SentryListener;
use Baldinof\RoadRunnerBundle\Http\Middleware\BlackfireMiddleware;
use Baldinof\RoadRunnerBundle\Http\Middleware\DoctrineMiddleware;
use Baldinof\RoadRunnerBundle\Http\Middleware\NativeSessionMiddleware;
use Baldinof\RoadRunnerBundle\Http\Middleware\SentryMiddleware;
use Baldinof\RoadRunnerBundle\Reboot\KernelRebootStrategyInterface;
use Baldinof\RoadRunnerBundle\Reboot\OnExceptionRebootStrategy;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Spiral\RoadRunner\Metrics\Collector;
use Spiral\RoadRunner\Metrics\MetricsInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class BaldinofRoadRunnerExtension extends Extension
{
    const MONOLOG_CHANNEL = 'roadrunner';

    /**
     * @param array<string, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');

        if ($container->getParameter('kernel.debug')) {
            $this->loadDebug($container);
        }

        $container->register(KernelRebootStrategyInterface::class, OnExceptionRebootStrategy::class)
            ->addArgument($config['kernel_reboot']['allowed_exceptions'])
            ->addArgument(new Reference(LoggerInterface::class))
            ->setAutoconfigured(true)
            ->addTag('monolog.logger', ['channel' => self::MONOLOG_CHANNEL]);

        $container->setParameter('baldinof_road_runner.middlewares', $config['middlewares']);

        $this->loadIntegrations($container, $config);
    }

    private function loadDebug(ContainerBuilder $container): void
    {
        $container->register(ConfigureVarDumperListener::class, ConfigureVarDumperListener::class)
            ->addTag('kernel.event_listener', ['event' => WorkerStartEvent::class])
            ->addArgument(new Reference('data_collector.dump'))
            ->addArgument(new Reference('var_dumper.cloner'))
            ->addArgument('%env(bool:default::RR)%');
    }

    private function loadIntegrations(ContainerBuilder $container, array $config): void
    {
        $beforeMiddlewares = [];
        $lastMiddlewares = [];

        if (!$config['default_integrations']) {
            $container->setParameter('baldinof_road_runner.middlewares.default', ['before' => $beforeMiddlewares, 'after' => $lastMiddlewares]);

            return;
        }

        /** @var array */
        $bundles = $container->getParameter('kernel.bundles');

        if (class_exists(\BlackfireProbe::class)) {
            $container->register(BlackfireMiddleware::class);
            $beforeMiddlewares[] = BlackfireMiddleware::class;
        }

        if (isset($bundles['SentryBundle'])) {
            $container
                ->register(SentryMiddleware::class)
                ->addArgument(new Reference(HubInterface::class));

            $container
                ->register(SentryListener::class)
                ->addArgument(new Reference(HubInterface::class))
                ->setAutoconfigured(true);

            $beforeMiddlewares[] = SentryMiddleware::class;
        }

        if (isset($bundles['DoctrineMongoDBBundle'])) {
            $container
                ->register(DoctrineMongoDBListener::class)
                ->addArgument(new Reference('service_container'))
                ->setAutoconfigured(true);
        }

        if (isset($bundles['DoctrineBundle'])) {
            $container
                ->register(DoctrineMiddleware::class)
                ->addArgument(new Reference(ManagerRegistry::class))
                ->addArgument(new Reference('service_container'))
                ->addArgument(new Reference(EventDispatcherInterface::class))
                ->addArgument(new Reference(LoggerInterface::class))
                ->addTag('monolog.logger', ['channel' => self::MONOLOG_CHANNEL])
            ;

            $beforeMiddlewares[] = DoctrineMiddleware::class;
        }

        $beforeMiddlewares[] = NativeSessionMiddleware::class;

        if ($config['metrics']['enabled']) {
            $this->configureMetrics($config, $container);
        }

        $container->setParameter('baldinof_road_runner.middlewares.default', ['before' => $beforeMiddlewares, 'after' => $lastMiddlewares]);
    }

    private function configureMetrics(array $config, ContainerBuilder $container): void
    {
        if (!interface_exists(MetricsInterface::class)) {
            throw new LogicException('RoadRunner Metrics support cannot be enabled as the spiral/roadrunner-metrics is not installed. Try running "composer require spiral/roadrunner-metrics".');
        }

        $listenerDef = $container->register(DeclareMetricsListener::class)
            ->setAutoconfigured(true)
            ->addArgument(new Reference(MetricsInterface::class));

        foreach ($config['metrics']['collect'] as $name => $metric) {
            $def = new Definition(Collector::class);
            $def->setFactory([Collector::class, $metric['type']]);

            $id = "baldinof_road_runner.metrics.internal.collector.$name";
            $container->setDefinition($id, $def);

            $listenerDef->addMethodCall('addCollector', [$name, $metric]);
        }
    }
}
