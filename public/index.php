<?php declare(strict_types=1);

$options =  array('extension' => '.html.mustache');

$m = new Mustache_Engine(array(
    'loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__) . '/../protected/lib/templates', $options),
    'entity_flags' => ENT_QUOTES,
));

$cocktail = [
  'name' => 'Monkey Gland',
  'kind' => 'sour',
  'ingredients' => "gin<br>orange juice<br>absinthe<br>grenadine",
  'proportions' => "5 parts<br>3 parts<br>2 dashes<br>2 dashes",
];

echo $m->render('index', $cocktail);
