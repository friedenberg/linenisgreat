<?php declare(strict_types=1);

$options =  array('extension' => '.html.mustache');

$m = new Mustache_Engine(array(
    'loader' => new Mustache_Loader_FilesystemLoader(__DIR__ . '/../protected/lib/templates', $options),
    'entity_flags' => ENT_QUOTES,
));

$cocktails = new Cocktails($m);

$today_cocktail = $cocktails->getTodayCocktail();
$url = $today_cocktail->getImageUrl();

header("Location: $url", true, 302);
//todo set cache control
exit;
