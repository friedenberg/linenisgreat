<?php declare(strict_types=1);

$options =  array('extension' => '.html.mustache');

$m = new Mustache_Engine(array(
  'loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__) . '/../protected/lib/templates', $options),
  'entity_flags' => ENT_QUOTES,
));

$zettels = new Zettels($m);

$nav_raw = json_decode(
  file_get_contents(
    __DIR__ . "/../protected/nav.json",
  ), true
);

$nav = array_map(
  function ($value, $key) use ($zettels) {
    if (strcmp($key, $zettels->getSite()) == 0) {
      $value["active"] = true;
    }

    return $value;
  },
  $nav_raw,
  array_keys($nav_raw),
);

$template = 'index';
$template_args = [
  'nav' => array_values($nav),
  'meta' => $zettels->getMeta($m),
  'stylesheets' => [
    "stylesheet",
    "zettels",
  ],
];

if ($zettels->isResume()) {
  $template = "resume";
  $template_args["resume"] = file_get_contents(
    __DIR__ . "/resume.html",
  );
  array_push($template_args["stylesheets"], "resume");
} else {
  $template_args["zettels"] = array_map(
    function ($zettel) {
      return $zettel->html;
    },
    $zettels->getZettels($m),
  );

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
}

$template_args['query'] = substr($_SERVER['REQUEST_URI'], 1);
echo $m->render($template, $template_args);
