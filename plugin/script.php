<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

use Joomla\CMS\Application\ApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

defined('_JEXEC') or die;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class () implements InstallerScriptInterface {
                /** Minimalna wersja Joomli. */
                private string $minimumJoomla = '5.0';

                /** Minimalna wersja PHP. */
                private string $minimumPhp = '8.1';

                public function preflight(string $type, InstallerAdapter $parent): bool
                {
                    if (version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
                        $this->komunikat(
                            'Wtyczka wymaga PHP ' . $this->minimumPhp . ' lub nowszego. Ten serwer ma ' . PHP_VERSION . '.',
                            'error'
                        );

                        return false;
                    }

                    if (version_compare(JVERSION, $this->minimumJoomla, '<')) {
                        $this->komunikat(
                            'Wtyczka wymaga Joomli ' . $this->minimumJoomla . ' lub nowszej.',
                            'error'
                        );

                        return false;
                    }

                    // Bez HikaShopa wtyczka nie ma czego rozszerzać. Nie
                    // przerywamy instalacji, bo kolejność instalowania
                    // rozszerzeń bywa dowolna, ale uprzedzamy.
                    if ($type !== 'uninstall' && !$this->czyHikaShopObecny()) {
                        $this->komunikat(
                            'Nie wykryto HikaShopa. Wtyczka zadziała dopiero po jego zainstalowaniu.',
                            'warning'
                        );
                    }

                    return true;
                }

                public function install(InstallerAdapter $parent): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $parent): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $parent): bool
                {
                    return true;
                }

                public function postflight(string $type, InstallerAdapter $parent): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    $this->wlaczWtyczke();

                    $this->komunikat(
                        'Przelewy24 zainstalowane i włączone. Metodę płatności dodaj w HikaShopie: '
                        . 'System, Metody płatności, Nowa, Przelewy24. Pamiętaj o wpisaniu adresu IP '
                        . 'serwera w panelu Przelewy24, bez tego połączenie z API zostanie odrzucone.',
                        'message'
                    );

                    return true;
                }

                /**
                 * Wtyczka płatności bez włączenia jest niewidoczna dla
                 * HikaShopa, a ręczne szukanie jej w Menedżerze Wtyczek
                 * to najczęstsza przyczyna zgłoszenia "nie działa".
                 */
                private function wlaczWtyczke(): void
                {
                    try {
                        $db = Factory::getContainer()->get(DatabaseInterface::class);

                        $query = $db->getQuery(true)
                            ->update($db->quoteName('#__extensions'))
                            ->set($db->quoteName('enabled') . ' = 1')
                            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                            ->where($db->quoteName('folder') . ' = ' . $db->quote('hikashoppayment'))
                            ->where($db->quoteName('element') . ' = ' . $db->quote('przelewy24'));

                        $db->setQuery($query);
                        $db->execute();
                    } catch (\Throwable) {
                        // Nieudane włączenie nie może przerwać instalacji.
                        // Sprzedawca włączy wtyczkę ręcznie.
                    }
                }

                private function czyHikaShopObecny(): bool
                {
                    return is_file(JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php');
                }

                private function komunikat(string $tresc, string $typ): void
                {
                    $app = Factory::getApplication();

                    if ($app instanceof ApplicationInterface && method_exists($app, 'enqueueMessage')) {
                        $app->enqueueMessage($tresc, $typ);
                    }
                }
            }
        );
    }
};
