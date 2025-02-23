<?php declare(strict_types=1);

$options =  array('extension' => '.html.mustache');

$mustache = new Mustache_Engine(array(
  'loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__) . '/../protected/lib/templates', $options),
  'entity_flags' => ENT_QUOTES,
));

$zettels = new Tab("cocktails", __DIR__ . '/cocktails.json');
$nav = new Nav("cocktails");

$template = 'index';
$template_args = [
  'nav' => array_values($nav->tiles),
  'meta' => $zettels->getMeta(),
  'stylesheets' => [
    "stylesheet",
    "fonts",
    "zettels",
  ],
  'zettels' => array_map(
    function($zettel) {
      return $zettel->html;
    },
    $zettels->getZettels($mustache),
  ),
];

$template_args['query'] = substr($_SERVER['REQUEST_URI'], 1);
echo $mustache->render($template, $template_args);
