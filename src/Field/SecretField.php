<?php

/**
 * Orboni for J2Commerce.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */

namespace Orboni\Plugin\J2commerce\PaymentOrboni\Field;

\defined('_JEXEC') or exit;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Orboni\Plugin\J2commerce\PaymentOrboni\Api;

/**
 * A secret in the settings: an empty password input, never the saved value
 * printed back into the page; left empty, the saved one is kept. In the
 * field's own filter(): Joomla turns an empty value into '' before a filter
 * named in the form is called.
 */
class SecretField extends FormField
{
    protected $type = 'Secret';

    protected function getInput()
    {
        $saved = trim((string) $this->value) !== '';
        $placeholder = $saved ? Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_SAVED_KEEP') : (string) $this->element['hint'];

        return '<input type="password" class="form-control" autocomplete="off" spellcheck="false"'
            .' id="'.htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8').'"'
            .' name="'.htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8').'" value=""'
            .' placeholder="'.htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8').'">';
    }

    public function filter($value, $group = null, ?Registry $input = null)
    {
        return (string) $this->element['name'] === 'webhook_secret' ? self::keepWebhookSecret($value) : self::keepSecretKey($value);
    }

    /** The secret key as saved: the new one, or the saved one when left empty or not shaped like a key. */
    public static function keepSecretKey($value): string
    {
        return self::keep('secret_key', $value, Api::SECRET_KEY, 'PLG_J2COMMERCE_PAYMENT_ORBONI_ERROR_SECRET_KEY');
    }

    /** The webhook signing secret as saved, the same way. */
    public static function keepWebhookSecret($value): string
    {
        return self::keep('webhook_secret', $value, Api::WEBHOOK_SECRET, 'PLG_J2COMMERCE_PAYMENT_ORBONI_ERROR_WEBHOOK_SECRET');
    }

    private static function keep(string $name, $value, string $shape, string $error): string
    {
        $new = \is_string($value) ? trim($value) : '';
        if ($new !== '' && preg_match($shape, $new) === 1) {
            return $new;
        }
        if ($new !== '') {
            Factory::getApplication()->enqueueMessage(Text::_($error), 'error');
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type').' = '.$db->quote('plugin'))
            ->where($db->quoteName('folder').' = '.$db->quote('j2commerce'))
            ->where($db->quoteName('element').' = '.$db->quote('payment_orboni'));
        $params = json_decode((string) $db->setQuery($query)->loadResult(), true);

        return \is_array($params) && \is_string($params[$name] ?? null) ? trim($params[$name]) : '';
    }
}
