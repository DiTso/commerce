//<?php
/**
 * PriceFormat
 * 
 * Format price using predefined settings
 *
 * @category    snippet
 * @version     0.1.0
 * @author      mnoskov
 * @internal    @modx_category Commerce
 * @internal    @installset base
*/

if (!empty($modx->commerce)) {
    return $modx->commerce->formatPrice(array_shift($params));
}
