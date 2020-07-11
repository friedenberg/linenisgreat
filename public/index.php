<?php declare(strict_types=1);

$options =  array('extension' => '.html.mustache');

$m = new Mustache_Engine(array(
    'loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__) . '/../protected/lib/templates', $options),
    'entity_flags' => ENT_QUOTES,
));

$cocktails = new Cocktails($m);
$today_cocktail = $cocktails->getTodayCocktail();

echo $m->render('index', $today_cocktail);
