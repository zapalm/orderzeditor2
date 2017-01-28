<?php
/**
 * Orders editor: module for PrestaShop 1.4
 *
 * @author     zapalm <zapalm@ya.ru>
 * @copyright (c) 2012, zapalm
 * @link      http://prestashop.modulez.ru/en/administrative-tools/10-orders-editor-module-for-prestashop.html
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

class OrderPayment extends ObjectModel
{
	public $id_order;

	public $id_currency;

	public $amount;

	public $payment_method;

	public $conversion_rate;

	public $transaction_id;

	public $card_number;

	public $card_brand;

	public $card_expiration;

	public $card_holder;

	public $date_add;

	protected $table = 'order_payment';

	protected $identifier = 'id_order_payment';

	protected $fieldsRequired = array('id_currency', 'amount');

	protected $fieldsSize = array(
		'order_reference' => 9,
		'transaction_id' => 254,
		'card_number' => 254,
		'card_brand' => 254,
		'card_expiration' => 254,
		'card_holder' => 254
	);

	protected $fieldsValidate = array(
		'id_order' => 'isUnsignedId',
		'id_currency' => 'isUnsignedId',
		'amount' => 'isPrice',
		'payment_method' => 'isGenericName',
		'conversion_rate' => 'isFloat',
		'transaction_id' => 'isAnything',
		'card_number' => 'isAnything',
		'card_brand' => 'isAnything',
		'card_expiration' => 'isAnything',
		'card_holder' => 'isAnything',
		'date_add' => 'isDate'
	);

	public function getFields()
	{
		parent::validateFields();

		$fields = array();
		$fields['id_order'] = (int)$this->id_order;
		$fields['id_currency'] = (int)$this->id_currency;
		$fields['amount'] = (float)$this->amount;
		$fields['payment_method'] = pSQL($this->payment_method);
		$fields['conversion_rate'] = (float)$this->conversion_rate;
		$fields['transaction_id'] = pSQL($this->transaction_id);
		$fields['card_number'] = pSQL($this->card_number);
		$fields['card_brand'] = pSQL($this->card_brand);
		$fields['card_expiration'] = pSQL($this->card_expiration);
		$fields['card_holder'] = pSQL($this->card_holder);
		$fields['date_add'] = pSQL($this->date_add);
		
		return $fields;
	}

	public function add($autodate = true, $nullValues = false)
	{
		if (parent::add($autodate, $nullValues))
		{
			Module::hookExec('paymentCCAdd', array('paymentCC' => $this));
			return true;
		}
		return false;
	}

	/**
	 * Получить массив оплат для заказа по его id.
	 *
	 * @return array|false
	 */
	public static function getByOrderId($id_order)
	{
		$sql = '
			SELECT *
			FROM `'._DB_PREFIX_.'order_payment`
			WHERE `id_order` = '.(int)$id_order;

		return Db::getInstance()->ExecuteS($sql, true, 0);
	}
}