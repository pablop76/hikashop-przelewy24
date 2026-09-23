<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Extension\Przelewy24;

defined('_JEXEC') or die;

return new class () implements ServiceProviderInterface {
    /**
     * Rejestruje wtyczkę w kontenerze Joomli.
     *
     * Tą drogą wtyczkę tworzy sama Joomla, przy wyzwalaniu zdarzeń.
     * HikaShop chodzi obok kontenera i tworzy ją własnoręcznie, przez
     * alias z przelewy24.php. Obie drogi dają obiekt tej samej klasy.
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $daneWtyczki = PluginHelper::getPlugin('hikashoppayment', 'przelewy24');

                $plugin = new Przelewy24($dispatcher, (array) $daneWtyczki);
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
