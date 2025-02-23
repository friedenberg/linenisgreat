<?php declare(strict_types=1);

$options =  array('extension' => '.html.mustache');

$mustache = new Mustache_Engine(array(
  'loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__) . '/../protected/lib/templates', $options),
  'entity_flags' => ENT_QUOTES,
));

$zettels = new Tab('meet');

$nav_raw = json_decode(
  file_get_contents(
    __DIR__ . "/../protected/nav.json",
  ), true
);

$nav = array_map(
  function ($value, $key) use ($zettels) {
    if (strcmp($key, $zettels->title) == 0) {
      $value["active"] = true;
    }

    return $value;
  },
  $nav_raw,
  array_keys($nav_raw),
);

$template = 'meet';
$template_args = [
  'nav' => array_values($nav),
  'meta' => $zettels->getMeta(),
  'stylesheets' => [
    "stylesheet",
    "zettels",
    "fonts",
  ],
];

$template_args['query'] = substr($_SERVER['REQUEST_URI'], 1);
echo $mustache->render($template, $template_args);
