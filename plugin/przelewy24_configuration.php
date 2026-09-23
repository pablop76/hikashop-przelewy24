<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 *
 * Formularz konfiguracji metody płatności w panelu HikaShopa.
 * Wczytywany przez HikaShopa jako widok, więc $this to obiekt widoku.
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die('Restricted access');

$params = $this->element->payment_params ?? new stdClass();
?>
<tr>
	<td class="key">
		<label for="data[payment][payment_params][test_mode]">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_TEST_MODE'); ?>
		</label>
	</td>
	<td>
		<?php echo HTMLHelper::_('hikaselect.booleanlist', 'data[payment][payment_params][test_mode]', '', $params->test_mode ?? 1); ?>
		<p class="hikashop_help">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_TEST_MODE_HELP'); ?>
		</p>
	</td>
</tr>
<tr>
	<td class="key">
		<label for="data[payment][payment_params][merchant_id]">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_MERCHANT_ID'); ?>
		</label>
	</td>
	<td>
		<input type="text" inputmode="numeric" size="20"
		       name="data[payment][payment_params][merchant_id]"
		       value="<?php echo $this->escape($params->merchant_id ?? ''); ?>" />
		<p class="hikashop_help">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_MERCHANT_ID_HELP'); ?>
		</p>
	</td>
</tr>
<tr>
	<td class="key">
		<label for="data[payment][payment_params][pos_id]">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_POS_ID'); ?>
		</label>
	</td>
	<td>
		<input type="text" inputmode="numeric" size="20"
		       name="data[payment][payment_params][pos_id]"
		       value="<?php echo $this->escape($params->pos_id ?? ''); ?>" />
		<p class="hikashop_help">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_POS_ID_HELP'); ?>
		</p>
	</td>
</tr>
<tr>
	<td class="key">
		<label for="data[payment][payment_params][crc_key]">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_CRC_KEY'); ?>
		</label>
	</td>
	<td>
		<input type="password" autocomplete="off" size="40"
		       name="data[payment][payment_params][crc_key]"
		       value="<?php echo $this->escape($params->crc_key ?? ''); ?>" />
		<p class="hikashop_help">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_CRC_KEY_HELP'); ?>
		</p>
	</td>
</tr>
<tr>
	<td class="key">
		<label for="data[payment][payment_params][api_key]">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_API_KEY'); ?>
		</label>
	</td>
	<td>
		<input type="password" autocomplete="off" size="40"
		       name="data[payment][payment_params][api_key]"
		       value="<?php echo $this->escape($params->api_key ?? ''); ?>" />
		<p class="hikashop_help">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_API_KEY_HELP'); ?>
		</p>
	</td>
</tr>
<tr>
	<td class="key">
		<label>
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_NOTIFY_URL'); ?>
		</label>
	</td>
	<td>
		<p class="hikashop_help">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_NOTIFY_URL_HELP'); ?>
		</p>
	</td>
</tr>
<tr>
	<td class="key">
		<label for="data[payment][payment_params][debug]">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_DEBUG'); ?>
		</label>
	</td>
	<td>
		<?php echo HTMLHelper::_('hikaselect.booleanlist', 'data[payment][payment_params][debug]', '', $params->debug ?? 0); ?>
		<p class="hikashop_help">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_DEBUG_HELP'); ?>
		</p>
	</td>
</tr>
<tr>
	<td class="key">
		<label for="data[payment][payment_params][blik_in_shop]">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_IN_SHOP'); ?>
		</label>
	</td>
	<td>
		<?php echo HTMLHelper::_('hikaselect.booleanlist', 'data[payment][payment_params][blik_in_shop]', '', $params->blik_in_shop ?? 0); ?>
		<p class="hikashop_help">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_IN_SHOP_HELP'); ?>
		</p>
	</td>
</tr>
<tr>
	<td class="key">
		<label for="data[payment][payment_params][payment_method_id]">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_METHOD_ID'); ?>
		</label>
	</td>
	<td>
		<input type="text" inputmode="numeric" size="8"
		       name="data[payment][payment_params][payment_method_id]"
		       value="<?php echo $this->escape($params->payment_method_id ?? ''); ?>" />
		<p class="hikashop_help">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_METHOD_ID_HELP'); ?>
		</p>
	</td>
</tr>
<tr>
	<td class="key">
		<label for="data[payment][payment_params][verified_status]">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_VERIFIED_STATUS'); ?>
		</label>
	</td>
	<td>
		<?php echo $this->data['order_statuses']->display('data[payment][payment_params][verified_status]', $params->verified_status ?? 'confirmed'); ?>
		<p class="hikashop_help">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_VERIFIED_STATUS_HELP'); ?>
		</p>
	</td>
</tr>
<tr>
	<td class="key">
		<label for="data[payment][payment_params][invalid_status]">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_INVALID_STATUS'); ?>
		</label>
	</td>
	<td>
		<?php echo $this->data['order_statuses']->display('data[payment][payment_params][invalid_status]', $params->invalid_status ?? 'cancelled'); ?>
	</td>
</tr>
<tr>
	<td class="key">
		<label for="data[payment][payment_params][refund_status]">
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_REFUND_STATUS'); ?>
		</label>
	</td>
	<td>
		<?php
		// Lista statusów HikaShopa nie ma pustej pozycji. Bez niej
		// przeglądarka zaznacza pierwszy status i zapis formularza
		// po cichu włącza zwroty, więc dokładamy ją na początek.
		$refundSelect = $this->data['order_statuses']->display('data[payment][payment_params][refund_status]', $params->refund_status ?? '');
		$refundEmpty  = '<option value=""' . (($params->refund_status ?? '') === '' ? ' selected="selected"' : '') . '>'
			. htmlspecialchars(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_REFUND_STATUS_NONE'), ENT_QUOTES, 'UTF-8') . '</option>';
		echo preg_replace('/(<select\b[^>]*>)/i', '$1' . $refundEmpty, $refundSelect, 1);
		?>
		<p class="hikashop_help">
			<strong><?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_REFUND_STATUS_WARNING'); ?></strong>
			<br />
			<?php echo Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_REFUND_STATUS_HELP'); ?>
		</p>
	</td>
</tr>
