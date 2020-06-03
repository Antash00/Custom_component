<?

use Bitrix\Currency\CurrencyManager;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Delivery;
use Bitrix\Sale\Order;
use Bitrix\Sale\PaySystem;

Loader::includeModule("sale");

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
}

class FastOrderComponent extends CBitrixComponent
{
    protected $product;

    public function onIncludeComponentLang()
    {
        $this->includeComponentLang(basename(__FILE__));
        Loc::loadMessages(__FILE__);
    }

    public function executeComponent()
    {
        $this->getProduct($this->arParams["PRODUCT_ID"]);
        if (isset($_POST['submit'])) {
            $this->AddToBasket($_POST);
        }
        $this->includeComponentTemplate($this->page);
    }

    protected function getProduct($productid)
    {
        if (!empty($productid)) {
            $arFilter = Array(
                "IBLOCK_ID" => $this->arParams["IBLOCK_ID"],
                "ID"        => $productid,
                "ACTIVE"    => "Y",
            );

            $arSelect = array(
                'ID',
                'NAME',
                'PREVIEW_PICTURE',
                'PROPERTY_SALELEADER',
                'PROPERTY_NEWPRODUCT',
                'PROPERTY_SPECIALOFFER',
                'PROPERTY_UNDER_THE_ORDER',
            );

            $result = CIBlockElement::GetList(Array("SORT" => "ASC"), $arFilter, false, false, $arSelect);
            if ($ar_res = $result->Fetch()) {
                $this->product = $ar_res;
            }
            $this->getPrice($productid);
            if (!empty($this->product['PREVIEW_PICTURE'])) {
                $this->getImage($this->product['PREVIEW_PICTURE']);
            }
            $this->arResult = $this->product;
        }
    }

    public function getPrice($productid)
    {
        global $USER;
        $price = CCatalogProduct::GetOptimalPrice($productid, 1, $USER->GetUserGroupArray());
        $this->product["PRICE"] = $price;
    }

    protected function getImage($imageid)
    {
        $this->product['PREVIEW_PICTURE'] = CFile::GetPath($imageid);
    }

