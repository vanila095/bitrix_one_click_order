<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();
// основной класс, является оболочкой компонента унаследованного от CBitrixComponent

global $USER;
\Bitrix\Main\Loader::includeModule('iblock');
\Bitrix\Main\Loader::includeModule('sale');
\Bitrix\Main\Loader::includeModule('catalog');

class CustomAjaxController extends \Bitrix\Main\Engine\Controller
{
	// обязательный метод предпроверки данных
	public function configureActions()
	{
		return [
			'test' => [
				'prefilters' => [],
				'postfilters' => []
			]
		];
	}
	
	// основной метод исполнитель
	public function testAction(?string $name, ?string $tel, ?string $email)
	{
		//ищем товар
		$item_id = 317;
		$dbItems = CIBlockElement::GetList(
			array(),
			array('ID' => IntVal($item_id)),
			false,
			false,
			array('ID', 'IBLOCK_ID', 'NAME')
		);
		
		//ищем пользователя
		$phone = \Bitrix\Main\UserPhoneAuthTable::normalizePhoneNumber($tel);
		$PhoneAuthTable = \Bitrix\Main\UserPhoneAuthTable::getList($parameters = array(
			'filter'=>array('PHONE_NUMBER' => $phone) 
		 ));
		 if($item = $PhoneAuthTable->fetch()){
			$userId = $item["USER_ID"];
		 } else {
			 $user = new CUser;
			 if (!$email) {
				$email = 'anonim@anonim.ru'; 
			 }
			 
			 $arFields = Array(
				 "EMAIL"             => $email,
				 "LOGIN"             => $phone,
				 "PHONE_NUMBER"      => $phone,
				 "PASSWORD"          => "123456",
				 "CONFIRM_PASSWORD"  => "123456",
			 );
			 $userId = $user->Add($arFields);
		 }

		if ($arItem = $dbItems->GetNext()) {
			$arItem['PRICE'] = CCatalogProduct::GetOptimalPrice($arItem['ID'], 1);
			// Добавляем товар в корзину
			$basket = \Bitrix\Sale\Basket::create(SITE_ID);
			$basketItem = $basket->createItem("catalog", $arItem['ID']);
			$basketItem->setFields(
				array(
					'PRODUCT_ID' => $arItem['ID'],
					'NAME' => $arItem['NAME'],
					'CURRENCY' => $arItem['PRICE']['RESULT_PRICE']['CURRENCY'],
					'QUANTITY' => 1,
					'PRODUCT_PROVIDER_CLASS' => 'CCatalogProductProvider',
				)
			);
		
			// Создаем заказ
			$order = \Bitrix\Sale\Order::create(SITE_ID, $userId);
			$order->setPersonTypeId(1); // Физ. лицо
			$order->setBasket($basket);
		
			
			//Ищем по коду
			$shipmentResult = \Bitrix\Sale\Delivery\Services\Table::getList(array(
				'filter'  => array(
					'XML_ID' => 'ONE_CLICK_ORDER',
				)
			));
			$shipmentId = $shipmentResult->fetch()['ID'];
			
			// Создаём отгрузку
			$shipmentCollection = $order->getShipmentCollection();
			$shipment = $shipmentCollection->createItem();
			$service = \Bitrix\Sale\Delivery\Services\Manager::getById($shipmentId);
			$shipment->setFields(array(
				'DELIVERY_ID' => $service['ID'],
				'DELIVERY_NAME' => $service['NAME'],
			));
			$shipmentItemCollection = $shipment->getShipmentItemCollection();
			$arResult['basket'] = $basket;
			foreach ($basket as $item) {
				$shipmentItem = $shipmentItemCollection->createItem($item);
				$shipmentItem->setQuantity($item->getQuantity());
			}
			
			//Ищем платежную систему
			$paySystemResult = \Bitrix\Sale\PaySystem\Manager::getList(array(
				'filter'  => array(
					'CODE' => 'ONE_CLICK_ORDER',
				)
			));
			$paySystemId = $paySystemResult->fetch()['ID'];
		
			//Оплата
			$paymentCollection = $order->getPaymentCollection();
			$payment = $paymentCollection->createItem();
			$paySystemService = \Bitrix\Sale\PaySystem\Manager::getObjectById($paySystemId);
			$payment->setFields(array(
				'PAY_SYSTEM_ID' => $paySystemService->getField("PAY_SYSTEM_ID"),
				'PAY_SYSTEM_NAME' => $paySystemService->getField("NAME"),
			));
		
			// Свойства
			$propertyCollection = $order->getPropertyCollection();
			$nameProp = $propertyCollection->getPayerName();
			$nameProp->setValue(htmlspecialcharsbx($name));
			$emailProp = $propertyCollection->getUserEmail();
			$emailProp->setValue(htmlspecialcharsbx($email));
			//$locProp = $propertyCollection->getDeliveryLocation();
			//$locProp->setValue(DEFAULT_LOCATION_ID);
		
			// Сохраняем
			$order->doFinalAction(true);
			$order->save();
			echo 'New order #'.$order->getId();
			
			//отправляем в CRM через ВебХук
			$params = [
				'fields' => [
					'TITLE' => 'Заказ ' .$order->getId(),
					'IS_NEW' => 'Y'
				],
				'params' => ["REGISTER_SONET_EVENT" => "Y"]
			];
			
			$method = 'crm.deal.add';
			$webhookUrl = ''; //Адрес веб-хука crm24
			$httpClient = new HttpClient();
			$response = $httpClient->post($webhookUrl.$method, $params);
			return json_decode($response, true);
				
		}
		
	}
}