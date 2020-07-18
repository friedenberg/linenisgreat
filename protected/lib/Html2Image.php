<?php declare(strict_types=1);

class Html2Image {
  function __construct($html, $css) {
    $this->html = $html;
    $this->css = $css;
  }

  function getImage() {
    $data = array(
      'html' => $this->html,
      'css' => $this->css
    );

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "https://hcti.io/v1/image");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

    curl_setopt($ch, CURLOPT_POST, 1);

    curl_setopt($ch, CURLOPT_USERPWD, Html2ImageApiKey::KEY);

    $headers = array();
    $headers[] = "Content-Type: application/x-www-form-urlencoded";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
      echo 'Error:' . curl_error($ch);
    }

    curl_close ($ch);
    $res = json_decode($result,true);
    $url = $res['url'];

    return $url;
  }
}
