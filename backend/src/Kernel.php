<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /**
     * gesdinet/jwt-refresh-token-bundle 3.0.0 points some services at a
     * parameter's name instead of the service it holds
     * (config/reuse_detection.php: `service((string) param('…cache'))`), so
     * the container refuses to build with reuse_detection on. This resolves
     * those references. Harmless once the bundle is fixed: there is nothing
     * left to match.
     */
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!str_starts_with($id, 'gesdinet_jwt_refresh_token.')) {
                continue;
            }

            foreach ($definition->getArguments() as $index => $argument) {
                if ($argument instanceof Reference && preg_match('/^%([^%]+)%$/', (string) $argument, $match)) {
                    $definition->replaceArgument($index, new Reference($container->getParameter($match[1]), $argument->getInvalidBehavior()));
                }
            }
        }
    }
}
