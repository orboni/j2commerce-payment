<?php

/**
 * Orboni for J2Commerce: the confirm step's part — what happens next, and
 * the button J2Commerce's checkout sends through confirmPayment.
 *
 * @copyright 2026 Orboni
 * @license   GNU General Public License version 2 or later; see LICENSE
 */
\defined('_JEXEC') or exit;

use J2Commerce\Component\J2commerce\Administrator\Helper\J2htmlHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var array $displayData */
$vars = $displayData['vars'];
$e = static fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
?>
<form action="<?php echo Route::_('index.php?option=com_j2commerce&view=checkout'); ?>" method="post" name="payment_form" id="payment_form">
    <div class="j2commerce-payment-orboni note note-<?php echo $e($vars->orderpayment_type); ?>">
        <p class="j2commerce-payment-display-name"><strong><?php echo $e($vars->display_name); ?></strong></p>
        <p><?php echo $e(Text::_('PLG_J2COMMERCE_PAYMENT_ORBONI_NEXT_STEP')); ?></p>
        <?php if ($vars->onbeforepayment_text !== '') { ?>
            <div class="j2commerce-on-before-payment-text"><?php echo J2htmlHelper::merchantText($vars->onbeforepayment_text); ?></div>
        <?php } ?>
    </div>

    <button type="button" id="orboni-submit-button" class="j2commerce-cart-button button btn btn-primary"><?php echo $e($vars->button_text); ?></button>

    <input type="hidden" name="order_id" value="<?php echo $e($vars->order_id); ?>">
    <input type="hidden" name="orderpayment_type" value="<?php echo $e($vars->orderpayment_type); ?>">
    <input type="hidden" name="orderpayment_id" value="<?php echo (int) $vars->orderpayment_id; ?>">
    <input type="hidden" name="hash" value="<?php echo $e($vars->hash); ?>">
    <input type="hidden" name="option" value="com_j2commerce">
    <input type="hidden" name="view" value="checkout">
    <input type="hidden" name="task" value="confirmPayment">
    <input type="hidden" name="paction" value="process">

    <div class="plugin_error_div">
        <span class="plugin_error"></span>
        <span class="plugin_error_instruction"></span>
    </div>

    <?php echo HTMLHelper::_('form.token'); ?>
</form>
