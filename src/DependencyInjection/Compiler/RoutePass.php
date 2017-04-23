<?php

declare(strict_types=1);
namespace Prooph\Bundle\ServiceBus\DependencyInjection\Compiler;

use Prooph\Bundle\ServiceBus\DependencyInjection\ProophServiceBusExtension;
use Prooph\Common\Messaging\HasMessageName;
use Prooph\Common\Messaging\Message;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class RoutePass implements CompilerPassInterface
{
    private static function tryToDetectMessageName(ReflectionClass $messageReflection)
    {
        if (! $messageReflection->implementsInterface(HasMessageName::class)) {
            return;
        }
        $instance = $messageReflection->newInstanceWithoutConstructor(); /* @var $instance HasMessageName */
        if ($messageReflection->hasMethod('init')) {
            $init = $messageReflection->getMethod('init');
            $init->setAccessible(true);
            $init->invoke($instance);
        }

        return $instance->messageName();
    }

    public function process(ContainerBuilder $container)
    {
        foreach (ProophServiceBusExtension::AVAILABLE_BUSES as $type) {
            if (! $container->hasParameter('prooph_service_bus.' . $type . '_buses')) {
                continue;
            }

            $buses = $container->getParameter('prooph_service_bus.' . $type . '_buses');

            foreach ($buses as $name => $bus) {
                $router = $container->findDefinition(sprintf('prooph_service_bus.%s.router', $name));
                $routerArguments = $router->getArguments();

                $handlers = $container->findTaggedServiceIds(sprintf('prooph_service_bus.%s.route_target', $name));

                foreach ($handlers as $id => $args) {
                    foreach ($args as $eachArgs) {
                        $messageNames = $this->recognizeMessageNames($container, $id, $eachArgs);

                        if ($type === 'event') {
                            $routerArguments[0] = array_merge_recursive(
                                $routerArguments[0],
                                array_combine($messageNames, array_fill(0, count($messageNames), [$id]))
                            );
                            $routerArguments[0] = array_map('array_unique', $routerArguments[0]);
                        } else {
                            $routerArguments[0] = array_merge(
                                $routerArguments[0],
                                array_combine($messageNames, array_fill(0, count($messageNames), $id))
                            );
                        }
                    }
                }
                $router->setArguments($routerArguments);
            }
        }
    }

    private function recognizeMessageNames(ContainerBuilder $container, $id, array $args)
    {
        if (isset($args['message'])) {
            return [$args['message']];
        }
        $handlerReflection = new ReflectionClass($container->getDefinition($id)->getClass());

        $methodsWithMessageParameter = array_filter(
            $handlerReflection->getMethods(ReflectionMethod::IS_PUBLIC),
            function (ReflectionMethod $method) {
                return $method->getNumberOfRequiredParameters() === 1
                && $method->getParameters()[0]->getClass()
                && $method->getParameters()[0]->getClass()->getName() !== Message::class
                && $method->getParameters()[0]->getClass()->implementsInterface(Message::class);
            }
        );

        return array_filter(array_unique(array_map(function (ReflectionMethod $method) {
            return self::tryToDetectMessageName($method->getParameters()[0]->getClass());
        }, $methodsWithMessageParameter)));
    }
}
