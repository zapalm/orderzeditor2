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

class OrderzEditor2 extends Module
{
	public function __construct()
	{
		$this->name = 'orderzeditor2';
		$this->version = '2.2.0';
		$this->tab = 'administration';
		$this->author = 'zapalm';
		$this->need_instance = 0;
		$this->ps_versions_compliancy = array('min' => '1.4.1.0', 'max' => '1.4.12.0');
		$this->bootstrap = false;
		$this->module_tab = 'OrderzAdmin';
		$this->module_key = '35f4414292656724037d94c51554eb48';

		parent::__construct();

		$this->displayName = $this->l('zapalm\'s Orders editor');
		$this->description = $this->l('Must have tool to edit orders.');
	}

	public function install()
	{
		return $this->createTable()
			&& $this->installModuleTab($this->module_tab, 'AdminOrders')
			&& $this->copyAdminImages(array('yandex.ico'))
			&& parent::install();
	}

	public function uninstall()
	{
		return $this->uninstallModuleTab($this->module_tab)
			&& $this->delAdminImages(array('yandex.ico'))
			&& parent::uninstall();
	}

	private function installModuleTab($tab_name, $parent_tab_name)
	{
		$tab_img_name = $tab_name.'.gif';
		@copy(_PS_MODULE_DIR_.$this->name.'/'.$tab_img_name, _PS_IMG_DIR_.'t/'.$tab_img_name);
		$tab = new Tab();

		// subtab name in different languages
		$langs = Language::getLanguages();
		foreach ($langs as $l)
			$tab->name[$l['id_lang']] = $this->l('Orders editor');

		$tab->class_name = $tab_name;
		$tab->module = $this->name;

		$parent_tab_id = Tab::getIdFromClassName($parent_tab_name);
		$tab->id_parent = $parent_tab_id;

		return $tab->save();
	}

	private function copyAdminImages($images)
	{
		foreach ($images as $i)
		{
			$copy_from = _PS_MODULE_DIR_.$this->name.'/'.$i;
			$copy_to = _PS_IMG_DIR_.'admin/'.$i;
			@copy($copy_from, $copy_to);
		}

		return true;
	}

	private function delAdminImages($images)
	{
		foreach ($images as $i)
		{
			$filename = _PS_IMG_DIR_.'admin/'.$i;
			@unlink($filename);
		}

		return true;
	}

	private function uninstallModuleTab($tab_name)
	{
		$tab_id = Tab::getIdFromClassName($tab_name);
		if ($tab_id != 0)
		{
			$tab = new Tab($tab_id);
			$tab->delete();
			@unlink(_PS_IMG_DIR_.'t/'.$tab_name.'.gif');

			return true;
		}

		return false;
	}

	private function createTable()
	{
		$sql = '
			CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'order_payment` (
			  `id_order_payment` int(11) NOT NULL AUTO_INCREMENT,
			  `id_order` int(10) unsigned NOT NULL,
			  `id_currency` int(10) unsigned NOT NULL,
			  `amount` decimal(10,2) NOT NULL,
			  `payment_method` varchar(255) NOT NULL,
			  `conversion_rate` decimal(13,6) NOT NULL DEFAULT 1.000000,
			  `transaction_id` varchar(254) DEFAULT NULL,
			  `card_number` varchar(254) DEFAULT NULL,
			  `card_brand` varchar(254) DEFAULT NULL,
			  `card_expiration` char(7) DEFAULT NULL,
			  `card_holder` varchar(254) DEFAULT NULL,
			  `date_add` datetime NOT NULL,
			  PRIMARY KEY (`id_order_payment`),
			  KEY `id_order` (`id_order`)
			) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
		';

		return Db::getInstance()->Execute($sql);
	}

	public function getContent()
	{
		global $cookie;

		$output = '';
		$iso_code = Language::getIsoById((int)$cookie->id_lang);

		$modulez_url = 'http://prestashop.modulez.ru'.($iso_code == 'ru' ? '/ru/' : '/en/');
		$module_page = $modulez_url.'10-orders-editor-module-for-prestashop.html';

		$output .= '
			<br />
			<fieldset style="width: 400px;">
				<legend><img src="'.$this->_path.'logo.gif" /> '.$this->l('Module info').'</legend>
				<div id="dev_div">
					<span><b>'.$this->l('Version').':</b> '.$this->version.'</span><br/>
					<span><b>'.$this->l('License').':</b> Academic Free License (AFL 3.0)<br/>
					<span><b>'.$this->l('Website').':</b> <a class="link" href="'.$module_page.'" target="_blank">prestashop.modulez.ru</a></span><br/>
					<span><b>'.$this->l('Author').':</b> zapalm <img src="../modules/'.$this->name.'/zapalm24x24.jpg" /><br/><br/>
					<img style="width: 250px;" alt="'.$this->l('Website').'" src="../modules/'.$this->name.'/marketplace-logo.png" />
				</div>
			</fieldset>
			<br class="clear" />
		';

		return $output;
	}
}