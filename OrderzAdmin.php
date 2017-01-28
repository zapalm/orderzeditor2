<?php
/**
 * Orders editor: module for PrestaShop 1.4
 *
 * @author     zapalm <zapalm@ya.ru>
 * @copyright (c) 2012, zapalm
 * @link      http://prestashop.modulez.ru/en/administrative-tools/10-orders-editor-module-for-prestashop.html
 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_'))
	exit;

include_once(PS_ADMIN_DIR.'/../classes/AdminTab.php');
include_once(PS_ADMIN_DIR.'/../modules/orderzeditor2/OrderPayment.php');

class OrderzAdmin extends AdminTab
{
	private $_postErrors = array();
	private static $file_cache = array();

	/** @var array Типы расчета цены корзины (для ретро-совместимости - версии <= 1.4.0.10 не имеют эти константы в классе Cart) */
	private static $order_total_calc_type = array(
		'ONLY_PRODUCTS' => 1,
		'ONLY_DISCOUNTS' => 2,
		'BOTH' => 3,
		'BOTH_WITHOUT_SHIPPING' => 4,
		'ONLY_SHIPPING' => 5,
		'ONLY_WRAPPING' => 6,
		'ONLY_PRODUCTS_WITHOUT_SHIPPING' => 7,
		'ONLY_PHYSICAL_PRODUCTS_WITHOUT_SHIPPING' => 8,
	);

	public function __construct()
	{
		global $cookie;

		$this->name = 'orderzeditor2';
		$this->table = 'order';
		$this->className = 'Order';
		$this->edit = true;
		$this->delete = true;
		$this->noAdd = true;
		$this->colorOnBackground = true;
		$this->_select = '
			a.id_order AS id_pdf,
			CONCAT(LEFT(c.`firstname`, 1), \'. \', c.`lastname`) AS `customer`,
			osl.`name` AS `osname`,
			os.`color`,
			IF((SELECT COUNT(so.id_order) FROM `'._DB_PREFIX_.'orders` so WHERE so.id_customer = a.id_customer AND so.valid = 1) > 1, 0, 1) as new,
			(SELECT COUNT(od.`id_order`) FROM `'._DB_PREFIX_.'order_detail` od WHERE od.`id_order` = a.`id_order` GROUP BY `id_order`) AS product_number';
			$this->_join = 'LEFT JOIN `'._DB_PREFIX_.'customer` c ON (c.`id_customer` = a.`id_customer`)
			LEFT JOIN `'._DB_PREFIX_.'order_history` oh ON (oh.`id_order` = a.`id_order`)
			LEFT JOIN `'._DB_PREFIX_.'order_state` os ON (os.`id_order_state` = oh.`id_order_state`)
			LEFT JOIN `'._DB_PREFIX_.'order_state_lang` osl ON (os.`id_order_state` = osl.`id_order_state` AND osl.`id_lang` = '.(int)$cookie->id_lang.')
		';
		$this->_where = '
			AND oh.`id_order_history` = (SELECT MAX(`id_order_history`) FROM `'._DB_PREFIX_.'order_history` moh WHERE moh.`id_order` = a.`id_order` GROUP BY moh.`id_order`)
		';

		$states_array = array();
		$states = OrderState::getOrderStates((int)$cookie->id_lang);
		foreach ($states as $state)
			$states_array[$state['id_order_state']] = $state['name'];

		$this->fieldsDisplay = array(
			'id_order' => array('title' => $this->l('ID'), 'align' => 'center', 'width' => 25),
			'new' => array('title' => $this->l('New'), 'width' => 25, 'align' => 'center', 'type' => 'bool', 'filter_key' => 'new', 'tmpTableFilter' => true, 'icon' => array(0 => 'blank.gif', 1 => 'news-new.gif'), 'orderby' => false),
			'customer' => array('title' => $this->l('Customer'), 'widthColumn' => 160, 'width' => 140, 'filter_key' => 'customer', 'tmpTableFilter' => true),
			'total_paid' => array('title' => $this->l('Total'), 'width' => 70, 'align' => 'right', 'prefix' => '<b>', 'suffix' => '</b>', 'price' => true, 'currency' => true),
			'payment' => array('title' => $this->l('Payment'), 'width' => 100),
			'osname' => array('title' => $this->l('Status'), 'widthColumn' => 250, 'type' => 'select', 'select' => $states_array, 'filter_key' => 'os!id_order_state', 'filter_type' => 'int', 'width' => 200),
			'date_add' => array('title' => $this->l('Date'), 'width' => 90, 'align' => 'right', 'type' => 'datetime', 'filter_key' => 'a!date_add')
		);

		parent::__construct();
	}

	/**
	 * Get domain name according to configuration and ignoring ssl (there is no presents in PS <= 1.4.0.1).
	 *
	 * @return string
	 */
	public static function getShopDomain($http = false, $entities = false)
	{
		if (version_compare(_PS_VERSION_, '1.4.0.1', '>'))
			return Tools::getShopDomain($http, $entities);

		if (!($domain = Configuration::get('PS_SHOP_DOMAIN')))
			$domain = Tools::getHttpHost();
		if ($entities)
			$domain = htmlspecialchars($domain, ENT_COMPAT, 'UTF-8');
		if ($http)
			$domain = 'http://'.$domain;

		return $domain;
	}

	/**
	 * Получить перевод строки.
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	protected function l($string, $class = null, $addslashes = null, $htmlentities = null)
	{
		global $_MODULES, $_MODULE, $cookie;

		$id_lang = (!isset($cookie) || !is_object($cookie)) ? (int)Configuration::get('PS_LANG_DEFAULT') : (int)$cookie->id_lang;

		$file = _PS_MODULE_DIR_.$this->name.'/'.Language::getIsoById($id_lang).'.php';

		if (self::existsFileCache($file) && include_once($file))
			$_MODULES = !empty($_MODULES) ? array_merge($_MODULES, $_MODULE) : $_MODULE;

		if (!is_array($_MODULES))
			return (str_replace('"', '&quot;', $string));

		$source = Tools::strtolower(get_class($this));
		$string_md5 = md5(str_replace('\'', '\\\'', $string));
		$current_key = '<{'.$this->name.'}'._THEME_NAME_.'>'.$source.'_'.$string_md5;
		$default_key = '<{'.$this->name.'}prestashop>'.$source.'_'.$string_md5;

		if (key_exists($current_key, $_MODULES))
			$ret = Tools::stripslashes($_MODULES[$current_key]);
		elseif (key_exists($default_key, $_MODULES))
			$ret = Tools::stripslashes($_MODULES[$default_key]);
		else
			$ret = $string;

		return str_replace('"', '&quot;', $ret);
	}

	/**
	 * Существует ли файл перевода в кеше.
	 *
	 * @param string $filename
	 *
	 * @return bool
	 */
	private static function existsFileCache($filename)
	{
		if (!isset(self::$file_cache[$filename]))
			self::$file_cache[$filename] = file_exists($filename);

		return self::$file_cache[$filename];
	}

	/**
	 * Получить установленные и активные модули способов оплаты.
	 *
	 * @return array
	 */
	private static function getInstalledPaymentModules()
	{
		if (version_compare(_PS_VERSION_, '1.4.5', '>='))
			return PaymentModule::getInstalledPaymentModules();

		return Db::getInstance()->executeS('
			SELECT DISTINCT m.`id_module`, h.`id_hook`, m.`name`, hm.`position`
			FROM `'._DB_PREFIX_.'module` m
			LEFT JOIN `'._DB_PREFIX_.'hook_module` hm ON hm.`id_module` = m.`id_module`
			LEFT JOIN `'._DB_PREFIX_.'hook` h ON hm.`id_hook` = h.`id_hook`
			WHERE h.`name` = \'payment\'
			AND m.`active` = 1
		');
	}

	/**
	 * Получить идентификатор изображения комбинации.
	 *
	 * Нет адекватного метода для получения картинок комбинации, не считая
	 * $combination->getWsImages(), но она предназначена для REST и не присутствует
	 * в версиях ниже или равной 1.4.10, поэтому сделал свою, которая будет во всех версиях
	 * использоваться. Также, пока что необходимо получить только одну картинку
	 * (нет в ps_product_attribute_image соответствующего поля, отвечающего за
	 * картинку по умолчанию, поэтому нужно в выборке взять первую),
	 * чтобы ее отображать для определенной комбинации товара, например при поиске
	 * или в списке существующих товаров в заказе
	 *
	 * @param int $id_product_attribute
	 *
	 * @return int|false Идентификатор изображения или false, если нет изображения.
	 */
	private static function getCombinationFirstImageById($id_product_attribute)
	{
		$result = Db::getInstance()->ExecuteS('
			SELECT `id_image` as id
			FROM `'._DB_PREFIX_.'product_attribute_image`
			WHERE `id_product_attribute` = '.$id_product_attribute
		);

		return $result && $result[0]['id'] ? $result[0]['id'] : false;
	}

	/**
	 * @param Address $address
	 * @param array   $addressFormat
	 *
	 * @return array
	 */
	private function getFormattedAddressFieldsValues($address, &$addressFormat)
	{
		global $cookie;

		$tab = array();
		$temporyObject = array();

		// Check if $address exist and it's an instanciate object of Address
		if ($address && ($address instanceof Address)) {
			foreach ($addressFormat as $line) {
				if (($keyList = preg_split(_CLEANING_REGEX_, $line, -1, PREG_SPLIT_NO_EMPTY)) && is_array($keyList)) {
					foreach ($keyList as $pattern)
						if ($associateName = explode(':', $pattern)) {
							$totalName = count($associateName);
							if ($totalName == 1 && isset($address->{$associateName[0]}))
								$tab[$associateName[0]] = $address->{$associateName[0]};
							else {
								$tab[$pattern] = '';

								// Check if the property exist in both classes
								if (($totalName == 2) && class_exists($associateName[0]) &&
										Tools::property_exists($associateName[0], $associateName[1]) &&
										Tools::property_exists($address, 'id_' . Tools::strtolower($associateName[0]))) {
									$idFieldName = 'id_' . Tools::strtolower($associateName[0]);

									if (!isset($temporyObject[$associateName[0]]))
										$temporyObject[$associateName[0]] = new $associateName[0]($address->{$idFieldName});
									if ($temporyObject[$associateName[0]])
										$tab[$pattern] = (is_array($temporyObject[$associateName[0]]->{$associateName[1]})) ?
												((isset($temporyObject[$associateName[0]]->{$associateName[1]}[(isset($cookie) ? (int)$cookie->id_lang : Configuration::get('PS_LANG_DEFAULT'))])) ?
														$temporyObject[$associateName[0]]->{$associateName[1]}[(isset($cookie) ? (int)$cookie->id_lang : Configuration::get('PS_LANG_DEFAULT'))] : '') :
												$temporyObject[$associateName[0]]->{$associateName[1]};
								}
							}
						}
					$this->setOriginalDisplayFormat($tab, $line, $keyList);
				}
			}
		}

		self::cleanOrderedAddress($addressFormat);

		// Free the instanciate objects
		foreach ($temporyObject as &$object)
			unset($object);

		return $tab;
	}

	/**
	 * @param array  $formattedValueList
	 * @param string $currentLine
	 * @param array  $currentKeyList
	 */
	private function setOriginalDisplayFormat(&$formattedValueList, $currentLine, $currentKeyList)
	{
		if ($currentKeyList && is_array($currentKeyList))
			if ($originalFormattedPatternList = explode(' ', $currentLine))
			// Foreach the available pattern
				foreach ($originalFormattedPatternList as $patternNum => $pattern) {
					// Var allows to modify the good formatted key value when multiple key exist into the same pattern
					$mainFormattedKey = '';

					// Multiple key can be found in the same pattern
					foreach ($currentKeyList as $key) {
						// Check if we need to use an older modified pattern if a key has already be matched before
						$replacedValue = empty($mainFormattedKey) ? $pattern : $formattedValueList[$mainFormattedKey];
						if (($formattedValue = preg_replace('/' . $key . '/', $formattedValueList[$key], $replacedValue, -1, $count)))
							if ($count) {
								// Allow to check multiple key in the same pattern,
								if (empty($mainFormattedKey))
									$mainFormattedKey = $key;
								// Set the pattern value to an empty string if an older key has already been matched before
								if ($mainFormattedKey != $key)
									$formattedValueList[$key] = '';
								// Store the new pattern value
								$formattedValueList[$mainFormattedKey] = $formattedValue;
								unset($originalFormattedPatternList[$patternNum]);
							}
					}
				}
	}

	/**
	 * @param array $orderedAddressField
	 */
	private function cleanOrderedAddress(&$orderedAddressField)
	{
		foreach($orderedAddressField as &$line)
		{
			$cleanedLine = '';
			if (($keyList = preg_split(_CLEANING_REGEX_, $line, -1, PREG_SPLIT_NO_EMPTY)))
			{
				foreach($keyList as $key)
					$cleanedLine .= $key.' ';
				$cleanedLine = trim($cleanedLine);
				$line = $cleanedLine;
			}
		}
	}

	/**
	 * Сгенерировать адрес.
	 *
	 * Метод отсутсвует в версиях <= 1.4.1.0, поэтому он будет использоваться для ранних версий,
	 * в более старшых версиях это метод AddressFormat::generateAddress().
	 * Этот сделан на основе PS 1.4.8.2.
	 *
	 * @param Address $address
	 * @param array   $patternRules
	 * @param string  $newLine
	 * @param string  $separator
	 * @param array   $style
	 *
	 * @return string
	 */
	private function generateAddress(Address $address, $patternRules, $newLine = "\r\n", $separator = ' ', $style = array())
	{
		if (version_compare(_PS_VERSION_, '1.4.1.0', '>'))
			return AddressFormat::generateAddress($address, $patternRules, $newLine, $separator, $style);

		// Необходимые, используемые определения. Нужны также для методов используемых, в данном методе: cleanOrderedAddress()
		// и getFormattedAddressFieldsValues(); в текущем методе и во всех, зависимых от него. Код взят у PS 1.4.8.2.
		if (!defined('_CLEANING_REGEX_'))
			define('_CLEANING_REGEX_', '#([^\w:_]+)#i');

		if (!defined('PREG_SPLIT_NO_EMPTY'))
			define('PREG_SPLIT_NO_EMPTY', 1);

		$addressFields = AddressFormat::getOrderedAddressFields($address->id_country);
		$addressFormatedValues = $this->getFormattedAddressFieldsValues($address, $addressFields);

		$addressText = '';
		foreach ($addressFields as $line)
			if (($patternsList = preg_split(_CLEANING_REGEX_, $line, -1, PREG_SPLIT_NO_EMPTY)))
				{
					$tmpText = '';
					foreach($patternsList as $pattern)
						if (!in_array($pattern, $patternRules['avoid']))
							$tmpText .= (isset($addressFormatedValues[$pattern])) ?
								(((isset($style[$pattern])) ?
									(sprintf($style[$pattern], $addressFormatedValues[$pattern])) :
									$addressFormatedValues[$pattern]).$separator) : '';
					$tmpText = trim($tmpText);
					$addressText .= (!empty($tmpText)) ? $tmpText.$newLine: '';
				}

		return $addressText;
	}

	/**
	 * Проверить количество запаса товара для добавления в заказ.
	 *
	 * @param int       $qty_to_add           Количество для добавления - положительное целое число.
	 * @param Product   $product              Объект товара.
	 * @param int|bool  $id_product_attribute Код комбинации товара.
	 * @param bool      $check_minimal_qty    Проверять ли минимальное количество товара, которое позволяется добавлять в корзину/заказ.
	 *
	 * @return int Возвращает 0, если указанное количество $qty имеется на складе, иначе - код ошибки из [3].
	 */
	private static function checkProductQty($qty_to_add, $product, $id_product_attribute = false, $check_minimal_qty = true)
	{
		// код ошибки
		$err_code = 0;

		// если указан комбинационный товар
		if ($id_product_attribute)
		{
			if (!Attribute::checkAttributeQty($id_product_attribute, $qty_to_add))
				$err_code = 2;
			elseif ($check_minimal_qty && $qty_to_add < (int)Attribute::getAttributeMinimalQty($id_product_attribute))
				$err_code = 21;
		}
		// если обычный товар
		else
		{
			if (!$product->checkQty($qty_to_add))
				$err_code = 1;
			elseif ($check_minimal_qty && $qty_to_add < $product->minimal_quantity)
				$err_code = 21;
		}

		return $err_code;
	}

	/**
	 * Обновить количество товара в корзине (без поддержки катомных товаров, customization).
	 *
	 * Это незначительно переписанный метод Cart::updateQty(), чтобы была возможность обрабатывать неактивные товары.
	 *
	 * @param Cart      $cart                   Объект корзины.
	 * @param int       $quantity               Количество товара на которое нужно увеличить или уменьшить.
	 * @param int       $id_product             Код товара.
	 * @param int|null  $id_product_attribute   Код комбинации.
	 * @param string    $operator               Строка, указывающая на операцию: up (добавление) или down (уменьшение).
	 *
	 * @return bool|int возвращает true при успехе, иначе false или -1 (количество для добавления меньше, что минимальное допустимое)
	 */
	public static function updateCartQty($cart, $quantity, $id_product, $id_product_attribute = null, $operator = 'up')
	{
		$id_customization = false;

		/* Check if the product exists in Db and is available for order (+ handle product removal from cart) */
		if ($id_product > 0)
			$product = Db::getInstance()->getRow('
			SELECT id_product, available_for_order, minimal_quantity, customizable
			FROM '._DB_PREFIX_.'product
			WHERE id_product = '.(int)$id_product);

		if (!isset($product) || !$product)
			return false;

		if ((int)$quantity <= 0)
			return $cart->deleteProduct((int)$id_product, (int)$id_product_attribute, (int)$id_customization);
		elseif (!$product['available_for_order'] || Configuration::get('PS_CATALOG_MODE'))
			return false;

		/* Product is available for order, let's add it to the cart or update the existing quantities */
		else
		{
			if ($id_product_attribute)
			{
				$combination = new Combination((int)$id_product_attribute);
				if ($combination->id_product != $id_product)
					return false;
			}
			/* If we have a product combination, the minimal quantity is set with the one of this combination */
			$minimalQuantity = !empty($id_product_attribute) ? (int)Attribute::getAttributeMinimalQty((int)$id_product_attribute) : (int)$product['minimal_quantity'];
			/* Check if the product is already in the cart */
			$result = $cart->containsProduct((int)$id_product, (int)$id_product_attribute, (int)$id_customization);

			/* Update the current quantity if the product already exist in the cart */
			if ($result)
			{
				if ($operator == 'up')
				{
					/* We need to check if the product is in stock (or can be ordered without stock) */
					$result2 = Db::getInstance()->getRow('
					SELECT '.(!empty($id_product_attribute) ? 'pa' : 'p').'.`quantity`, p.`out_of_stock`
					FROM `'._DB_PREFIX_.'product` p
					'.(!empty($id_product_attribute) ? 'LEFT JOIN `'._DB_PREFIX_.'product_attribute` pa ON (p.`id_product` = pa.`id_product`)' : '').'
					WHERE p.`id_product` = '.(int)$id_product.
					(!empty($id_product_attribute) ? ' AND pa.`id_product_attribute` = '.(int)$id_product_attribute : ''));

					// количество товара, которое получится после операции
					$newQty = (int)$result['quantity'] + (int)$quantity;

					// количество товара, которое нужно прибавить (подставляется в update-запрос, поэтому ставится впереди знак)
					$qty = '+ '.(int)$quantity;

					/* If the total quantity asked is greater than the stock, we need to make sure that the product can be ordered without stock */
					if ($newQty > (int)$result2['quantity'] && !Product::isAvailableWhenOutOfStock((int)$result2['out_of_stock']))
						return false;
				}
				elseif ($operator == 'down')
				{
					$qty = '- '.(int)$quantity;
					$newQty = (int)$result['quantity'] - (int)$quantity;
				}
				else
					return false;

				/* If the new product quantity is lower or equal to zero, we can remove this product from the cart */
				if ($newQty <= 0)
					return $cart->deleteProduct((int)$id_product, (int)$id_product_attribute, (int)$id_customization);

				/* If the new product quantity does not match the minimal quantity to buy the product (default = 1), return -1 */
				elseif ($minimalQuantity > 1 && $newQty < $minimalQuantity)
					return -1;

				/* Otherwise, we are ready to update the current quantity of this product in the cart */
				else
					Db::getInstance()->Execute('
					UPDATE `'._DB_PREFIX_.'cart_product`
					SET `quantity` = `quantity` '.$qty.', `date_add` = NOW()
					WHERE `id_product` = '.(int)$id_product.
					(!empty($id_product_attribute) ? ' AND `id_product_attribute` = '.(int)$id_product_attribute : '').'
					AND `id_cart` = '.(int)$cart->id.'
					LIMIT 1');
			}

			/* Add the product to the cart */
			else
			{
				$result2 = Db::getInstance()->getRow('
				SELECT '.(!empty($id_product_attribute) ? 'pa' : 'p').'.`quantity`, p.`out_of_stock`
				FROM `'._DB_PREFIX_.'product` p
				'.(!empty($id_product_attribute) ? 'LEFT JOIN `'._DB_PREFIX_.'product_attribute` pa ON p.`id_product` = pa.`id_product`' : '').'
				WHERE p.`id_product` = '.(int)$id_product.
				(!empty($id_product_attribute) ? ' AND `id_product_attribute` = '.(int)$id_product_attribute : ''));

				/* If the quantity asked is greater than the stock, we need to make sure that the product can be ordered without stock */
				if ((int)$quantity > $result2['quantity'] && !Product::isAvailableWhenOutOfStock((int)$result2['out_of_stock']))
					return false;

				/* If the new product quantity does not match the minimal quantity to buy the product (default = 1), return -1 */
				if ($minimalQuantity > 1 && $quantity < $minimalQuantity)
					return -1;

				if (!Db::getInstance()->Execute('
				INSERT INTO '._DB_PREFIX_.'cart_product (id_product, id_product_attribute, id_cart, quantity, date_add) VALUES
				('.(int)$id_product.', '.($id_product_attribute ? (int)$id_product_attribute : 0).', '.(int)$cart->id.', '.(int)$quantity.', NOW())'))
					return false;
			}
		}

		/* refresh the cache of self::_products and update the cart */
		$cart->getProducts(true);
		$cart->update(true);

		return true;
	}

	/**
	 * Рассчитать скидку специальной цены.
	 * Рассчитывается, если задан первый параметр (процент скидки), иначе рассчитывается по второму (значение скидки).
	 *
	 * @param float $price              Цена, к которой применяется скидка.
	 * @param float $reduction_percent  Процент скидки.
	 * @param float $reduction_amount
	 *
	 * @return float вернет 0, если оба указанных параметра были меньше или ноль, иначе вернет скидку.
	 */
	private static function calcSpecificReduction($price, $reduction_percent, $reduction_amount)
	{
		if ($reduction_percent > 0)
			$result = $price * ($reduction_percent * 0.01);
		elseif ($reduction_amount > 0)
			$result = $reduction_amount;
		else
			$result = 0;

		return (float)$result;
	}

	/**
	 * Рассчитать груповую скидку.
	 *
	 * @param float $price               Цена товара, к которой применяется скидка (не обязательно должна быть начальной ценой товара - может уже включать какие-то скидки или налог).
	 * @param float $od_group_reduction  Процент груповой скидки из детелей заказа.
	 *
	 * @return float Вернет 0, если процент груповой скидки меньше или равен нулю, иначе - вернет рассчитаную цену с учетом указанной скидки.
	 */
	private static function calcGroupReduction($price, $od_group_reduction)
	{
		$result = 0;

		// @todo: нужно будет проверить и переписать алгоритм, т.к.:
		//   - в 1.4.4.0 групповая скидка уже включена в product_price, а специальная скидка считается отдельно
		//   - в 1.4.9.0 и старше в product_price не входят никакие скидки
		//   - в промежуточных версиях не известно
		if ($od_group_reduction)
		{
			if (version_compare(_PS_VERSION_, '1.4.9.0', '>='))
				$result = $price * ($od_group_reduction * 0.01);
		}

		return (float)$result;
	}

	/**
	 * Обновляет цены в заказе.
	 *
	 * @param array    $products               Массив товаров заказа, переданный по ссылке.
	 * @param Order    $order                  Заказ, переданный по ссылке.
	 * @param Cart     $cart                   Корзина заказа.
	 * @param Carrier  $carrier                Способ доставки.
	 * @param array    $order_total_calc_type  Массив типов расчетов цен.
	 * @param boolean  $save_order             Если true, то сохранить объект заказа (по умолчанию).
	 *
	 * @return boolean возвращает true при успешном сохранении
	 */
	private function calcPrices(&$products, &$order, $cart, $carrier, $order_total_calc_type, $save_order = true)
	{
		// общая стоимость заказа, которую должен оплатить клиент
		$total_paid = 0;

		// total_products - это сумма цен товаров, при этом, каждая цена товара не включает налоги, но включает скидки
		// встроенный метод Order::getTotalProductsWithoutTaxes() ничего не считает, а только возвращает свойство total_products,
		// а нам нужно посчитать цену и записать в это свойство, поэтому расчитываем самостоятельно
		$total_products = 0;

		// total_products_wt - тоже самое, что total_products, но с налогом
		// специальный метод Order::getTotalProductsWithTaxes() не стал использовать для ускорения работы и большей понятности
		$total_products_wt = 0;

		// рассчитываем их
		foreach ($products as &$row)
		{
			// исходная цена товара (без налога и скидок)
			$price = $row['product_price'];

			// рассчитываем скидки - групповую и специальную (для товара, не включающего налог)
			$reductions = self::calcSpecificReduction($price, $row['reduction_percent'], $row['reduction_amount']);
			$reductions += self::calcGroupReduction($price, $row['group_reduction']);

			// итого по всем товарам включая скидки и исключая налоги
			$total_products += ($price - $reductions) * $row['product_quantity'];

			// далее, почти тоже самое, но рассчитываем цены с учетом налогов
			// последовательно меняем переменную $price, при этом последовательность важна, иначе будут расхождения с
			// данными стандартного редактора - см. Order::getTotalProductsWithTaxes()
			$price = $price * (1 + $row['tax_rate'] / 100);
			$price -= self::calcSpecificReduction($price, $row['reduction_percent'], $row['reduction_amount']);
			$price -= self::calcGroupReduction($price, $row['group_reduction']);
			$price += $row['ecotax'] * (1 + $row['ecotax_tax_rate'] / 100);

			// цена одного товара
			$row['product_price_wt'] = Tools::ps_round($price, 2);

			// итого по одному товару
			$row['total_wt'] = Tools::ps_round($row['product_price_wt'] * $row['product_quantity'], 2);

			// итого по всем товарам включая налоги и скидки
			$total_products_wt += $row['total_wt'];
		}

		// нельзя считать цены на товары в заказе, используя методы в классе Cart, так как
		// они для расчета цен во время оформления заказа - со временем цены и другие параметры товаров могу измениться,
		// а прежние цены товаров (заказываемых ранее) корзина не хранит, а хранит таблица order_detail.
		// эти методы здесь оставлены на тот случай, если они понадобятся в новых версиях модуля - например, при создании
		// нового заказа через модуль
		//$order->total_products = $cart->getOrderTotal(false, version_compare(_PS_VERSION_, '1.4.0.1', '>') ? Cart::ONLY_PRODUCTS : self::$order_total_calc_type['ONLY_PRODUCTS']);
		//$order->total_products_wt = $cart->getOrderTotal(true, version_compare(_PS_VERSION_, '1.4.0.1', '>') ? Cart::ONLY_PRODUCTS : self::$order_total_calc_type['ONLY_PRODUCTS']);

		$order->total_products = Tools::ps_round($total_products, 2);
		$order->total_products_wt = Tools::ps_round($total_products_wt, 2);

		// сами скидки, примененных к корзине нам не нужны, но необходимо обновление кеша (второй параметр)
		$cart->getDiscounts(false, true);

		// общая скидка на товары заказа (на скидки налогов нет, просто первый параметр обязательный)
		$order->total_discounts = abs($cart->getOrderTotal(true, $order_total_calc_type['ONLY_DISCOUNTS']));

		// цена за подарочную упаковку
		if ($order->gift)
		{
			// узнаем, есть ли налог на подарочную упаковку. в конфигурации будет 0,
			// если налога нет на нее
			$wrapping_tax_include = Configuration::get('PS_GIFT_WRAPPING_TAX') ? true : false;

			// запишем цену за подарочную упаковку
			$order->total_wrapping = abs($cart->getOrderTotal($wrapping_tax_include, $order_total_calc_type['ONLY_WRAPPING']));
			$total_paid += $order->total_wrapping;
		}

		// стоимость доставки (total_shipping) перед этим не перерасчитываем по правилам корзины, т.к. пользователь имеет возможность задать
		// свою собственную стоимость
		$total_paid += $order->total_products_wt + $order->total_shipping;

		// проверим, если у в заказе нет товаров, значит скидка не может действовать,
		// в другом случае мы получим отрецательный total_paid, чего не может быть
		if ($total_paid >= $order->total_discounts)
			$total_paid -= $order->total_discounts;

		$order->total_paid = Tools::ps_round($total_paid, 2);

		return $save_order ? $order->save() : true;
	}

	/**
	 * Сгенерировать массив адресов клиента в формате array('id'=> int, 'addr'=> string).
	 *
	 * @param array   $customer_addresses
	 * @param Address $address_delivery
	 * @param Address $address_invoice
	 *
	 * @return array
	 */
	private function generateFormatedAddresses($customer_addresses, $address_delivery, $address_invoice)
	{
		global $cookie;

		$formated_addresses = array();
		foreach($customer_addresses as $a)
		{
			// для адресов доставки товара и счета нет необходимости создавать
			// объекты, так как они уже есть
			if($a['id_address'] == $address_delivery->id)
				$addr_tmp = $address_delivery;
			elseif($a['id_address'] == $address_invoice->id)
				$addr_tmp = $address_invoice;
			else
				$addr_tmp = new Address($a['id_address'], (int)$cookie->id_lang);

			// сформируем адрес в специализированном формате страны.
			// параметр array('avoid' => array('')) говорит о том, что нужно сформировать
			// адрес по всем данным; например параметр array('avoid' => array('lastname', 'firstname'))
			// говорит о том, что нужно пропустить имя и фамилию адресата
			$formated_tmp = $addr_tmp->alias.' - '.$this->generateAddress($addr_tmp, array('avoid' => array('')), ', ');
			$formated_tmp[Tools::strlen($formated_tmp)-2] = "\0";
			$formated_addresses[] = array('id' => $addr_tmp->id, 'addr' => $formated_tmp);
		}

		return $formated_addresses;
	}

	/**
	 * Получить данные о ЧПУ товара.
	 *
	 * Метод отсутсвует в версиях <= 1.4.1.0, поэтому его нужно добавить для
	 * использвания в методе getProductsProperties(). Перенесен с версии 1.4.8.2,
	 * в которой объявлен как Product::getUrlRewriteInformations().
	 *
	 * @param int $id_product
	 *
	 * @return array
	 */
	private static function getUrlRewriteInformations($id_product)
	{
		if (version_compare(_PS_VERSION_, '1.4.1.0', '>'))
			return Product::getUrlRewriteInformations((int)$id_product);
		else
			return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
				SELECT pl.`id_lang`, pl.`link_rewrite`, p.`ean13`, cl.`link_rewrite` AS category_rewrite
				FROM `'._DB_PREFIX_.'product` p
				LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON (p.`id_product` = pl.`id_product`)
				LEFT JOIN `'._DB_PREFIX_.'lang` l ON (pl.`id_lang` = l.`id_lang`)
				LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (cl.`id_category` = p.`id_category_default`  AND cl.`id_lang` = pl.`id_lang`)
				WHERE p.`id_product` = '.(int)$id_product.'	AND l.`active` = 1'
			);
	}

	/**
	 * Добавление поелей id_image и link_rewrite для каждого товара из массива Products.
	 * К сожалению, Order->getProducts() возвращает массив товаров без этих полей;
	 * эти поля нужны для получения uri картинки товара при вызове getImageLink();
	 * для PS 1.4 достаточно одного id_image, при этом вызов метода для получения
	 * uri картинки выглядит так: getImageLink(false, id_image, 'small');
	 *
	 * @todo: возможно стоит переписать метод Product::getProductsProperties($cookie->id_lang, $products) и использовать его вместо этого алгоритма (название своего метода сделал специально таким же).

	 * @param array $query_result
	 */
	private function getProductsProperties(&$query_result)
	{
		global $cookie;

		$id_lang = (int)$cookie->id_lang;

		foreach ($query_result as $k => &$p)
		{
			// получим информацию по UrlRewrite и выделим из нее link_rewrite товара,
			// и присвоим новому полю. проверим так же на ретро-совместимость
			$rewrite_info = self::getUrlRewriteInformations($p['product_id']);
			$link_rewrite = '';
			foreach ($rewrite_info as $l)
			{
				if ($l['id_lang'] == $id_lang)
				{
					$link_rewrite = $l['link_rewrite'];
					break;
				}
			}

			if (!$link_rewrite)
				$link_rewrite = Language::getIsoById($id_lang).'-default';

			$p['link_rewrite'] = $link_rewrite;

			// получим id_image для товара с комбинацией
			$product_attribute_id = $p['product_attribute_id'];
			if ($product_attribute_id)
				$id_image = self::getCombinationFirstImageById($product_attribute_id);

			// получим id_image для товара без комбинации или для товара с
			// комбинацией, если id_image для нее не получилось найти - могут
			// картинки быть, но просто ни для одной комбинации не заданы эти картинки
			if (!$product_attribute_id || !$id_image)
			{
				$cover = Product::getCover($p['product_id']);
				$id_image = $cover['id_image'];
			}

			$p['id_image'] = $id_image;
		}
	}

	/**
	 * Формирует и возвращает массив атрибутов и их группы.
	 *
	 * @param int $id_product
	 * @param int $id_currency
	 *
	 * @return array
	 */
	private function getAttrByProductId($id_product, $id_currency)
	{
		global $cookie;

		$sql = '
			SELECT pai.id_image as attribute_image, pl.`name` as product_name, pl.`available_now`, pl.`available_later`, p.`weight` as `product_weight`, p.`out_of_stock`, p.`reference` as `product_reference`, pa.*, ag.`id_attribute_group`, ag.`is_color_group`, agl.`name` AS group_name, al.`name` AS attribute_name, a.`id_attribute` as color_attribute_id, a.`color` as color_val
			FROM `'._DB_PREFIX_.'product_attribute` pa
			LEFT JOIN `'._DB_PREFIX_.'product_attribute_combination` pac ON (pac.`id_product_attribute` = pa.`id_product_attribute`)
			LEFT JOIN `'._DB_PREFIX_.'attribute` a ON (a.`id_attribute` = pac.`id_attribute`)
			LEFT JOIN `'._DB_PREFIX_.'attribute_group` ag ON ag.`id_attribute_group` = a.`id_attribute_group`
			LEFT JOIN `'._DB_PREFIX_.'attribute_lang` al ON (a.`id_attribute` = al.`id_attribute` AND al.`id_lang` = '.(int)$cookie->id_lang.')
			LEFT JOIN `'._DB_PREFIX_.'attribute_group_lang` agl ON (ag.`id_attribute_group` = agl.`id_attribute_group` AND agl.`id_lang` = '.(int)$cookie->id_lang.')
			LEFT JOIN `'._DB_PREFIX_.'product` p ON (pa.`id_product` = p.`id_product`)
			LEFT JOIN `'._DB_PREFIX_.'product_attribute_image` pai ON (pai.`id_product_attribute` = pa.`id_product_attribute`)
			LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON (pa.`id_product` = pl.`id_product` AND pl.`id_lang` = '.(int)$cookie->id_lang.')
			WHERE pa.`id_product` = '.(int)$id_product.' ORDER BY pa.`id_product_attribute`
		';

		$comb = Db::getInstance()->executeS($sql, true, 0);
		$currency = new Currency($id_currency);

		$attr_vals = array();
		$attr_groups = array();
		if (count($comb))
		{
			foreach ($comb as $c)
			{
				$attr_vals[$c['id_product_attribute']]['id_product'] = (int)$c['id_product'];
				$attr_vals[$c['id_product_attribute']][$c['group_name']] = $c['attribute_name'];
				$attr_vals[$c['id_product_attribute']]['price'] = Tools::displayPrice(Product::getPriceStatic($c['id_product'], true, $c['id_product_attribute']), $currency, false);
				$attr_vals[$c['id_product_attribute']]['old_price'] = Tools::displayPrice(Product::getPriceStatic($c['id_product'], true, $c['id_product_attribute'], 6, null, false, false), $currency, false);
				$attr_vals[$c['id_product_attribute']]['comb_reference'] = $c['reference'];
				$attr_vals[$c['id_product_attribute']]['name'] = $c['product_name'];
				$attr_vals[$c['id_product_attribute']]['avail'] = ((int)$c['quantity'] == 0 ? false : true);
				$attr_vals[$c['id_product_attribute']]['quantity'] = (int)$c['quantity'];
				$attr_vals[$c['id_product_attribute']]['available_now'] = $c['available_now'];
				$attr_vals[$c['id_product_attribute']]['available_later'] = $c['available_later'];
				$attr_vals[$c['id_product_attribute']]['allow_oosp'] = Product::isAvailableWhenOutOfStock((int)$c['out_of_stock']);
				$attr_vals[$c['id_product_attribute']]['weight'] = ($c['product_weight'] + $c['weight']).' '.Configuration::get('PS_WEIGHT_UNIT');
				$attr_vals[$c['id_product_attribute']]['id_attribute_group'] = $c['id_attribute_group'];
				$attr_vals[$c['id_product_attribute']]['group_name'] = $c['group_name'];
				if ((int)$c['is_color_group'])
				{
					$attr_vals[$c['id_product_attribute']]['color_val'] = $c['color_val'];
					$attr_vals[$c['id_product_attribute']]['color_attribute_id'] = $c['color_attribute_id'];
				}
				$attr_vals[$c['id_product_attribute']]['attribute_image'] = $c['attribute_image'];
				$attr_groups[$c['group_name']] = (int)$c['is_color_group'];
			}

			ksort($attr_groups);
		}

		$result = array(
			'attr_vals' => $attr_vals,
			'attr_groups' => $attr_groups,
		);

		return $result;
	}

	/**
	 * Удаляет ваучер из таблицы скидок заказа и из таблицы скидок корзины.
	 * Общая сумма заказа ($order->total_paid) после удаления ваучера не перерасчитывается.
	 *
	 * @param int   $id_discount ID ваучера
	 * @param Cart  $cart корзина
	 * @param Order $order заказ
	 */
	private static function deleteDiscount($id_discount, $cart, $order)
	{
		$cart->deleteDiscount($id_discount);
		Db::getInstance()->execute('DELETE FROM `'._DB_PREFIX_.'order_discount` WHERE `id_discount` = '.(int)$id_discount.' AND `id_order` = '.(int)$order->id.' LIMIT 1');

		// обновляем кеш скидок в корзине
		$cart->getDiscounts(false, true);
	}

	/**
	 * Добавить ваучер в таблицу скидок заказа и в таблицу скидок корзины.
	 * Общая сумма заказа ($order->total_paid) после добавления ваучера не перерасчитывается.
	 *
	 * @param int   $id_discount    ID ваучера.
	 * @param int   $id_lang        ID языка.
	 * @param Cart  $cart           Корзина.
	 * @param Order $order          Заказ.
	 *
	 * @return string|boolean вернет true при успехе или строку с сообщением об ошибке.
	 */
	private function addDiscount($id_discount, $id_lang, $cart, $order)
	{
		$discount = new Discount($id_discount, (int)$id_lang);

		// валидация - метод вернет сообщение об ошибке или false, если скидку можно применить
		$check_msg = $cart->checkDiscountValidity($discount, $cart->getDiscounts(false, true), $cart->getOrderTotal(true, self::$order_total_calc_type['ONLY_PRODUCTS']), $cart->getProducts(), true);

		if ($check_msg !== false)
			return $check_msg;
		else
		{
			$cart->addDiscount($id_discount);
			$order->addDiscount($id_discount, $discount->name, $discount->value);

			// обновляем кеш скидок в корзине
			$cart->getDiscounts(false, true);

			return true;
		}
	}

	/**
	 * Метод, как таковой, валидации не производит, лишь получает статус операции и формирует сообщение.
	 */
	private function _postValidation()
	{
		// [3] st указывает на статус операции: 0 - операция прошла успешно,
		// больше 1 - была ошибка с кодом этого числа, NULL - нет сообщения (не было операции)
		$errCode = Tools::getValue('st');
		if ($errCode)
		{
			switch ((int)$errCode)
			{
				case 1: $this->_postErrors[] = $this->l('Not enough product quantity to add to the order.');
						break;
				case 2: $this->_postErrors[] = $this->l('Not enough product combination quantity to add to the order.');
						break;
				case 3: $this->_postErrors[] = $this->l('An error occurred when adding the product to the order.');
						break;
				case 4: $this->_postErrors[] = $this->l('Product quantity to delete must be less than product quantity in all.');
						break;
				case 5: $this->_postErrors[] = $this->l('An error occurred when deleting the product from the order.');
						break;
				case 6: $this->_postErrors[] = $this->l('An error occurred when saving the product.');
						break;
				case 7: $this->_postErrors[] = $this->l('An error occurred when saving the shipping information.');
						break;
				case 8: $this->_postErrors[] = $this->l('An error occurred when appling the address.');
						break;
				case 9: $this->_postErrors[] = $this->l('An error occurred when saving the order.');
						break;
				case 10: $this->_postErrors[] = $this->l('An error occurred when adding the payment.');
						break;
				case 11: $this->_postErrors[] = $this->l('An error occurred when deleting the payment.');
						break;
				case 12: $this->_postErrors[] = $this->l('An error occurred while changing the status or was unable to send e-mail to the customer.');
						break;
				case 13: $this->_postErrors[] = $this->l('An error occurred while marking the customer\'s message as viewed.');
						break;
				case 14: $this->_postErrors[] = $this->l('An error occurred while sending message.');
						break;
				case 15: $this->_postErrors[] = $this->l('An error occurred while sending e-mail to customer.');
						break;
				case 16: $this->_postErrors[] = $this->l('An error occurred when saving the cart.');
						break;
				case 17: $this->_postErrors[] = $this->l('An error occurred when deleting the voucher.');
						break;
				case 18: $this->_postErrors[] = $this->l('An error occurred when adding the voucher.');
						break;
				case 19: $this->_postErrors[] = $this->l('An error occurred when changing the voucher.');
						break;
				case 20: $this->_postErrors[] = $this->l('The amount like other price value must be greater or equal zero');
						break;
				case 21: $this->_postErrors[] = $this->l('Not enough product quantity to add because it is not corresponds with minimal quantity');
						break;
			}
		}
	}

	/**
	 * Метод возвращает, сформированные в нужном виде url, параметры, которые передаются в виде массива.
	 * Параметр может передаваться без значения, поэтому для него необходимо задавать значение NULL.
	 * Пример array('id_product' => $p['id_product'], 'updateproduct' => NULL).
	 *
	 * @param array $get_vars
	 *
	 * @return string
	 */
	private function buildUrlParams($get_vars)
	{
		$params = '';
		foreach ($get_vars as $k => $v)
			$params .= '&'.$k.($v === NULL ? '' : '='.$v);

		return $params;
	}

	/**
	 * Возвращает url указанного таба с параметрами; $get_vars - массив параметров.
	 * Параметр может передаваться без значения, поэтому для него необходимо задавать значение NULL.
	 * Пример вызова: $this->getAdminTabUrl('AdminCatalog', array('id_product' => $p['id_product'], 'updateproduct' => NULL)).
	 *
	 * @param string $tab_name
	 * @param array  $get_vars
	 *
	 * @return string
	 */
	private function getAdminTabUrl($tab_name, $get_vars)
	{
		global $cookie;
		return __PS_BASE_URI__.Tools::substr($_SERVER['SCRIPT_NAME'], Tools::strlen(__PS_BASE_URI__)).'?tab='.$tab_name.$this->buildUrlParams($get_vars).'&token='.Tools::getAdminTokenLite($tab_name).'&id_lang='.(int)$cookie->id_lang;
	}

	/**
	 * Возвращает url указанного таба модуля OrderzAdmin с параметрами; $get_vars - массив параметров.
	 *
	 * Массив параметров $get_vars может быть false (по умолчанию), при этом генерируется ссылка на список заказов, а для
	 * страницы редактирования заказа необходимо добавить в массив два параметра: array('updateorder' => NULL, 'id_order'=>ID_ORDER).
	 * Параметр в массиве может быть без значения, поэтому для него необходимо задавать значение NULL.
	 * Пример вызова: $this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order'=>$order->id))
	 *
	 * @param array|bool $get_vars
	 *
	 * @return string
	 */
	private function getOrderzAdminUrl($get_vars = false)
	{
		global $cookie, $currentIndex;

		return $this->getShopDomain(true).$currentIndex.($get_vars ? $this->buildUrlParams($get_vars) : '').'&token='.$this->token.'&id_lang='.(int)$cookie->id_lang;
	}

	/**
	 * Формирует url возврата на страницу с которой происходит переход на таба $tab_name.
	 *
	 * При возврате с таба $tab_name в url-параметрах передается &conf со значением результата.
	 * Выполнение операции, например при успешном обновлении данных на $tab_name, будет значение &conf=4.
	 * Параметр $order_id необходим для возврата на страницу редактирования заказа с которой был осуществел переход.
	 *
	 * @param string $tab_name
	 * @param int    $order_id
	 *
	 * @return string
	 */
	private function getBackUrlToOrderzAdmin($tab_name, $order_id)
	{
		global $cookie;
		return $this->buildUrlParams(array ('back' => urlencode($this->getOrderzAdminUrl(array ('updateorder' => null, 'id_order' => $order_id))), 'token' => Tools::getAdminToken($tab_name.Tab::getIdFromClassName($tab_name).(int)$cookie->id_employee)));
	}

	/**
	 * Возвращает ссылку на сайт сервиса с такимим параметрами, чтобы сделать поиск объекта на карте.
	 *
	 * @param string  $sevice_name
	 * @param string  $sevice_url
	 * @param array   $get_vars
	 * @param string  $search_param
	 * @param string  $img_name
	 * @param Address $addressObj
	 * @param State   $stateObj
	 *
	 * @return string
	 */
	private function getMapUrl($sevice_name, $sevice_url, $get_vars, $search_param, $img_name, $addressObj, $stateObj)
	{
		$addr = $addressObj->address1.' '.$addressObj->postcode.' '.$addressObj->city.' '.(Validate::isLoadedObject($stateObj) ? $stateObj->name : '');
		$get_vars[$search_param] = $addr;
		$params = $this->buildUrlParams($get_vars);

		return '<a href="'.$sevice_url.$params.'" target="_blank"><img src="../img/admin/'.$img_name.'" title="'.$this->l('See address at '.$sevice_name).'" class="middle" /></a>';
	}

	/**
	 * Формирование и отображение страницы таблицы заказов или редактора выбранного заказа.
	 */
	public function display()
	{
		global $cookie;

		// начало заголовка страницы редактирования заказа. конец формирования заголовка здесь [2]
		$html = '<h2>' . $this->l('Orders Editor');

		// если не нажата кнопка редактирования заказа в таблице заказов: Orders Editor / Orders list
		// то вывести эту таблицу
		if (!Tools::getIsset('updateorder'))
		{
			$html .= ' / '.$this->l('Orders list').'</h2>';
			echo $html;

			$this->getList((int)$cookie->id_lang, !Tools::getValue($this->table.'Orderby') ? 'date_add' : null, !Tools::getValue($this->table.'Orderway') ? 'DESC' : null);
			$this->displayList();
		}
		// нажата кнопка редактирования заказа
		else
		{
			// объявляем основные объекты для работы над заказом

			// массив id полей, для которых необходимо добавить виджет для выбора даты
			$DatePickerArray = array();

			$order = parent::loadObject();

			// массив с детальным описанием скидочных купонов, примененных к заказу (именно к заказу, а не к корзине)
			$order_discounts = $order->getDiscounts(true);

			$cart = Cart::getCartByOrderId($order->id);

			$carrier = new Carrier($order->id_carrier);

			// получим массив товаров заказа
			$products = $order->getProductsDetail();

			// добавим в него необходимые параметры для каждого товара
			$this->getProductsProperties($products);

			//@todo: включить кастомные данные, когда они потребуются (в 1.4.11.0 появились нотисы из класса Product)
			//$customizedDatas = Product::getAllCustomizedDatas((int)$order->id_cart);
			//Product::addCustomizationPrice($products, $customizedDatas);

			// обновляем цены в заказе и массиве товаров, а измененный объект заказа не сохраняем (последний параметр), т.к. в этом месте
			// вызов метода присутствует лишь для того, чтобы обновлить цены в переменных для отображения на странице, а сохранение самого
			// заказа происходит раньше - при post-запросах форм, после которых производится редирект; если это не делать, то из-за редиректа
			// во первых, производился бы расчет и сохранение повторно, а во вторных, что более кретично - изменение заказа лишь только при
			// его просмотре (например, это особенно критично для старых заказов с прежними ценовыми правилами)
			$this->calcPrices($products, $order, $cart, $carrier, self::$order_total_calc_type, false);

			$iso_code = Language::getIsoById((int)$cookie->id_lang);
			$customer = new Customer($order->id_customer);
			$currentState = OrderHistory::getLastOrderState($order->id);
			$currency = new Currency($order->id_currency);
			$link = new Link();

			// вся история статусов заказа
			$history = $order->getHistory((int)$cookie->id_lang);

			// ткущий статус заказа
			$currentStateTab = $order->getCurrentStateFull((int)$cookie->id_lang);

			// список доступных статусов заказа
			$states = OrderState::getOrderStates((int)$cookie->id_lang);

			// шаблоны сообщений для заказов
			$orderMessages = OrderMessage::getOrderMessages((int)($order->id_lang));

			// сообщения или заметки по заказу
			$messages = Message::getMessagesByOrderId($order->id, true);

			// суммарная стоимость товаров включая налог
			$totalProductsWithTaxes = Tools::displayPrice($order->total_products_wt, $currency, false);

			// значение уже с налогом, нужно только приписать знак валюты
			$totalWrapping = Tools::displayPrice($order->total_wrapping, $currency, false);

			// значение уже с налогом, нужно только приписать знак валюты
			$totalShipping = Tools::displayPrice($order->total_shipping, $currency, false);

			// значение уже с налогом, нужно только приписать знак валюты
			$totalToPay = Tools::displayPrice($order->total_paid, $currency, false);

			// значение уже с налогом, нужно только приписать знак валюты
			$paidByCustomer = Tools::displayPrice($order->total_paid_real, $currency, false);

			// адрес доставки
			$addressDelivery = new Address($order->id_address_delivery, (int)$cookie->id_lang);
			$deliveryState = null;
			if (Validate::isLoadedObject($addressDelivery) && $addressDelivery->id_state) {
				$deliveryState = new State($addressDelivery->id_state);
			}

			// адрес счета
			$addressInvoice = new Address($order->id_address_invoice, (int)$cookie->id_lang);
			$invoiceState = null;
			if (Validate::isLoadedObject($addressInvoice) && $addressInvoice->id_state) {
				$invoiceState = new State($addressInvoice->id_state);
			}

			// сформируем массив всех адресов клиента; массив необходим для формирования
			// списка выбора адресов для изменения адреса доставки или адреса счета
			$customer_addresses = $customer->getAddresses((int)$cookie->id_lang);
			$formated_addresses = $this->generateFormatedAddresses($customer_addresses, $addressDelivery, $addressInvoice);

////////////////////////////////// Post process ///////////////////////////////
			// добавление товара в заказ
			if (Tools::isSubmit('addProduct'))
			{
				$p = new Product((int)Tools::getValue('id_product'));

				// количество товара для добавления; если по ошибке передался 0, то ставим 1;
				// это может произойти, если пользователь очистил поле количества товара
				$productQtyToAdd = (int)(Tools::getvalue('product_qtw') ? Tools::getvalue('product_qtw') : 1);

				$id_country = $customer->getCurrentCountry($customer->id);
				$od = new OrderDetail();

				$od->id_order = $order->id;
				$od->product_id = $p->id;

				// значения id_product_attribute(id) и product_name (перечень атрибутов комбинации) получаем из post-запроса;
				// сделал для удобства и простоты - нужно только распарсить;
				// базовое название товара (без перечисления атрибутов) получим из свойства $p->name, в котором хратинся массив названий для разных языков
				$product_name = $p->name[(int)$cookie->id_lang];
				if(Tools::getValue('id_product_attribute')) {
					$comb = explode('|', Tools::getvalue('id_product_attribute'));
					$id_product_attribute = (int)$comb[0];
					$product_name .= ' - ' . $comb[1];
				}
				else {
					// товар без комбинации
					$id_product_attribute = false;
				}
				$od->product_attribute_id = $id_product_attribute;
				$od->product_name = $product_name;

				// проверим, доступно ли необходимое количество товара на складе, чтобы добавить в заказ
				$errCode = self::checkProductQty($productQtyToAdd, $p, $id_product_attribute);
				if($errCode > 0)
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));

				// затем присвоим свойству объекта новое количество
				$od->product_quantity = $productQtyToAdd;

				// не до конца понятное поле product_quantity_in_stock, но по всей видимости хранится число заказаного товара или максимально
				// доступное для заказа количество; вариан реализации взял здесь: PaymentModule - строка 188;
				// сам метод Product::getQuantity() возвращает общее количество товара (с учетом количества комбинаций)
				$productQuantity = Product::getQuantity($p->id, $id_product_attribute);
				$od->product_quantity_in_stock = (($productQuantity - $productQtyToAdd < 0) ? $productQuantity : $productQtyToAdd);

				// не до конца понятное поле - оно связано с возвратом товара, хотя уже есть поле
				// product_quantity_refunded и туда похоже записывается результат возрата.
				$od->product_quantity_return = 0;

				$od->product_quantity_refunded = 0;

				// не до конца понятное поле - оно связано с возвратом товара, хотя уже есть поле
				// product_quantity_refunded и туда похоже записывается результат возрата.
				$od->product_quantity_reinjected = 0;

				// базовая цена единицы товара; взял пример из PaymentModule - строка 260;
				// метод Product::getPriceStatic() заполняет $specificPrice специальными ценами, поэтому их рассчитывать предварительно не нужно
				$specificPrice = null;
				$od->product_price = Product::getPriceStatic($p->id, false, $id_product_attribute, (Product::getTaxCalculationMethod($order->id_customer) == PS_TAX_EXC ? 2 : 6), NULL, false, false, $productQtyToAdd, false, $order->id_customer, $order->id_cart, $order->id_address_delivery, $specificPrice, false, false);
				$od->reduction_percent = (float)(($specificPrice AND $specificPrice['reduction_type'] == 'percentage') ? $specificPrice['reduction'] * 100 : 0.00);
				$od->reduction_amount = (float)(($specificPrice AND $specificPrice['reduction_type'] == 'amount') ? (!$specificPrice['id_currency'] ? Tools::convertPrice($specificPrice['reduction'], $order->id_currency) : $specificPrice['reduction']) : 0.00);

				// по всей видимости, применяется групповая скидка для клиента, которая назначена для него по-умолчанию,
				// даже если он состоит в группе с наибольшей скидкой, все равно применяется, которая по-умолчанию;
				// это показал экперимент по покупке товара клиентом состоящим в двух группах;
				// пока что следуем этому же правилу;
				// товар может относится к нескольким категориям, но скидка назначается только на категорию по-умолчанию;
				// учтем, что груповая скидка на категорию, к которой относится добавляемый товар не суммируется с груповой и переопределяет ее
				$product_reduct = GroupReduction::getValueForProduct($p->id, $customer->id_default_group) * 100;
				$group_reduction = Group::getReductionByIdGroup($customer->id_default_group);
				$od->group_reduction = $product_reduct ? $product_reduct : $group_reduction;

				if (Tax::excludeTaxeOption())
				{
					$tax_rate = 0;
				}
				else {
					$tax_rate = Tax::getProductTaxRate($p->id, $order->id_address_delivery);
				}

				// расчет скидки по количеству товара
				$quantityDiscount = SpecificPrice::getQuantityDiscount($p->id, Shop::getCurrentShop(), $order->id_currency, $id_country, $customer->id_default_group, $productQtyToAdd);
				$unitPrice = Product::getPriceStatic($p->id, true, $id_product_attribute, 2, NULL, false, true, 1, false, $order->id_customer, NULL, $order->id_address_delivery);
				$quantityDiscountValue = $quantityDiscount ? ((Product::getTaxCalculationMethod($order->id_customer) == PS_TAX_EXC ? Tools::ps_round($unitPrice, 2) : $unitPrice) - $quantityDiscount['price'] * (1 + $tax_rate / 100)) : 0.00;
				$od->product_quantity_discount = $quantityDiscountValue;

				$combination = new Combination($id_product_attribute);
				$od->product_ean13 = $p->ean13;
				$od->product_upc = $p->upc;
				$od->product_reference = $combination->reference ? $combination->reference : $p->reference;
				$od->product_supplier_reference = $combination->supplier_reference ? $combination->supplier_reference : $p->supplier_reference;
				$od->product_weight = $p->weight;
				$od->tax_rate = $tax_rate;
				$od->ecotax = Tools::convertPrice($p->ecotax, $order->id_currency);
				$od->ecotax_tax_rate = Tax::getProductEcotaxRate($order->id_address_delivery);

				// флаг, что применяется скидка, пример из PaymentModule.php - строка 289
				$od->discount_quantity_applied = ($specificPrice AND $specificPrice['from_quantity'] > 1) ? 1 : 0;

				// подготовим данные для записи значения свойства download_hash
				$id_product_download = ProductDownload::getIdFromIdProduct($p->id);
				if ($id_product_download)
				{
					$product_download = new ProductDownload($id_product_download);
					$download_deadline = $product_download->getDeadLine();
					$download_hash = $product_download->getHash();
				}
				else
				{
					$download_deadline = '0000-00-00 00:00:00';
					$download_hash = null;
				}

				$od->download_hash = $download_hash;
				$od->download_nb = 0;
				$od->download_deadline = $download_deadline;

				$errCode = $od->save() ? 0 : 3;
				if($errCode) {
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
				}

				// сначала изменяем количество товара в корзине, если товар отсутствует в корзине, то этот метод его добавит
				$errCode = self::updateCartQty($cart, $productQtyToAdd, $p->id, $id_product_attribute) === true ? 0 : 16;
				if($errCode)
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));

				// потом забираем со склада соответствующее количество товара
				$p->updateQuantity(array('id_product'=>$p->id, 'cart_quantity'=>$productQtyToAdd, 'id_product_attribute'=>$id_product_attribute), $order->id);

				$errCode = $this->calcPrices($products, $order, $cart, $carrier, self::$order_total_calc_type) ? 0 : 9;

				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			// нажата кнопка удаления товара из заказа
			elseif(Tools::isSubmit('delProduct'))
			{
				// создаем объект OrderDetail выбранного товара для отката;
				// откат это return/refund/delete в зависимости от состояния:
				// hasBeenDelivered/hasBeenPaid - если ни к одному из двух состояний
				// не относится, то производится реальное удаление товара из заказа
				// @todo: откат не получается, сделал просто удаление, экспериментальный код оставил в комментариях
				$id_order_detail = (int)Tools::getvalue('id_order_detail');
				$od = new OrderDetail($id_order_detail);

				// product_qtw_new - количество, которое необходимо откатить, для этого вычисляется разница: product_qtw_current - product_qtw_new
				//$qty_new = (int)Tools::getvalue('product_qtw_new');
				//$qty_current = (int)Tools::getvalue('product_qtw_current');
				//$qtyToDelete = $qty_current - $qty_new;

				//if ($qtyToDelete < 0) {
				//	$errCode = 4;
				//	Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
				//}

				// вызываем метод отката выбранного товара; параметр $order не используется в 1.4, видимо оставлен для ретросовместимости;
				//$errCode = $order->deleteProduct($order, $od, $qtyToDelete) ? 0 : 5;

				// удаление из корзины
				$cart->deleteProduct($od->product_id, $od->product_attribute_id);

				// удаление из деталей заказа
				$errCode = $od->delete() ? 0 : 5;
				if($errCode)
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
				else
					Product::updateQuantity(array('id_product'=>$od->product_id, 'cart_quantity'=>-$od->product_quantity, 'id_product_attribute'=>$od->product_attribute_id), $order->id);

				$errCode = $this->calcPrices($products, $order, $cart, $carrier, self::$order_total_calc_type) ? 0 : 9;
				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			// нажата кнопка сохранения параметров товара в заказе
			elseif(Tools::isSubmit('saveProduct'))
			{
				// количество товара, которое получится после сохранения
				$qty_new = (int)Tools::getvalue('product_qtw_new');

				// текущее количество товара (перед сохранением)
				$qty_current = (int)Tools::getvalue('product_qtw_current');

				// количество товара, которое нужно прибавить или отнять
				$qty_to_change = $qty_new - $qty_current;

				$id_order_detail = (int)Tools::getvalue('id_order_detail');
				$product_attribute_id = (int)Tools::getValue('product_attribute_id', 0);

				$od = new OrderDetail($id_order_detail);
				$p = new Product($od->product_id);

				// если изменяется количество товара
				if ($qty_to_change != 0)
				{
					// проверим, доступно ли необходимое количество товара на складе, чтобы добавить в заказ, если это изменение в сторону увеличения
					if($qty_to_change > 0)
					{
						$errCode = self::checkProductQty($qty_to_change, $p, $product_attribute_id, false);
						if($errCode > 0)
							Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
					}

					// сначала обновляем количество товара в корзине, потому что, если будет ошибка, то продолжать дальше нельзя;
					// для корзины количество должно быть всегда положительным, а параметром $operator задается направление - уменьшать или увеличивать
					$operator = $qty_new > $qty_current ? 'up' : 'down';
					$ret = self::updateCartQty($cart, abs($qty_to_change), $od->product_id, $product_attribute_id, $operator);
					if ($ret === -1)
						$errCode = 21;
					elseif ($ret == false)
						$errCode = 16;
					else
						$errCode = 0;

					if($errCode > 0)
						Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));

					// обновляем запас товара
					$product_params = array(
						'id_product' => $od->product_id,
						'cart_quantity' => $qty_to_change,
						'id_product_attribute' => $od->product_attribute_id
					);
					Product::updateQuantity($product_params, $od->id_order);

					// изменим количество товара в истории заказа, но обновлять объект будем после всех изменений
					$od->product_quantity = $qty_new;
				}

				// если изменяется цена
				$product_price = Tools::getValue('product_price');
				if (Validate::isPrice($product_price) && $product_price != $od->product_price)
					$od->product_price = (float)$product_price;

				// обновляем детали заказа
				$errCode = $od->save() ? 0 : 6;
				if($errCode > 0)
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));

				// пересчитываем цены
				$errCode = $this->calcPrices($products, $order, $cart, $carrier, self::$order_total_calc_type) ? 0 : 9;

				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			// нажата кнопка сохранения параметров доставки
			elseif(Tools::isSubmit('editShipping'))
			{
				// необходимо продублировать новую информацию как для Order так и для Cart
				$order->delivery_date = pSQL(Tools::getvalue('delivery_date'));
				$order->id_carrier = $cart->id_carrier = (int)Tools::getvalue('id_carrier');
				$order->recyclable = $cart->recyclable = (int)Tools::getvalue('recyclable');
				$order->gift = $cart->gift = (int)Tools::getvalue('gift');

				// если изменена цена доставки
				$total_shipping_new = Tools::getValue('total_shipping');
				if (Validate::isPrice($total_shipping_new) && $total_shipping_new != $order->total_shipping)
					$order->total_shipping = (float)$total_shipping_new;

				// подарочная упаковка
				if($order->gift) {
					$order->gift_message = $cart->gift_message = pSQL(Tools::getvalue('gift_message'));
					// узнаем, есть ли налог на подарочную упаковку; в конфигурации будет 0, если налога нет на нее
					$wrapping_tax_include = Configuration::get('PS_GIFT_WRAPPING_TAX') ? true : false;

					// запишем цену за подарочную упаковку
					$order->total_wrapping = abs($cart->getOrderTotal($wrapping_tax_include, self::$order_total_calc_type['ONLY_WRAPPING']));
				}
				// без подарочной упаковки
				else {
					$order->total_wrapping = 0;
					$order->gift_message = $cart->gift_message = '';
				}

				$order->shipping_number = pSQL(Tools::getvalue('shipping_number'));

				$errCode = $order->save() ? 0 : 7;
				if($errCode) {
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
				}

				$errCode = $cart->save() ? 0 : 16;
				if($errCode) {
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
				}

				$errCode = $this->calcPrices($products, $order, $cart, $carrier, self::$order_total_calc_type) ? 0 : 9;
				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			elseif(Tools::isSubmit('recalcShippingCost'))
			{
				// перерасчитаем стоимость доставки автоматически - на основе правил корзины;
				// проверим есть ли налог на доставку, и если есть, то передадим соответствующий
				// параметр в метод; важно, что если для правила (Payment -> Tax Rules) не задан
				// налог для страны, к которой принадлежит адрес клиента, то налог не рассчитается,
				// но рассчитается налог на сборы (fees) и налог на обработку (Handling charges),
				// ну а если задан, то рассчитается налог, как на доставку, так и на сборы и обработку.
				$shipping_tax_include = $carrier->id_tax_rules_group ? true : false;
				$order->total_shipping = $cart->getOrderShippingCost(null, $shipping_tax_include);
				
				$errCode = $order->save() ? 0 : 7;
				if($errCode)
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));

				$errCode = $this->calcPrices($products, $order, $cart, $carrier, self::$order_total_calc_type) ? 0 : 9;
				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			elseif(Tools::isSubmit('applyShippingAddress'))
			{
				$order->id_address_delivery = $cart->id_address_delivery = Tools::getValue('shipping_address');
				$errCode = $order->save() ? 0 : 8;
				if($errCode)
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));

				$errCode = $cart->save() ? 0 : 16;
				if($errCode)
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));

				// необходимо рассчитать стоимость доставки снова, если адрес доставки изменился;
				// @todo: для InvoiceAddress это делать возможно тоже нужно, т.к. в настройках престы устанавливается, рассчитывать по
				// адресу доставки или по адресу счета - поэтому нужно делать проверку и рассчитывать как надо.
				$errCode = $this->calcPrices($products, $order, $cart, $carrier, self::$order_total_calc_type) ? 0 : 9;

				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			elseif(Tools::isSubmit('applyInvoiceAddress'))
			{
				$order->id_address_invoice = $cart->id_address_invoice = Tools::getValue('invoice_address');
				$errCode = $order->save() ? 0 : 8;
				if($errCode) {
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
				}

				$errCode = $cart->save() ? 0 : 16;

				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			elseif(Tools::isSubmit('savePayment') || Tools::isSubmit('addPayment'))
			{
				// если $id_order_payment == 0, то создастся новый объект для добавления в бд, иначе
				// создастся объект существующей записи в БД
				$id_order_payment = Tools::getValue('id_order_payment');
				$order_payment = new OrderPayment($id_order_payment);
				$order_payment->id_order = $order->id;
				$order_payment->id_currency = $currency->id;

				// сохранить conversion_rate на момент оплаты, так как он может поменяться в другой момент
				$order_payment->conversion_rate = $currency->conversion_rate;

				$order_payment->payment_method = Tools::getValue('payment_method');
				$order_payment->transaction_id = Tools::getValue('transaction_id');

				$order_payment->date_add = Tools::getValue('date_add');

				$amount = Tools::getValue('amount');
				$amount_old = Tools::getValue('amount_old');
				if (!Validate::isPrice($amount) || !Validate::isPrice($amount_old))
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => 20)));

				$order_payment->amount = (float)$amount;

				// если дата не задана, то передастся true во втором параметре, чтобы дата была сгенерирована
				$errCode = $order_payment->save(false, !$order_payment->date_add) ? 0 : 10;
				if ($errCode) {
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
				}

				// обновляем уплаченную сумму за заказ
				if ($order_payment->id_currency == $order->id_currency) {
					if($id_order_payment) {
						$order->total_paid_real += $order_payment->amount - $amount_old;
					}
					else {
						$order->total_paid_real += $order_payment->amount;
					}
				}
				else {
					$order->total_paid_real += Tools::ps_round(Tools::convertPrice($order_payment->amount, $order_payment->id_currency, false), 2);
				}

				// обновляем метод оплаты
				$module = Module::getInstanceByName($order_payment->payment_method);
				$order->module = $order_payment->payment_method;
				$order->payment = $module->displayName;

				$errCode = $order->save() ? 0 : 9;
				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			elseif(Tools::isSubmit('delPayment'))
			{
				$id_order_payment = Tools::getValue('id_order_payment');
				$order_payment = new OrderPayment($id_order_payment);

				// удаление платежки не вычитает ее сумму из суммы заказа, поэтому нужно самому это сделать
				$errCode = $order_payment->delete() ? 0 : 11;
				if ($errCode) {
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
				}

				if ($order_payment->id_currency == $order->id_currency)
					$order->total_paid_real -= $order_payment->amount;
				else
					$order->total_paid_real -= Tools::ps_round(Tools::convertPrice($order_payment->amount, $order_payment->id_currency, false), 2);

				$errCode = $order->save() ? 0 : 9;
				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			elseif(Tools::isSubmit('editOrder'))
			{
				$order->date_add = Tools::getValue('date_add');
				if (Validate::isDate($order->date_add))
					$errCode = $order->save() ? 0 : 9;
				else
					$errCode = 9;

				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			elseif (Tools::isSubmit('addState'))
			{
				$newOrderStatusId = Tools::getValue('id_order_state');
				$OrderHistory = new OrderHistory();
				$OrderHistory->id_order = $order->id;
				$OrderHistory->id_employee = (int)$cookie->id_employee;
				$OrderHistory->changeIdOrderState($newOrderStatusId, $order->id);

				// далее формируется массив шаблона письма, отправляемого клиенту
				// если статус не относится к доставке, оплате по чеку или квитанции,
				// то массив $templateVars остается пустым и письмо клиенту не отправляется
				$templateVars = array();
				if($OrderHistory->id_order_state == Configuration::get('PS_OS_SHIPPING') AND $order->shipping_number) {
					$templateVars = array('{followup}' => str_replace('@', $order->shipping_number, $carrier->url));
				}
				elseif ($OrderHistory->id_order_state == Configuration::get('PS_OS_CHEQUE')) {
					$templateVars = array(
						'{cheque_name}' => (Configuration::get('CHEQUE_NAME') ? Configuration::get('CHEQUE_NAME') : ''),
						'{cheque_address_html}' => (Configuration::get('CHEQUE_ADDRESS') ? nl2br(Configuration::get('CHEQUE_ADDRESS')) : ''));
				}
				elseif ($OrderHistory->id_order_state == Configuration::get('PS_OS_BANKWIRE')) {
					$templateVars = array(
						'{bankwire_owner}' => (Configuration::get('BANK_WIRE_OWNER') ? Configuration::get('BANK_WIRE_OWNER') : ''),
						'{bankwire_details}' => (Configuration::get('BANK_WIRE_DETAILS') ? nl2br(Configuration::get('BANK_WIRE_DETAILS')) : ''),
						'{bankwire_address}' => (Configuration::get('BANK_WIRE_ADDRESS') ? nl2br(Configuration::get('BANK_WIRE_ADDRESS')) : ''));
				}

				$errCode = $OrderHistory->addWithemail(true, $templateVars) ? 0 : 12;
				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			elseif($readed_message_id = (int)Tools::getValue('readed_message_id'))
			{
				if (method_exists('Message', 'markAsReaded'))
					$errCode = Message::markAsReaded($readed_message_id, (int)$cookie->id_employee) ? 0 : 13;
				else
					$errCode = Message::markAsRead($readed_message_id, (int)$cookie->id_employee) ? 0 : 13;

				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			elseif(Tools::isSubmit('addMessage'))
			{
				$message = new Message();
				$message->id_employee = (int)$cookie->id_employee;
				$message->message = htmlentities(Tools::getValue('message'), ENT_COMPAT, 'UTF-8');
				$message->id_order = $order->id;
				$message->private = (int)Tools::getValue('visibility');

				if ($message->message != '' && Validate::isBool($message->private))
					$errCode = $message->add() ? 0 : 14;
				else
					$errCode = 14;

				if ($errCode)
					Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));

				// отправляем сообщение клиенту, если оно не приватное
				if (!$message->private)
				{
					$tpl_vars = array(
						'{lastname}' => $customer->lastname,
						'{firstname}' => $customer->firstname,
						'{id_order}' => $message->id_order,
						'{message}' => (Configuration::get('PS_MAIL_TYPE') == 2 ? $message->message : nl2br2($message->message))
					);
					$errCode = @Mail::Send(
						$order->id_lang,
						'order_merchant_comment',
						Mail::l('New message regarding your order', $order->id_lang),
						$tpl_vars,
						$customer->email,
						$customer->firstname.' '.$customer->lastname,
						NULL,
						NULL,
						NULL,
						NULL,
						_PS_MAIL_DIR_,
						true
					) ? 0 : 15;
				}

				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			elseif(Tools::isSubmit('delDiscount'))
			{
				// удаляем ваучер
				if ($id_discount = (int)Tools::getValue('id_order_discount'))
				{
					self::deleteDiscount($id_discount, $cart, $order);
					$errCode = $this->calcPrices($products, $order, $cart, $carrier, self::$order_total_calc_type) ? 0 : 9;
				}
				else
					$errCode = 17;

				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}
			elseif(Tools::isSubmit('saveDiscount') || Tools::isSubmit('addDiscount'))
			{
				$id_discount = (int)Tools::getValue('id_discount');
				$id_discount_old = (int)Tools::getValue('id_order_discount');

				if($id_discount <= 0)
					$errCode = 18;
				elseif ($id_discount == $id_discount_old)
					$errCode = 0;
				elseif(Tools::isSubmit('addDiscount'))
				{
					// добавляем ваучер
					//@todo: нужно добавить возможность отображать ошибку, которую возвращает метод; тоже самое при изменения ваучера - см. ниже
					if (self::addDiscount($id_discount, (int)$cookie->id_lang, $cart, $order) === true)
						$errCode = $this->calcPrices($products, $order, $cart, $carrier, self::$order_total_calc_type) ? 0 : 9;
					else
						$errCode = 18;
				}
				elseif(Tools::isSubmit('saveDiscount'))
				{
					// удаляем старый ваучер и добавляем новый
					self::deleteDiscount($id_discount_old, $cart, $order);
					$errCode = self::addDiscount($id_discount, (int)$cookie->id_lang, $cart, $order) === true ? 0 : 19;

					// при любом раскладе в данном случае нужно обновить цены
					$errCode = $this->calcPrices($products, $order, $cart, $carrier, self::$order_total_calc_type) ? $errCode : 9;
				}

				Tools::redirectLink($this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id, 'st' => $errCode)));
			}

/////////////////////////////////// Верстка ////////////////////////////////////

			// [2] заканчиваем формировать заголовок
			$order_href = $this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id));
			$html.= ' / <a class="link" href="'.$order_href.'">'.$this->l('Order #').$order->id.' - '.$customer->firstname.' '.$customer->lastname.'</a></h2>';

			// отобразим сообщение со статусом выполненой операции
			$this->_postValidation();
			if (sizeof($this->_postErrors))
			{
				foreach ($this->_postErrors AS $e)
					$html .= '<div class="alert error">'. $e .'</div>';
			}

			if(Tools::getIsset('st') && (int)Tools::getValue('st') === 0)
				$html .= '<div class="conf confirm"><img src="../img/admin/ok.gif" />' . $this->l('Updated successful.') . '</div>';

			$html .= '<div class="warning" style="margin-bottom:0; margin-top:5px; width:890px;">'.$this->l('Be careful with operations. The orders editor does not ask for confirmation.').'</div>';
			$html .= '<br/><br/>';

			// весь контент страницы, разделенный по соответствующим блокам
			$html.= '
				<STYLE TYPE="text/css">
					a.qty_dec {
						margin-top: 6px;
						display:block;
						background-repeat:no-repeat;
						width:14px;
						height:9px;
						background-image: url("../modules/orderzeditor2/quantity_down.gif");
					}
					a.qty_inc {
						display:block;
						background-repeat:no-repeat;
						width:14px;
						height:9px;
						background-image: url("../modules/orderzeditor2/quantity_up.gif");
					}
					div.no_results {

					}
					span.price_label {
						text-decoration: none;
						border-bottom: 1px dashed;
						cursor: pointer;
					}
					span.price_notice {
						color: #FF0000;
						font-size: smaller;
					}
				</STYLE>
				<script type="text/javascript">
				//<![CDATA[
					$("document").ready( function(){
						if(document.getElementById("gift").checked == false) {
							$("#gift_div").toggle("slow");
						}
					});

					function showPriceInput(el) {
						vals = $(el).attr("id").split("_");
						if(vals.length==3) {
							id = vals[2];
							$(el).hide();
							$("#price_input_"+id).show();
							$("#price_input_"+id).focus();
							$("#price_notice_"+id).show();
						}
					}

					//@todo пока не требуется использовать - в самом начале производится выход из функции
					function hidePriceInput(el) {
						return 0;
						vals = $(el).attr("id").split("_");
						if(vals.length==3) {
							id = vals[2];
							$(el).hide();
							$("#price_notice_"+id).hide();
							$("#price_label_"+id).show();
						}
					}

					function check_fill(field_name, field_data) {
						if(field_data == "") {
							alert("'.$this->l('You must fill the field').': " + field_name);
						}
					}

					function check_price(price) {
						check_fill("'.$this->l('Price').'", price);
						if(eval(price) < 0) {
							alert("'.$this->l('The amount like other price value must be greater or equal zero').'");
						}
					}

					function check_qty(qty, stock) {
						check_fill("' . $this->l('Qty') . '", qty);
						if(eval(qty) <=0 || eval(qty) > eval(stock)) {
							alert("' . $this->l('Product Qty must be in range: 0 < Qty < Stock !') . '");
						}
					}
					function upQty(op, ed_name, id_product) {
						// ed_name - the name of input field
						// id_product - id_product or another id to identify the field
						// op - inc or dec
						var ed = "#"+ed_name+"_"+id_product;
						var str = $(ed).val();
						if (str == "") {
							var val = 1;
							$(ed).val(val);
						}
						else {
							var val = parseInt(str);
						}
						if (op == "inc") {
							if (val < 9999) {
								$(ed).val(val+1);
							}
						}
						else if (val > 1) {
							$(ed).val(val-1);
						}
					}
				//]]>
				</script>
			';

			// суммарная информация о заказе
			$DatePickerID = 'order_date_add';
			$DatePickerArray[] = $DatePickerID;
			$html.= '
				<fieldset style="width: 900px">
					<legend><img src="../img/admin/details.gif" /> ' . $this->l('Order summary (inc. taxes)') . '</legend>
					<table class="table" width="900px;" cellspacing="0" cellpadding="0">
						<tr>
							<th>' . $this->l('Date') . '</th>
							<th>' . $this->l('Products') . '</th>
							<th>' . $this->l('Shipping') . '</th>'.
							(Configuration::get('PS_GIFT_WRAPPING') ? '<th>' . $this->l('Wrapping') . '</th>' : '').'
							<th>' . $this->l('Discount') . '</th>
							<th>' . $this->l('Total to pay') . '</th>
							<th>' . $this->l('Paid') . '</th>
							<th>' . $this->l('Invoice') . '</th>
							<th>' . $this->l('Delivery slip') . '</th>
							<th>' . $this->l('Actions') . '</th>
						</tr>
						<tr>
							<form name="EditOrderForm" method="post" action="' . $this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order'=>$order->id)) . '">
								<td><input type="text" value="' . $order->date_add . '" name="date_add" id="'.$DatePickerID.'" style="width:115px;"/></td>
								<td>' . $totalProductsWithTaxes . '</td>
								<td>' . $totalShipping . '</td>'.
								(Configuration::get('PS_GIFT_WRAPPING') ? '<td>' . $totalWrapping . '</td>' : ''). '
								<td>'.Tools::displayPrice($order->total_discounts, $currency, false).'</td>
								<td>' . $totalToPay . '</td>
								<td title="'.$this->l('Paid by the customer').'"'.($order->total_paid != $order->total_paid_real ? 'style="color:red"' : '').'>' . $paidByCustomer . '</td>
								<td>';
									if($currentState->invoice OR $order->invoice_number AND count($products)) {
										$html.= '<a href="pdf.php?id_order='.$order->id.'&pdf" title="'.$this->l('View invoice').'"><img src="../img/admin/pdf.gif" />#' .$order->invoice_number. '</a>';
									}
									else {
										$html.= '<img src="../img/admin/disabled.gif" title="'.$this->l('No invoice').'" />#' .$order->invoice_number;
									}
								$html.= '
								</td>
								<td>';
									if($currentState->delivery OR $order->delivery_number) {
										$html.= '<a href="pdf.php?id_delivery='.$order->delivery_number.'" title="'.$this->l('View delivery slip').'"><img src="../img/admin/pdf.gif" />#' .$order->delivery_number. '</a>';
									}
									else {
										$html.= '<img src="../img/admin/disabled.gif" title="'.$this->l('No delivery slip').'" />#' .$order->delivery_number;
									}
								$html.= '
								</td>
								<td align="center">
									<input type="image" src="../img/admin/ok.gif" name="editOrder" value="edit" title="' . $this->l('Save changes') . '">
								</td>
							</form>
						</tr>
					</table>
				</fieldset>
			<br class="clear"/>
			<br />
			';

			// отображаем блок статусов заказа
			$html.= '
			<fieldset style="display:inline; width:350px; margin-right:10px; vertical-align:top;">
				<legend><img src="../img/admin/date.png" /> ' . $this->l('Order statuses') . '</legend>
				<table cellspacing="0" cellpadding="0" class="table" style="width:350px">
					<tr>
						<th style="text-align:center; width:115px;">'.$this->l('Date').'</th>
						<th style="text-align:center;">'.$this->l('Status').'</th>
					</tr>';
				$i = 0;
				foreach ($history AS $row)
				{
					$html.= '
					<tr class="'.($i++ % 2 ? 'alt_row' : '').'">
						<td>'. $row['date_add'] .'</td>
						<td><img src="../img/os/'.$row['id_order_state'].'.gif" /> '.Tools::stripslashes($row['ostate_name']).'</td>
					</tr>';
				}
				$html.= '
					<tr>
						<form name="EditStatusForm" method="post" action="' . $this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order'=>$order->id)) . '">
							<td colspan="2" align="right">
								<span style="color:black;font-weight:bold;">'. $this->l('New status:'). '</span>
								<select name="id_order_state">';
								foreach ($states AS $state) {
									$html.= '<option value="'.$state['id_order_state'].'"'.(($state['id_order_state'] == $currentStateTab['id_order_state']) ? ' selected="selected"' : '').'>'.Tools::stripslashes($state['name']).'</option>';
								}
								$html.= '
								</select>
								<input type="image" src="../img/admin/add.gif" name="addState" value="add" title="' . $this->l('Add status') . '">
							</td>
						</form>
					</tr>
				</table>
			</fieldset>
			';

			// отображаем блок с перепиской
			$html.= '
			<fieldset style="display:inline; width:508px">
				<legend><img src="../img/admin/comment.gif" /> ' . $this->l('Messages and notes') . '</legend>
				<table cellspacing="0" cellpadding="0" class="table" style="width:508px">
					<tr>
						<th style="text-align:center; width:115px;">'.$this->l('Date').'</th>
						<th style="text-align:center;">'.$this->l('Text').'</th>
						<th style="text-align:center;">'.$this->l('Sender').'</th>
					</tr>';
					if ($messages)
					{
						foreach ($messages as $message)
						{
							$html .='
							<tr>
								<td>'. $message['date_add'] .'</td>
								<td>'. ($message['is_new_for_me'] ? '<a style="font-weight:bold;" title="'.$this->l('Mark this message as viewed').'" href="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'&token='.$this->token.'&readed_message_id='.$message['id_message'].'">'. nl2br2($message['message']) .'</a>' : nl2br2($message['message'])).' </td>
								<td align="center">'. ($message['private'] == 1 ? '<img src="../img/admin/employees_xl.png" width="16px" height="16px" title="' .$this->l('Private note'). '" />' : ($message['id_customer'] ? '<img src="../img/admin/tab-customers.gif" title="' .$this->l('Customer\'s message'). '" />' : '<img src="../img/admin/employee.gif" title="' .$this->l('Employe\'s message'). '" />') ) .'</td>
							</tr>';
						}
					}
					// если сообщений нет, то отобразим соответствующую надпись
					else
					{
						$html .='
						<tr>
							<td colspan="3" style="text-align:center; ">'. $this->l('There are no messages.') .'</td>
						</tr>';
					}
					$html .='
					<tr>
						<td colspan="3" align="right">
							<form name="AddMessageForm" method="post" action="' . $this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order'=>$order->id)) . '">
								<p style="display:inline; font-size:10px; color:#666; margin-right:5px;">'.$this->l('Max. length: 600 symbols').'</p>
								<select style="margin-right:5px;" name="order_message" id="order_message" onchange="orderOverwriteMessage(this, \''.$this->l('Do you want to overwrite your existing message?').'\')">
									<option value="0" selected="selected">' .$this->l('Choose a template'). '</option>';
									foreach ($orderMessages AS $orderMessage)
									{
										$html .='
										<option value="'.htmlentities($orderMessage['message'], ENT_COMPAT, 'UTF-8').'">'.$orderMessage['name'].'</option>';
									}
								$html .='
								</select>
								<input type="checkbox" name="visibility" id="visibility" value="1" checked="checked" /> <span style="color:black; font-weight:bold; margin-right:5px;">'.$this->l('Private').'</span>
								<input type="image" src="../img/admin/add.gif" name="addMessage" value="add" title="' . $this->l('Add') . '">
								<br/>
								<textarea maxlength="600" id="txt_msg" name="message" style="max-width:486px; min-width:486px; height:30px; margin-bottom:6px;">'.htmlentities(Tools::getValue('message'), ENT_COMPAT, 'UTF-8').'</textarea>
							</form>
						</td>
					</tr>
				</table>
			</fieldset>
			<br class="clear"/>
			<br />
			';

			// отображаем блок клиента
			$html.= '
				<a name="customer"></a>
				<fieldset style="width: 900px">
					<legend><img src="../img/admin/tab-customers.gif" /> ' . $this->l('Customer') . '</legend>
					<span style="font-weight: bold; font-size: 14px;">' .
						$customer->firstname . ' ' . $customer->lastname . '
					</span>
					<a href="'. $this->getAdminTabUrl('AdminCustomers', array('id_customer' => $customer->id, 'viewcustomer' => NULL)) .'"> <img src="../img/admin/nav-user.gif" /></a><br/>
					<br/>
					<div>
						<a target="_blank" href="' . $this->getAdminTabUrl('AdminAddresses', array('addaddress'=>NULL)) . '"><img src="../img/admin/add.gif" title="' . $this->l('Add new address') . '" />' . $this->l('Add new address') . '</a>
					</div>
					<br/>
					<table style="width:900px;" cellspacing="0" cellpadding="0" class="table">
						<tr>
							<th colspan="2">' . $this->l('Address') . '</th>
							<th>' . $this->l('Actions') . '</th>
							<th>' . $this->l('Map') . '</th>
						</tr>
						<tr>
							<form name="EditCarrierForm" method="post" action="' . $this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order'=>$order->id)) . '#customer">
							<td>'. $this->l('For shipping:') .'</td>
							<td>
								<select name="shipping_address" style="width:670px;">';
								foreach($formated_addresses as $a) {
									$html.= '<option value="' . $a['id'] . '" '.($addressDelivery->id == $a['id']?'selected="selected"':'').' >' . $a['addr'] . '</option>';
								}
								$html.='
								</select>
							</td>
							<td>
								<input type="image" src="../img/admin/ok.gif" name="applyShippingAddress" value="edit" title="' . $this->l('Apply shipping address') . '">
								<a href="' . $this->getAdminTabUrl('AdminAddresses', array('id_address'=>$addressDelivery->id, 'addaddress'=>NULL, 'realedit'=>1, 'id_order'=>$order->id)) . ($addressDelivery->id == $addressInvoice->id ? '&address_type=1' : '') . $this->getBackUrlToOrderzAdmin('AdminAddresses', $order->id) . '"><img src="../img/admin/edit.gif" title="' . $this->l('Edit shipping address') . '" /></a>
							</td>
							<td>' .
								$this->getMapUrl('Google maps', 'http://maps.google.com/maps?f=q', array('h1' => $iso_code), 'q', 'google.gif', $addressDelivery, $deliveryState) .
								$this->getMapUrl('Yandex maps', 'http://maps.yandex.ru/?l=map', array(), 'text','yandex.ico', $addressDelivery, $deliveryState) . '
							</td>
							</form>
						</tr>
						<tr>
							<form name="EditCarrierForm" method="post" action="' . $this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order'=>$order->id)) . '#customer">
							<td>'. $this->l('For invoice:') .'</td>
							<td>
								<select name="invoice_address" style="width:670px;">';
								foreach($formated_addresses as $a) {
									$html.= '<option value="' . $a['id'] . '" '.($addressInvoice->id == $a['id']?'selected="selected"':'').' >' . $a['addr'] . '</option>';
								}
								$html.='
								</select>
							</td>
							<td>
								<input type="image" src="../img/admin/ok.gif" name="applyInvoiceAddress" value="edit" title="' . $this->l('Apply invoice address') . '">
								<a href="' . $this->getAdminTabUrl('AdminAddresses', array('id_address'=>$addressInvoice->id, 'addaddress'=>NULL, 'realedit'=>1, 'id_order'=>$order->id)) . ($addressDelivery->id == $addressInvoice->id ? '&address_type=2' : '') . $this->getBackUrlToOrderzAdmin('AdminAddresses', $order->id) . '"><img src="../img/admin/edit.gif" title="' . $this->l('Edit invoice address') .'" /></a>
							</td>
							<td>' .
								$this->getMapUrl('Google maps', 'http://maps.google.com/maps?f=q', array('h1' => $iso_code), 'q', 'google.gif', $addressInvoice, $invoiceState) .
								$this->getMapUrl('Yandex maps', 'http://maps.yandex.ru/?l=map', array(), 'text', 'yandex.ico', $addressInvoice, $invoiceState) . '
							</td>
							</form>
						</tr>
					</table>
				</fieldset>
				<br class="clear"/>
				<br />
			';

			// отображаем блок со способом доставки; сгенерируем id для поля под дату и добавим в массив таких id
			$DatePickerID = 'delivery_date';
			$DatePickerArray[] = $DatePickerID;
			$html.= '
				<a name="shipping"></a>
				<fieldset style="width: 900px">
					<legend><img src="../img/admin/delivery.gif" /> ' . $this->l('Shipping') . '</legend>
					<form name="EditCarrierForm" method="post" action="' . $this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order'=>$order->id)) . '#shipping">
						<table cellspacing="0" cellpadding="0" class="table">
							<tr>
								<th style="text-align:center;">' . $this->l('Date') . '</th>
								<!--<th style="text-align:center;">' . $this->l('Type') . '</th>-->
								<th style="text-align:center;">' . $this->l('Carrier') . '</th>
								<th style="width:70px;text-align:center;">' . $this->l('Weight') . '</th>
								<th style="text-align:center;">' . $this->l('Shipping cost') . '</th>'.
								(Configuration::get('PS_RECYCLABLE_PACK') ? '<th style="text-align:center;">' . $this->l('Recycled package') . '</th>' : '') .
								(Configuration::get('PS_GIFT_WRAPPING') ? '<th style="text-align:center;">' . $this->l('Gift wrapping') . '</th>' : '') .'
								<th style="text-align:center;">' . $this->l('Tracking number') . '</th>
								<th style="text-align:center;">' . $this->l('Actions') . '</th>
							</tr>
							<tr>
								<td><input type="text" value="' . $order->delivery_date . '" name="delivery_date" id="'.$DatePickerID.'" style="width:115px;"/></td>
								<!--<td></td>-->
								<td>
									<select name="id_carrier" style="width:169px;">';
										$carriers = $carrier->getCarriers((int)$cookie->id_lang, true);
										foreach ($carriers as $k => $c) {
											$html .= '<option value="' . $c['id_carrier'] . '" ' . ($c['id_carrier'] == $order->id_carrier ? 'selected="selected"' : '') . '>' . $c['name'] . '</option>';
										}
									$html.= '
									</select>
									<input type="hidden" value="save" name="EditCarrier"/>
								</td>
								<td>' . Tools::ps_round($order->getTotalWeight(), 2) . ' ' . Configuration::get('PS_WEIGHT_UNIT') . '</td>
								<td>
									<span class="price_label" id="price_label_shipping" onclick="showPriceInput(this);">'.Tools::displayPrice($order->total_shipping, $currency, false).'</span>
									<input onblur="hidePriceInput(this);" style="display:none;" type="text" name="total_shipping" id="price_input_shipping" value="'.Tools::ps_round($order->total_shipping, 2).'" size="12" maxlength="15"/>
									<span class="price_notice" style="display:none;" id="price_notice_shipping">'.$this->l('Type a final cost').'</span>
								</td>'.
								(Configuration::get('PS_RECYCLABLE_PACK') ? '<td style="text-align:center;"><input style="margin-right:10px;" type="checkbox" value="1" name="recyclable" ' . ($order->recyclable ? 'checked="checked"' : '') . '></td>' : '') .
								(Configuration::get('PS_GIFT_WRAPPING') ? '<td style="text-align:center;"><input style="margin-right:10px;" type="checkbox" value="1" name="gift" id="gift" onclick="$(\'#gift_div\').toggle(\'slow\');"' . ($order->gift ? 'checked="checked"' : '') . '></td>' : '').'
								<td><input type="text" value="' . $order->shipping_number /* в версии 1.5 это: $order_carrier->tracking_number */ . '" name="shipping_number" /></td>
								<td>
									<input type="image" src="../img/admin/ok.gif" title="'.$this->l('Save').'" name="editShipping" value="edit">
									<input type="image" src="../modules/orderzeditor2/refresh.png" width="16" title="'.$this->l('Recalculate shipping cost automatically').'" name="recalcShippingCost" value="edit"></td>
								</td>
							</tr>
						</table>';
						if(Configuration::get('PS_GIFT_WRAPPING')) {
							$html.= '
							<div id="gift_div" style="clear: left; margin-top: 10px;">
								<b>'.$this->l('Note for gift wrapping:').'</b>
								<input style="width:890px" type="text" name="gift_message" value="'.$order->gift_message.'" />
							</div>';
						}
						$html.='
					</form>
				</fieldset>
				<br class="clear"/>
				<br />
			';

			// отображаем блок оплаты

			// массив оплат по заказу
			$payments = OrderPayment::getByOrderId($order->id);

			// история оплат изначально не включает первую оплату клиентом, которую он
			// произвел онлайн, поэтому нужно ее добавить в список при первой загрузки
			// страницы редактора; если будет удалена эта платежка из списка, то
			// также нужно удалить информацию об оплате из заказа: способ оплаты,
			// модуль оплаты, сколько оплачено.
			$paymentsNb = sizeof($payments);
			if($paymentsNb == 0)
			{
				// проверим, есть ли информация об оплате в Order, если да, то
				// продублируем эту информацию в OrderPayment
				if($order->total_paid_real > 0)
				{
					$order_payment = new OrderPayment();
					$order_payment->amount = $order->total_paid_real;
					$order_payment->date_add = $order->date_add;
					$order_payment->id_currency = $order->id_currency;
					$order_payment->id_order = $order->id;
					$order_payment->payment_method = $order->module;

					// сохраним объект и передадим в параметрах, чтобы не вставлял принудительно null и не менял дату
					$order_payment->save(false, false);

					// обновим массив оплат
					$payments = OrderPayment::getByOrderId($order->id);
				}
			}

			// добавим в массив оплат по заказу еще пустую запись об оплате,
			// которая послужит для добавления новой платежки в бд
			$payments[] = array('id_order_payment' => 0, 'date_add'=>'', 'payment_method' => '', 'transaction_id' => '', 'amount' => '', 'invoice_number' => '');

			// способы оплаты
			$payment_modules = self::getInstalledPaymentModules();

			$html.= '
				<a name="payment"></a>
				<fieldset style="width:900px;">
					<legend><img src="../img/admin/payment.gif" /> ' . $this->l('Payment') . '</legend>
					<table cellspacing="0" cellpadding="0" class="table" style="width:900px;">
						<tr>
							<th style="text-align:center;">' . $this->l('Date') . '</th>
							<th style="text-align:center;">' . $this->l('Payment method') . '</th>
							<th style="text-align:center;">' . $this->l('Transaction ID') . '</th>
							<th style="text-align:center; width:140px;">' . $this->l('Amount') . '</th>
							<th style="text-align:center;">' . $this->l('Invoice') . '</th>
							<th style="text-align:center;">' . $this->l('Actions') . '</th>
						</tr>';
					foreach ($payments as $k => $payment) {
						// сгенерируем id для поля под дату и добавим в массив таких id
						$DatePickerID = 'payment_date_'.$k;
						$DatePickerArray[] = $DatePickerID;

						// знаков после запятой для цен
						$c_decimals = (int)$currency->decimals * _PS_PRICE_DISPLAY_PRECISION_;

						$html.= '
						<form name="EditPaymentForm" method="post" action="' . $this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order'=>$order->id)) . '#payment">
							<tr>
								<td><input type="text" value="' . $payment['date_add'] . '" name="date_add" id="'.$DatePickerID.'" style="width:115px;"/></td>
								<td>
									<select name="payment_method" style="width:169px;">';
										foreach ($payment_modules as $m) {
											$module = Module::getInstanceByName($m['name']);
											$html .= '<option value="' . $m['name'] . '" ' . ($m['name'] == $payment['payment_method'] ? 'selected="selected"' : '') . '>' . $module->displayName. '</option>';
										}
									$html.= '
									</select>
								</td>
								<td><input style="width:300px;" type="text" value="' . $payment['transaction_id'] . '" name="transaction_id" /></td>
								<td style="text-align:right;">
									<input size="9" style="text-align:right;" type="text" value="' . Tools::ps_round($payment['amount'], $c_decimals) . '" name="amount" /> ' . $currency->sign . '
									<input type="hidden" value="' . $payment['amount'] . '" name="amount_old">
								</td>
								<td style="text-align:center;">#' . $order->invoice_number . '</td>
								<td >
									<input type="hidden" value="'.$payment['id_order_payment'].'" name="id_order_payment" />';
								if($payment['id_order_payment']) {
									$html.= '
									<input type="image" src="../img/admin/ok.gif" title="'.$this->l('Save').'" name="savePayment" value="edit">
									<input type="image" src="../img/admin/delete.gif" title="'.$this->l('Delete').'" name="delPayment" value="del">';
								}
								else {
									$html.= '
									<input type="image" src="../img/admin/add.gif" title="'.$this->l('Add').'" name="addPayment" value="add">';
								}
								$html.= '
								</td>
							</tr>
						</form>';
					}
					$html.= '
							<tr>
								<td colspan="4" style="text-align:right;color:black;font-weight:bold;">'.$this->l('Total paid').': '.$paidByCustomer.'</td>
								<td colspan="2"></td>
							</tr>
					</table>
				</fieldset>
				<br class="clear"/>
				<br />
			';

			// подключаем виджет выбора даты
			includeDatepicker($DatePickerArray, true);

			// отображаем блок с купонами
			$html.= '
			<a name="vouchers"></a>
			<fieldset style="width:900px;">
				<legend><img src="../img/admin/coupon.gif" /> '.$this->l('Vouchers').'</legend>
				<div>
					<a target="_blank" href="'.$this->getAdminTabUrl('AdminDiscounts', array('adddiscount'=>null)).'">
						<img src="../img/admin/add.gif" title="'.$this->l('Add new voucher').'" />'.
						$this->l('Add new voucher').'
					</a>
				</div>
				<br/>
				<table cellspacing="0" cellpadding="0" class="table" style="width:898px;">
					<tr>
						<th style="text-align:center; width: 175px;">'.$this->l('Code').'</th>
						<th style="text-align:center;">'.$this->l('Type').'</th>
						<th style="text-align:center;">'.$this->l('Description').'</th>
						<th style="text-align:center; width:90px;">'.$this->l('Value').'</th>
						<th style="text-align:center; width:40px;">'.$this->l('Actions').'</th>
					</tr>';

					// добавим в массив ID=0, чтобы отобразить дополнительную строку в таблице для добавления нового купона
					$order_discounts[] = array('id_discount' => 0);

					// все активные купоны, которые применимы для покупателя
					$vouchers = Discount::getCustomerDiscounts((int)$cookie->id_lang, $order->id_customer, true, true, true);

					// сформируем массив с названиями типов купонов [id типа => название]
					$discount_types = Discount::getDiscountTypes((int)$cookie->id_lang);
					$discount_type_names = array();
					foreach ($discount_types as $discount_type)
						$discount_type_names[$discount_type['id_discount_type']] = $discount_type['name'];

					// перебираем массив с купонами, которые применены к заказу и, таким образом, формируем таблицу
					foreach ($order_discounts as $order_discount)
					{
						// параметры каждого купона
						if ($order_discount['id_discount'] > 0)
						{
							$discount = new Discount($order_discount['id_discount'], (int)$cookie->id_lang);

							$discount_type_name = $discount_type_names[$discount->id_discount_type];
							$discount_description = $discount->description;
							$discount_value = Discount::display($order_discount['value'], $order_discount['id_discount_type'], $currency);
						}
						else
							$discount_type_name = $discount_description = $discount_value = '';

						$html.= '
							<form method="post" action="'.$this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order' => $order->id)).'#vouchers">
								<tr>
									<td>
										<select name="id_discount" style="width:169px;">';
											foreach ($vouchers as $voucher)
												$html .= '<option value="'.$voucher['id_discount'].'" '.($order_discount['id_discount'] == $voucher['id_discount'] ? 'selected="selected"' : '').'>'.$voucher['name'].'</option>';
											$html.= '
										</select>
									</td>
									<td>'.$discount_type_name.'</td>
									<td>'.$discount_description.'</td>
									<td>'.$discount_value.'</td>
									<td>
										<input type="hidden" value="'.$order_discount['id_discount'].'" name="id_order_discount" />';
										if ($order_discount['id_discount'] > 0)
											$html.= '
												<input type="image" src="../img/admin/ok.gif" title="'.$this->l('Save').'" name="saveDiscount" value="save">
												<input type="image" src="../img/admin/delete.gif" title="'.$this->l('Delete').'" name="delDiscount" value="del">
											';
										else
											$html.= '<input type="image" src="../img/admin/add.gif" title="'.$this->l('Add').'" name="addDiscount" value="add">';
										$html.= '
									</td>
								</tr>
							</form>
						';
					}
					$html.= '
				</table>
			</fieldset>
			<br class="clear"/>
			<br />
			';

			// формирование таблицы товаров в заказе;
			// выводим этот блок, если нет товаров
			if(!$products)
			{
				$html.= '
					<a name="order_products"></a>
					<fieldset style="width:900px;">
						<legend><img src="../img/admin/cart.gif" />' . $this->l('Products in the order') . '</legend>
						<div style="float:left;">
							<div class="no_results">' . $this->l('No products in the order.') . '</div>
						</div>
					</fieldset>
					<br class="clear"/>
					<br />
				';
			}
			else
			{
				$html.= '
					<a name="order_products"></a>
					<fieldset style="width:900px;">
						<legend><img src="../img/admin/cart.gif" />' . $this->l('Products in the order') . '</legend>
						<div style="float:left;">
							<table style="width:898px;" cellspacing="0" cellpadding="0" class="table">
								<tr>
									<th style="width:45px; text-align:center">&nbsp</th>
									<th style="text-align:center">' . $this->l('Product name') . '</th>
									<th style="width:60px; text-align:center">' . $this->l('Reference') . '</th>
									<th style="width:80px; text-align:center">' . $this->l('Price w.tax') . '</th>
									<th style="width:60px; text-align:center">' . $this->l('Qty.') . '</th>
									<th style="width:80px; text-align:center">' . $this->l('Total w.tax') . '</th>
									<th style="width:40px; text-align:center">' . $this->l('Actions') . '</th>
								</tr>';
								foreach ($products as $product)
								{
									// получим url картинки
									$product_image_uri = $link->getImageLink($product['link_rewrite'], ($product['product_id'].'-'.$product['id_image']), 'small');

									// получим url страницы админки по редактированию товара
									$product_admin_url = $this->getAdminTabUrl('AdminCatalog', array('id_product' => $product['product_id'], 'updateproduct' => NULL));

									$html.= '
										<form name="EditProductForm" method="post" action="' . $this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order'=>$order->id)) . '#order_products">
										<tr>
											<td><img src="' . $product_image_uri . '"></td>
											<td><a href="'.$product_admin_url.'" target="_blank">' . $product['product_name'] . '</a></td>
											<td>' . $product['product_reference'] . '</td>' . '
											<td>
												<span class="price_label" id="price_label_'.$product['id_order_detail'].'" onclick="showPriceInput(this);">'.Tools::displayPrice($product['product_price_wt'], $currency).'</span>
												<input onblur="hidePriceInput(this);" style="display:none;" type="text" name="product_price" id="price_input_'.$product['id_order_detail'].'" value="'.Tools::ps_round($product['product_price'], 2).'" size="12" maxlength="15"/>
												<span class="price_notice" style="display:none;" id="price_notice_'.$product['id_order_detail'].'">'.$this->l('Type a base price: w/o taxes and w/o reductions').'</span>
											</td>
											<td align="center" class="productQuantity">
												<div style="float:left;">
													<a rel="nofollow" class="qty_inc" href="" title="Add" onclick="upQty(\'inc\', \'product_qtw_new\', \''.$product['id_order_detail'].'\'); return false;"></a>
													<a rel="nofollow" class="qty_dec" href="" title="Subtract" onclick="upQty(\'dec\', \'product_qtw_new\', \''.$product['id_order_detail'].'\'); return false;"></a>
												</div>
												<input type="text" name="product_qtw_new" id="product_qtw_new_'.$product['id_order_detail'].'" value="'.$product['product_quantity'].'" size="2" maxlength="2"/>
												<input type="hidden" name="product_qtw_current" value="'.$product['product_quantity'].'">
											</td>
											<td>'.Tools::displayPrice($product['total_wt'], $currency).'</td>
											<td>
												<input type="image" src="../img/admin/ok.gif" title="'.$this->l('Save').'" name="saveProduct" value="save">
												<input type="image" src="../img/admin/delete.gif" title="'.$this->l('Delete').'" name="delProduct" value="del">

												<input type="hidden" name="id_order_detail" value="'.$product['id_order_detail'].'">
												<input type="hidden" name="product_attribute_id" value="'.$product['product_attribute_id'].'">
											</td>
										</tr>
										</form>
									';
								} // end foreach
							$html.= '
							</table>
						</div>
					</fieldset>
					<br class="clear"/>
					<br />
				';
			} // end else

			// блок для поиска товара
			$html.= '
				<form name="searchProductForm" method="post" action="' . $this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order'=>$order->id)) . '#search_results">
					<fieldset style="width:900px;">
						<legend><img src="../img/admin/search.gif" /> ' . $this->l('Search product to add to the order') . '</legend>'.
						$this->l('Product name or it\'s name part') . ':
						<input name="search_txt" type="text" value="" />
						<input type="submit" value="' . $this->l('   Search   ') . '" name="productSearch" class="button" />
					</fieldset>
				</form>
				<br class="clear"/>
				<br />
			';

			// нажата кнопка поиск
			if (Tools::isSubmit('productSearch'))
			{
				$search_txt = pSQL(trim(Tools::getvalue('search_txt')));
				$sql = '
					select i.`id_image`, p.`id_product`, p.`price`, p.`id_category_default`, p.`reference`, p.`quantity`, pl.`link_rewrite`, pl.`name`, m.`name` as man
					from ' . _DB_PREFIX_ . 'product p
					left join ' . _DB_PREFIX_ . 'product_lang pl on p.`id_product`=pl.`id_product`
					left join ' . _DB_PREFIX_ . 'image i on p.`id_product`=i.`id_product` AND i.`cover`=1
					right join ' . _DB_PREFIX_ . 'lang l on pl.`id_lang`=l.`id_lang` AND l.`id_lang`=' . $order->id_lang . '
					left join ' . _DB_PREFIX_ . 'manufacturer m on m.`id_manufacturer`=p.`id_manufacturer`
					where pl.`name` like "%'. $search_txt. '%" OR p.`reference` like "%' . $search_txt . '%" limit 25';

				$ps = Db::getInstance()->ExecuteS($sql, true, 0);

				// если нет результатов выборки
				if(!$ps)
				{
					$html.= '
						<a name="search_results"></a>
						<fieldset style="width:900px;">
							<legend><img src="../img/admin/binoculars.png" /> ' . $this->l('Search result') . '</legend>
							<div class="no_results">' . $this->l('No search results.') . '</div>
						</fieldset>
						<br class="clear"/>
						<br />
					';
				}
				// есть результат выборки - формируем блок со списком товаров
				else
				{
					$html.= '
						<a name="search_results"></a>
						<fieldset style="width:900px;">
							<legend><img src="../img/admin/binoculars.png" /> ' . $this->l('Search result') . '</legend>
							<table cellspacing="0" cellpadding="0" class="table">
								<tr>
									<th style="width:60px; text-align:center">' . $this->l('ID') . '</th>
									<th style="width:45px; text-align:center">&nbsp</th>
									<th style="width:200px; text-align:center">' . $this->l('Product name') . '</th>
									<th style="width:60px; text-align:center">' . $this->l('Reference') . '</th>
									<th style="width:80px; text-align:center">' . $this->l('Manufacturer') . '</th>
									<th style="width:80px; text-align:center">' . $this->l('Original price') . '</th>
									<th style="width:30px; text-align: center">' . $this->l('Stock') . '</th>
									<th>' . $this->l('Combinations:') . '</th>
									<th style="width:126px; text-align: center">' . $this->l('Qty.') . '</th>
									<th style="width:20px; text-align: center">' . $this->l('Actions') . '</th>
								</tr>';
								// генерируем тело таблицы
								foreach ($ps as $p)
								{
									// получим атрибуты товара
									$attribs_tmp = $this->getAttrByProductId($p['id_product'], $order->id_currency);

									// получим адрес картинки
									if (empty($p['id_image']))
										$product_image_uri = _THEME_PROD_DIR_.$iso_code.'-default-small.jpg';
									else
										$product_image_uri = $link->getImageLink($p['link_rewrite'], ($p['id_product'].'-'.$p['id_image']), 'small');

									// получим url страницы админки по редактированию товара
									$product_admin_url = $this->getAdminTabUrl('AdminCatalog', array('id_product' => $p['id_product'], 'updateproduct' => NULL));

									$html .= '
									<form name="addProductForm" method="post" action="' . $this->getOrderzAdminUrl(array('updateorder' => NULL, 'id_order'=>$order->id)) . '#order_products">
									<input type="hidden" name="id_product" value="'.$p['id_product'].'"/>
									<tr>
										<td>' . $p['id_product'] . '</td>
										<td><img src="' . $product_image_uri . '"></td>
										<td><a href="'.$product_admin_url.'" target="_blank">' . $p['name'] . '</a></td>
										<td>' . $p['reference'] . '</td>' . '
										<td>' . $p['man'] . '</td>' . '
										<td>' . Tools::displayPrice($p['price'], $currency) . '</td>' . '
										<td>' . $p['quantity'] . '</td>' . '
										<td>';
										// сформируем выпадаюший список комбинаций товара, если есть комбинации
										if ($attribs_tmp['attr_vals'])
										{
											$html.= '<select name="id_product_attribute" style="width:200px;">';
											foreach($attribs_tmp['attr_vals'] as $id_product_attribute => $a)
											{
												$first = true;
												$option_content = '';
												foreach($attribs_tmp['attr_groups'] as $group_name=>$is_color_group)
												{
													if (isset($a[$group_name]))
													{
														$option_content .= ($first ? '' : ', ').$group_name.': '.$a[$group_name];
														$first = false;
													}
												}

												$html.= '<option value="'.$id_product_attribute.'|'.$option_content.'">'. $this->l('Ref.: ').($a['comb_reference']?$a['comb_reference']:$this->l('n/a')).',&nbsp&nbsp' .  $option_content.'</option>';
											}
											$html.='</select>';
										}
										$html.='
										</td>
										<td>
											<div style="float:left;">
												<a rel="nofollow" class="qty_inc" href="" title="Add" onclick="upQty(\'inc\', \'product_qtw\',\''.$p['id_product'].'\'); return false;"></a>
												<a rel="nofollow" class="qty_dec" href="" title="Subtract" onclick="upQty(\'dec\', \'product_qtw\',\''.$p['id_product'].'\'); return false;"></a>
											</div>
											<input type="text" name="product_qtw" id="product_qtw_'.$p['id_product'].'" value="1" size="4" maxlength="4"/>
										</td>
										<td><input type="image" src="../img/admin/add.gif" name="addProduct" value="+"></td>
									</tr>
									</form>';
								} // end foreach
							$html.= '
							</table>
						</fieldset>
						<br class="clear"/>
						<br />
					';
				} // end else
			} // end if

			// выводим сформированную страницу редактора выбранного заказа
			echo $html;

		} // end if updateorder

	} // end display()
} // end class