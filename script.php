<?php

/**
 * Orboni for J2Commerce.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */
\defined('_JEXEC') or exit;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\Database\DatabaseInterface;

/**
 * Switches the plugin on when it is first installed: Joomla installs
 * plugins switched off. It is offered at checkout only once a secret key is
 * saved. An update leaves the setting as the merchant has it.
 */
class PlgJ2commercePayment_orboniInstallerScript
{
    public function postflight(string $type, InstallerAdapter $parent): bool
    {
        if ($type !== 'install') {
            return true;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled').' = 1')
            ->where($db->quoteName('type').' = '.$db->quote('plugin'))
            ->where($db->quoteName('folder').' = '.$db->quote('j2commerce'))
            ->where($db->quoteName('element').' = '.$db->quote('payment_orboni'));
        $db->setQuery($query)->execute();

        return true;
    }
}
