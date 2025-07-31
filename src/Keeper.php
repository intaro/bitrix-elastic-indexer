<?php

namespace Sheerockoff\BitrixElastic;

use _CIBElement;
use Bitrix\Catalog\Model\Price;
use CCatalogStoreProduct;
use CCatalogProduct;
use CIBlockElement;
use CIBlockSection;
use CModule;
use Elasticsearch\Client;
use Exception;

class Keeper
{
    /** @var Client */
    private $elastic;

    public function __construct(Client $elastic)
    {
        $this->elastic = $elastic;
    }

    public function getElementRawData(_CIBElement $element, array $skipProps = [], array $skipFields = []): array
    {
        $data = [];

        foreach ($element->GetFields() as $field => $value) {
            if ($skipFields && in_array($field, $skipFields, true)) {
                continue;
            }

            if (strpos($field, '~') === false) {
                $data[$field] = $value;
            }
        }

        foreach ($element->GetProperties() as $property) {
            if ($skipProps && in_array($property['CODE'], $skipProps, true)) {
                continue;
            }

            if ($property['PROPERTY_TYPE'] === 'L') {
                $data['PROPERTY_' . $property['CODE']] = $property['VALUE_ENUM_ID'];
                $data['PROPERTY_' . $property['CODE'] . '_VALUE'] = $property['VALUE'];
            } else {
                $data['PROPERTY_' . $property['CODE']] = $property['VALUE'];
            }
        }

        $groups = [];
        $navChain = [];
        $rs = CIBlockElement::GetElementGroups($element->fields['ID']);
        while ($group = $rs->Fetch()) {
            $groups[] = $group;
            $navChainRs = CIBlockSection::GetNavChain($group['IBLOCK_ID'], $group['ID']);
            while ($chain = $navChainRs->Fetch()) {
                $navChain[] = $chain;
            }
        }

        $data['GROUP_IDS'] = array_map(function ($group) {
            return (int)$group['ID'];
        }, $groups);

        $data['GROUP_CODES'] = array_values(array_filter(array_map(function ($group) {
            return $group['CODE'];
        }, $groups)));

        $data['NAV_CHAIN_IDS'] = array_map(function ($group) {
            return (int)$group['ID'];
        }, $navChain);

        $data['NAV_CHAIN_CODES'] = array_values(array_filter(array_map(function ($group) {
            return $group['CODE'];
        }, $navChain)));

        if (CModule::IncludeModule('catalog')) {
            $rs = CCatalogStoreProduct::GetList(null, ['PRODUCT_ID' => $element->fields['ID']]);
            while ($entry = $rs->Fetch()) {
                $data['CATALOG_STORE_AMOUNT_' . $entry['STORE_ID']] = $entry['AMOUNT'];
            }

            /** @noinspection PhpUnhandledExceptionInspection */
            $rs = Price::getList(['filter' => ['PRODUCT_ID' => $element->fields['ID']]]);
            while ($entry = $rs->fetch()) {
                $data['CATALOG_PRICE_' . $entry['CATALOG_GROUP_ID']] = $entry['PRICE'] ?? null;
                $data['CATALOG_CURRENCY_' . $entry['CATALOG_GROUP_ID']] = $entry['CURRENCY'] ?? null;
            }

            $rs = CCatalogProduct::GetList(
                [],
                [
                    'ID' => $element->fields['ID']
                ],
                false,
                false,
                [
                    'WEIGHT',
                    'WIDTH',
                    'HEIGHT',
                    'LENGTH'
                ]
            );
            if ($entry = $rs->Fetch()) {
                $data['WEIGHT'] = $entry['WEIGHT'];
                $data['WIDTH'] = $entry['WIDTH'];
                $data['HEIGHT'] = $entry['HEIGHT'];
                $data['LENGTH'] = $entry['LENGTH'];
            }
        }

        return $data;
    }

    /**
     * @throws Exception
     */
    public function normalizeData(IndexMapping $mapping, array $data): array
    {
        $normalizedData = [];

        foreach ($mapping->getProperties() as $key => $propertyMapping) {
            if ($propertyMapping->get('type') === 'alias') {
                continue;
            }

            // Учти, значение $data[$key] м.б. null\false и т.п. для кейса удаления\обнуления.
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $rawValue = $data[$key] ?: null;

            if (is_array($rawValue) && array_key_exists('TEXT', $rawValue)) {
                $rawValue = $rawValue['TEXT'];
            }

            if (is_array($rawValue)) {
                $value = array_map(function ($v) use ($propertyMapping) {
                    return $propertyMapping->normalizeValue($v);
                }, $rawValue);
            } else {
                $value = $propertyMapping->normalizeValue($rawValue);
            }

            $normalizedData[$key] = $value;
        }

        return $normalizedData;
    }

    /**
     * Updates a document with a script or partial document.
     *
     * $additionalParams['refresh']                 = (enum) If `true` then refresh the affected shards to make this operation visible to search, if `wait_for` then wait for a refresh to make this operation visible to search, if `false` (the default) then do nothing with refreshes. (Options = true,false,wait_for)
     * $additionalParams['wait_for_active_shards']  = (string) Sets the number of shard copies that must be active before proceeding with the update operation. Defaults to 1, meaning the primary shard only. Set to `all` for all shard copies, otherwise set to any non-negative value less than or equal to the total number of copies for the shard (number of replicas + 1)*
     * $additionalParams['_source']                 = (list) True or false to return the _source field or not, or a list of fields to return
     * $additionalParams['_source_excludes']        = (list) A list of fields to exclude from the returned _source field
     * $additionalParams['_source_includes']        = (list) A list of fields to extract and return from the _source field
     * $additionalParams['lang']                    = (string) The script language (default: painless)
     * $additionalParams['routing']                 = (string) Specific routing value
     * $additionalParams['timeout']                 = (time) Explicit operation timeout
     * $additionalParams['if_seq_no']               = (number) only perform the update operation if the last operation that has changed the document has the specified sequence number
     * $additionalParams['if_primary_term']         = (number) only perform the update operation if the last operation that has changed the document has the specified primary term
     * $additionalParams['require_alias']           = (boolean) When true, requires destination is an alias. Default is false
     *
     * @param array $additionalParams Associative array of parameters
     * @return bool
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/master/docs-update.html
     */
    public function put(string $index, ?int $id, array $data, array $additionalParams = []): bool
    {
        $params = [
            'index' => $index,
            'id' => $id,
            'type' => '_doc',
            'body' => [
                'doc' => $data,
                'doc_as_upsert' => true,
            ],
            'retry_on_conflict' => 3,
        ];

        if (!empty($additionalParams['refresh']) && in_array($additionalParams['refresh'], [true, false, 'wait_for'], true)) {
            $params['refresh'] = $additionalParams['refresh'];
        }

        $response = $this->elastic->update($params);

        return isset($response['result']) && in_array($response['result'], ['created', 'updated', 'noop']);
    }
}