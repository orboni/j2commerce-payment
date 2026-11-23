<?php

/**
 * Orboni for J2Commerce.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */
\defined('_JEXEC') or exit;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Orboni\Plugin\J2commerce\PaymentOrboni\Extension\PaymentOrboni;

return new class implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $app = Factory::getApplication();
                $plugin = new PaymentOrboni(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('j2commerce', 'payment_orboni'),
                    $app->getLanguage(),
                    $container->get(DatabaseInterface::class)
                );
                $plugin->setApplication($app);

                return $plugin;
            }
        );
    }
};
