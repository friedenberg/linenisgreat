<?php

declare(strict_types=1);

class Nav
{
    public $tiles;
    /**
     * @param string $selected
     */
    public function __construct($selected = "about")
    {
        $this->tiles = json_decode(
            file_get_contents(
                __DIR__ . "/../../protected/nav.json",
            ),
            true
        );

        $this->tiles = array_map(
            function ($value, $key) use ($selected) {
                if (strcmp($key, $selected) == 0) {
                    $value["active"] = true;
                }

                return $value;
            },
            $this->tiles,
            array_keys($this->tiles),
        );
    }
}
