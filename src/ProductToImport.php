<?php

namespace Kolstad;

class ProductToImport
{
    public array $wooUpdateArray;

    public function __construct($array)
    {
        $this->avgCost = $array[0];
        $this->importToWoo = $array[1];
        $this->wooId = $array[2];
        $this->shouldUpdate = $array[3];
        $categories = explode('>', $array[4]);
        foreach ($categories as $key => $category) {
            $trimmed = trim(str_replace('>', '', $category));
            if ($trimmed != '') {
                $categories[$key] = $trimmed;
            }else {
                unset($categories[$key]);
            }
        }
        $this->categories = $categories;
        $this->lastCategory = array_pop($categories);
        $this->name = $array[5];
        $this->longDescription = $array[6];
        $this->crossReference = $array[7];
        $this->sku = $array[8];
        $this->price1 = $array[9];
        $this->price2 = $array[10];
        $this->price3 = $array[11];
        $this->price7 = $array[12];
        $this->quantityAvailable = $array[13];
        $this->replacementCost = $array[14];
        $this->length = $array[15];
        $this->width = $array[16];
        $this->height = $array[17];
        $this->tags = $array[18];
        $this->supplier = $array[19];
        $this->unitOfMeasure = $array[20];
        $this->modifiedDate = $array[21];
        $this->slug = $array[22];
    }

    public function import(): bool
    {
        return $this->importToWoo === 'YES';
    }

    public function update(): bool
    {
        return $this->shouldUpdate === 'YES';
    }

    public function getWooObject($wooProduct = []): array
    {
        $description = $this->longDescription;
        if ($this->crossReference) {
            $description .= '<br/><br/><br/><h5>Cross References</h5>' . $this->crossReference;
        }

        $categories = [];
        foreach ($this->category as $category) {
            if (!empty(trim($category))) {
                $categories[] = ['name' => trim($category)];
            }
        }


        if ($wooProduct) {
            unset($wooProduct['categories']);
            unset($wooProduct['tags']);
        }

        return array_merge($wooProduct, [
            'name' => $this->name,
            'type' => 'simple',
            'regular_price' => $this->price7,
            'description' => $this->longDescription,
            'categories' => $categories
        ]);
    }
}