    protected function AddToBasket($POST)
    {
        $siteId = Context::getCurrent()->getSite();
        if (empty(trim($POST['email']))) {
            $this->arResult["ERRORS"]["MALE_ERROR"] = "Не введена почта";
        }
        if (!filter_var($POST['email'], FILTER_VALIDATE_EMAIL)) {
            $this->arResult["ERRORS"]["VALIDATE_MALE_ERROR"] = "E-mail адрес указан верно";
        }
        if (empty(trim($POST['phone']))) {
            $this->arResult["ERRORS"]["PHONE_ERROR"] = "Не введен телефон";
        }
        if (empty(trim($POST['name']))) {
            $this->arResult["ERRORS"]["NAME_ERROR"] = "Не введено имя";
        }
        if (empty($POST['agreement'])) {
            $this->arResult["ERRORS"]["AGREEMENT_ERROR"] = "Не приято пользовательское соглашение";
        }
        if (!$this->arResult["ERRORS"]) {
            $userid = $this->CheckUser($POST['email'], $POST['name'], $POST["phone"], $siteId);
            /*Получаем текущую валюту*/
            $currencyCode = CurrencyManager::getBaseCurrency();
            $order = Order::create($siteId, $userid);
            $order->setPersonTypeId($this->arParams["PERSON_TYPE"]);
            $order->setField('CURRENCY', $currencyCode);
            //Создаем текущую корзину, для того чтобы скопировать и удалить товары, лежащие в ней в данный момент
            $basket = \Bitrix\Sale\Basket::loadItemsForFUser(\Bitrix\Sale\Fuser::getId(), $siteId);
            $dbRes = \Bitrix\Sale\Basket::getList(
                [
                    'select' => ['ID', 'PRODUCT_ID', 'NAME', 'QUANTITY'],
                    'filter' => [
                        '=FUSER_ID' => \Bitrix\Sale\Fuser::getId(),
                        '=ORDER_ID' => null,
                        '=LID'      => $siteId,
                        '=CAN_BUY'  => 'Y',
                    ],
                ]
            );

            /*Копируем и удаляем товары */
            while ($item_product = $dbRes->fetch()) {
                $old_items[] = $item_product;
                $basketItem = $basket->getItemById($item_product["ID"]);
                $result = $basketItem->delete();
                if ($result->isSuccess()) {
                    $basket->save();
                }
            }
            //Пересоздаем корзину и добавляем наш товар
            $basket = Basket::create($siteId);
            $item = $basket->createItem('catalog', $this->product["ID"]);
            $item->setFields(
                array(
                    'QUANTITY'               => 1,
                    'CURRENCY'               => $currencyCode,
                    'LID'                    => $siteId,
                    'PRODUCT_PROVIDER_CLASS' => '\CCatalogProductProvider',
                )
            );
            //Добавляем нашу корзину в заказ
            $order->setBasket($basket);
            //Получаем отгрузки
            $shipmentCollection = $order->getShipmentCollection();
            $shipment = $shipmentCollection->createItem();
            // Берем выбранный в настройках способ доставки
            $service = Delivery\Services\Manager::getById(
                $this->arParams["DELIVERY_TYPE"]
            );
            $shipment->setFields(
                array(
                    'DELIVERY_ID'   => $service['ID'],
                    'DELIVERY_NAME' => $service['NAME'],
                )
            );
            //Устанавливаем товару отгрузку
            $shipmentItemCollection = $shipment->getShipmentItemCollection();
            $shipmentItem = $shipmentItemCollection->createItem($item);
            $shipmentItem->setQuantity($item->getQuantity());
            //Добавляем способ оплаты в заказ
            $paymentCollection = $order->getPaymentCollection();
            $payment = $paymentCollection->createItem();
            $paySystemService = PaySystem\Manager::getObjectById($this->arParams["PAYMENT_METHODS"]);
            $payment->setFields(
                array(
                    'PAY_SYSTEM_ID'   => $paySystemService->getField("PAY_SYSTEM_ID"),
                    'PAY_SYSTEM_NAME' => $paySystemService->getField("NAME"),
                )
            );
            // Добавляем свойства
            $propertyCollection = $order->getPropertyCollection();
            $phoneProp = $propertyCollection->getPhone();
            $phoneProp->setValue($POST["phone"]);
            $nameProp = $propertyCollection->getPayerName();
            $nameProp->setValue($POST["name"]);
            $emailProp = $propertyCollection->getUserEmail();
            $emailProp->setValue($POST["email"]);
            // Сохраняем и формляем заказ
            $order->doFinalAction(true);
            $result = $order->save();
            if (!$result->isSuccess()) {
                $this->arResult["ERRORS"][] = $result->getErrors();
            } elseif (empty($this->arResult["ERRORS"])) {
                $this->arResult["SUCCESS"] = true;
            }

            /*Создаем зановово корзину и возвращаем товары на место*/
            if (!empty($old_items)) {
                $basket = Basket::create($siteId);
                foreach ($old_items as $old_item) {
                    $item = $basket->createItem('catalog', $old_item["PRODUCT_ID"]);
                    $item->setFields(
                        array(
                            'QUANTITY'               => $old_item['QUANTITY'],
                            'CURRENCY'               => $currencyCode,
                            'LID'                    => $siteId,
                            'PRODUCT_PROVIDER_CLASS' => '\CCatalogProductProvider',
                        )
                    );
                }
                $basket->save();
            }
        }
    }

    protected function CheckUser($mail, $name, $phone, $siteid)
    {
        $user = new CUser;
        $rsUser = $user->GetByLogin($mail);
        if ($arUser = $rsUser->Fetch()) {
            $userid = $arUser["ID"];
        } else {
            $password_min_length = 6;
            $password_chars = array(
                "abcdefghijklnmopqrstuvwxyz",
                "ABCDEFGHIJKLNMOPQRSTUVWXYZ",
                "0123456789",
            );
            $password_chars[] = ",.<>/?;:'\"[]{}\|`~!@#\$%^&*()-_+=";
            $pass = randString($password_min_length + 2, $password_chars);
            $arFields = Array(
                "NAME"             => $name,
                "EMAIL"            => $mail,
                "LOGIN"            => $mail,
                "LID"              => $siteid,
                "PHONE"            => $phone,
                "ACTIVE"           => "Y",
                "PASSWORD"         => $pass,
                "CONFIRM_PASSWORD" => $pass,
            );

            $userid = $user->Add($arFields);
        }

        return $userid;
    }
}