<?php

$_SERVER['DOCUMENT_ROOT'] = str_replace('/local/cron', '', __DIR__);
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
@set_time_limit(0);
ini_set('memory_limit', '2048M');

use Citfact\SiteCore\Core;
use Citfact\SiteCore\Tools\HLBlock;
use Bitrix\Catalog\Product\SubscribeManager;
use Bitrix\Catalog\SubscribeTable;


$offersChangedPriceEntity = (new HLBlock())->getHlEntityByName(Core::HLBLOCK_CODE_PRODUCT_CHANGED_PRICES);

$productOffersIds = []; // ключ - ID оффера, значение - ID товара

$listOffersDelete = []; // офферы, которые нужно удалить из таблицы HL

$listOffersIdsHL = []; // соответствие id оффера ID элемента из HL

$offersChangedPrice = $offersChangedPriceEntity::GetList([
    'select' => ['ID','UF_PRODUCT_ID'],
    'order' => [],
    'filter' => []
]);
while ($offer = $offersChangedPrice->fetch()) {
    $listOffersIdsHL[$offer['UF_PRODUCT_ID']] = $offer['ID'];
    $productOffersIds[$offer['UF_PRODUCT_ID']] = "";
}

$productList = [];

$arParameters = ["FIELDS" => ["EMAIL", "ID"], "SELECT" => ['UF_FAVORITS']];
$users = CUser::GetList(($by = "id"), ($order = "asc"), ['ACTIVE' => 'Y'], $arParameters);
while ($user = $users->fetch()) {
    $filter['USER_ID'] = $user['ID'];
    $filter['SITE_ID'] = 's1';
    /* проверяем на какие товары подписан пользователь (/bitrix/admin/cat_subscription_list.php?lang=ru) */
    $subscribeEntity = SubscribeTable::getList(['select' => ['ITEM_ID'], 'filter' => $filter]);
    $listProductsReducedPrice = "";
    $listUserProducts = []; // список товаров пользователя, которые были добавлены в текст письма

    while ($subscribe = $subscribeEntity->fetch()) {

        if (!array_key_exists($subscribe['ITEM_ID'], $productOffersIds)) {
            continue;
        }

        /* сохраним информацию о товаре в массив */
        if (empty($productOffersIds[$subscribe['ITEM_ID']])) {
            $productInfo = CCatalogSku::GetProductInfo($subscribe['ITEM_ID']);
            if (array_key_exists($productInfo['ID'], $productList)) {
                $listOffersDelete[]  = $listOffersIdsHL[$subscribe['ITEM_ID']];
                $productOffersIds[$subscribe['ITEM_ID']] = $productInfo['ID'];
            } elseif (!empty($productInfo['ID'])) {
                $productDetailInfo = CIBlockElement::GetByID($productInfo['ID'])->GetNext();
                if (!empty($productDetailInfo)) {
                    $listOffersDelete[]  = $listOffersIdsHL[$subscribe['ITEM_ID']];
                    $productOffersIds[$subscribe['ITEM_ID']] = $productInfo['ID'];
                    $productList[$productInfo['ID']] = [
                        'NAME' => $productDetailInfo['NAME'],
                        'DETAIL_PAGE_URL' => $productDetailInfo['DETAIL_PAGE_URL']
                    ];
                }
            }
        }

        /* есть ли информация о товаре и была ли она добавлена в тест письма */
        if (!empty($productOffersIds[$subscribe['ITEM_ID']]) && !in_array($productOffersIds[$subscribe['ITEM_ID']], $listUserProducts)) {
            $listUserProducts[] = $productOffersIds[$subscribe['ITEM_ID']];
            $itemProductList = $productList[$productOffersIds[$subscribe['ITEM_ID']]];
            $listProductsReducedPrice .= 'https://site.ru' . $itemProductList['DETAIL_PAGE_URL'] . PHP_EOL;
        }
    }
    if (!empty($listProductsReducedPrice)) {
        $arEventFields =[
            'EMAIL_TO'=> $user['EMAIL'],
            'LIST_GOODS' => $listProductsReducedPrice
        ];
        CEvent::Send("CHANGE_PRICE_GOODS", SITE_ID, $arEventFields,'N');
    }
}

if (!empty($listOffersDelete)){
    foreach($listOffersDelete as $offerDelete){
        $offersChangedPriceEntity::delete(['ID'=>$offerDelete]);
    }
}



