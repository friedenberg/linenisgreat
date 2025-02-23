<?php declare(strict_types=1);

$options =  array('extension' => '.html.mustache');

$mustache = new Mustache_Engine(array(
  'loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__) . '/../protected/lib/templates', $options),
  'entity_flags' => ENT_QUOTES,
));

$zettels = new Tab('resume');
$nav = new Nav("resume");

$template = 'resume';
$template_args = [
  'nav' => array_values($nav->tiles),
  'meta' => $zettels->getMeta(),
  'stylesheets' => [
    "stylesheet",
    "zettels",
    "fonts",
  ],
  "resume" => file_get_contents(
    __DIR__ . "/resume.html",
  ),
];

$template_args['query'] = substr($_SERVER['REQUEST_URI'], 1);
echo $mustache->render($template, $template_args);
