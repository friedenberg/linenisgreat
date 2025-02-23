<?php declare(strict_types=1);

$tab = new Route('resume');

$tab->renderWithExtraStylesheets(
  'resume',
  [
    'resume',
  ],
  [
    "resume" => file_get_contents(
      __DIR__ . "/resume.html",
    ),
  ],
);
