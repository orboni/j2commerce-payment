<?php

/**
 * Orboni for J2Commerce.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */

namespace Orboni\Plugin\J2commerce\PaymentOrboni\Field;

\defined('_JEXEC') or exit;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Orboni\Plugin\J2commerce\PaymentOrboni\Extension\PaymentOrboni;
use Orboni\Plugin\J2commerce\PaymentOrboni\Signature;

/**
 * The webhook address for Orboni's dashboard, and the cron address (made
 * from the saved secret key) for a scheduled task. Shown, never saved.
 */
class AddressesField extends FormField
{
    protected $type = 'Addresses';

    protected function getInput()
    {
        $e = static fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        $data = $this->form->getData();
        $key = Signature::cronKey(trim((string) $data->get('params.secret_key', '')));

        $html = '<p><strong>'.$e(Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_WEBHOOK_URL')).'</strong><br>'
            .'<code style="user-select:all;word-break:break-all">'.$e(PaymentOrboni::ajaxUrl('webhook')).'</code><br>'
            .'<small>'.$e(Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_WEBHOOK_URL_TIP')).'</small></p>';
        $html .= '<p><strong>'.$e(Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_CRON_URL')).'</strong><br>';
        if ($key === '') {
            return $html.'<small>'.$e(Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_CRON_URL_SAVE_FIRST')).'</small></p>';
        }

        return $html.'<code style="user-select:all;word-break:break-all">'.$e(PaymentOrboni::ajaxUrl('cron').'&key='.$key).'</code><br>'
            .'<small>'.$e(Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_CRON_URL_TIP')).'</small></p>';
    }

    protected function getLabel()
    {
        return '<span class="form-label">'.htmlspecialchars(Text::_((string) $this->element['label']), ENT_QUOTES, 'UTF-8').'</span>';
    }
}
