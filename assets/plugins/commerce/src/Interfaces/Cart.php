<?php

namespace Commerce\Interfaces;

interface Cart
{
    public function getItems();

    public function getTotal();

    public function get($row);

    public function setItems(array $items);

    public function add(array $item);

    public function addMultiple(array $items = []);

    public function update($row, array $attributes = []);

    public function remove($row);

    public function clean();

    public function setCurrency($code);
}
