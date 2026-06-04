<?php

declare(strict_types=1);

namespace Card;

// Generalized from the app's original Html2Image: the hcti.io basic-auth key is
// injected (the old class referenced a gitignored global Html2ImageApiKey::KEY),
// and the network call lives behind a protected post() seam so tests can stub it
// without touching the wire.
class Html2Image
{
    public function __construct(
        private string $html,
        private string $css,
        private string $apiKey,
    ) {
    }

    /** Returns the hcti.io image URL for the html+css. */
    public function getImageUrl(): string
    {
        $body = $this->post(['html' => $this->html, 'css' => $this->css]);
        $res = json_decode($body, true);
        if (!is_array($res) || !isset($res['url'])) {
            throw new \RuntimeException('hcti.io: missing url in response');
        }
        return (string) $res['url'];
    }

    /**
     * Seam: POST form-encoded $data to hcti.io with basic-auth $apiKey and
     * return the raw response body. Overridden in tests to avoid the network.
     *
     * @param array<string,string> $data
     */
    protected function post(array $data): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://hcti.io/v1/image');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("hcti.io request failed: {$err}");
        }
        curl_close($ch);
        return (string) $result;
    }
}
