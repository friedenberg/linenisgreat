<?php declare(strict_types=1);

$options =  array('extension' => '.html.mustache');

$m = new Mustache_Engine(array(
  'loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__) . '/../protected/lib/templates', $options),
  'entity_flags' => ENT_QUOTES,
));

$zettels = new Zettels($m);

echo $m->render('resume', [
  'meta' => $zettels->getMeta(),
  'resume' => file_get_contents(
    __DIR__ . "/resume.html",
  ),
]);
