<?php declare(strict_types=1);

$options =  array('extension' => '.html.mustache');

$m = new Mustache_Engine(array(
    'loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__) . '/../protected/lib/templates', $options),
    'entity_flags' => ENT_QUOTES,
));

$zettels = new Zettels($m);
$zettels_list = $zettels->getZettels();

/* $query_elements = explode(',', $_GET['query'] ?? ''); */
/* $cocktail_for_image = $zettels_list[0]; */

/* if (!empty($query_elements)) { */
/*   foreach ($zettels_list as $cocktail) { */
/*     if ($cocktail->matches($query_elements)) { */
/*       $cocktail_for_image = $cocktail; */
/*       break; */
/*     } */
/*   } */
/* } */

echo $m->render('index', [
  'meta' => $zettels->getMeta(),
  'zettels' => $zettels_list,
  /* 'image_id' => $cocktail_for_image->getId(), */
  /* 'query' => implode(' ', $query_elements), */
]);
