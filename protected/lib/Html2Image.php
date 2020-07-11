<?php declare(strict_types=1);

class Html2Image {
  function __construct($html, $css) {
    $this->html = $html;
    $this->css = $css;
  }

  function getImage() string {
    $data = array(
      'html' => $this->html,
      'css' => $this->css
    );

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "https://hcti.io/v1/image");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

    curl_setopt($ch, CURLOPT_POST, 1);

    curl_setopt($ch, CURLOPT_USERPWD, "61360bc1-1914-49a9-949a-c6b55eb54217" . ":" . "a8628cd1-3e2d-457c-ba95-7e52ab22da36");

    $headers = array();
    $headers[] = "Content-Type: application/x-www-form-urlencoded";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
      echo 'Error:' . curl_error($ch);
    }

    curl_close ($ch);
    $res = json_decode($result,true);

    echo $res['url'];
  }
}
