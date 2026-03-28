<?php
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';
$cat_name = "Golf Shirts";
$terms = get_terms(array(
    'taxonomy' => 'product_cat',
    'name'     => $cat_name,
    'fields'   => 'ids',
    'hide_empty' => false,
));
print_r($terms);
