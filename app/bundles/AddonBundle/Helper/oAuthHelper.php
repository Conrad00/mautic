<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\AddonBundle\Helper;
use Mautic\AddonBundle\Integration\AbstractIntegration;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class oAuthHelper
 *
 * Portions modified from https://code.google.com/p/simple-php-oauth/
 *
 * @package Mautic\AddonBundle\Helper
 */
class oAuthHelper
{
    private $clientId;

    private $clientSecret;

    private $accessToken;

    private $accessTokenSecret;

    private $callback;

    private $settings;

    private $request;

    public  function __construct (AbstractIntegration $integration, Request $request, $settings = array())
    {
        $keys                    = $integration->getDecryptedApiKeys();
        $this->clientId          = $keys[$integration->getClientIdKey()];
        $this->clientSecret      = $keys[$integration->getClientSecretKey()];
        $authToken               = $integration->getAuthTokenKey();
        $this->accessToken       = (isset($keys[$authToken])) ? $keys[$authToken] : '';
        $this->accessTokenSecret = (isset($settings['token_secret'])) ? $settings['token_secret'] : '';
        $this->callback          = $integration->getAuthCallbackUrl();
        $this->settings          = $settings;
        $this->request           = $request;
    }

    /**
     * @param $url
     * @param $parameters
     * @param $method
     *
     * @return array
     */
    public function getAuthorizationHeader($url, $parameters, $method)
    {
        //Get standard OAuth headers
        $headers = $this->getOauthHeaders();

        if (!empty($this->settings['include_verifier']) && $this->request->query->has('oauth_verifier')) {
            $headers['oauth_verifier'] = $this->request->query->get('oauth_verifier');
        }

        //Add the parameters
        $headers                    = array_merge($headers, $parameters);
        $base_info                  = $this->buildBaseString($url, $method, $headers);
        $composite_key              = $this->getCompositeKey();
        $headers['oauth_signature'] = base64_encode(hash_hmac('sha1', $base_info, $composite_key, true));

        return array($this->buildAuthorizationHeader($headers), 'Expect:');
    }

    /**
     * Get composite key for OAuth 1 signature signing
     *
     * @return string
     */
    private function getCompositeKey ()
    {
        if (isset($this->accessTokenSecret) && strlen($this->accessTokenSecret) > 0) {
            $composite_key = $this->encode($this->clientSecret) . '&' . $this->encode($this->accessTokenSecret);
        } else {
            $composite_key = $this->encode($this->clientSecret) . '&';
        }

        return $composite_key;
    }

    /**
     * Get OAuth 1.0 Headers
     *
     * @return array
     */
    private function getOauthHeaders ()
    {
        $oauth = array(
            'oauth_consumer_key'     => $this->clientId,
            'oauth_nonce'            => $this->generateNonce(),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => time(),
            'oauth_version'          => '1.0'
        );
        if (isset($this->accessToken)) {
            $oauth['oauth_token'] = $this->accessToken;
        }

        if (!empty($this->callback)) {
            $oauth['oauth_callback'] = $this->callback;
        }

        return $oauth;
    }

    /**
     * Build base string for OAuth 1 signature signing
     *
     * @param $baseURI
     * @param $method
     * @param $params
     *
     * @return string
     */
    private function buildBaseString ($baseURI, $method, $params)
    {
        $r = $this->normalizeParameters($params);

        return $method . '&' . $this->encode($baseURI) . '&' . $this->encode($r);
    }

    /**
     * Build header for OAuth 1 authorization
     *
     * @param $oauth
     *
     * @return string
     */
    private function buildAuthorizationHeader ($oauth)
    {
        $r      = 'Authorization: OAuth ';
        $values = $this->normalizeParameters($oauth, true, true);
        $r .= implode(', ', $values);

        return $r;
    }

    /**
     * Normalize parameters
     *
     * @param      $parameters
     * @param bool $encode
     * @param bool $returnarray
     *
     * @return string
     */
    private function normalizeParameters ($parameters, $encode = false, $returnarray = false, $normalized = array(), $key = '')
    {
        //Sort by key
        ksort($parameters);

        foreach ($parameters as $k => $v) {
            if (is_array($v)) {
                $normalized = $this->normalizeParameters($v, $encode, true, $normalized, $k);
            } else {
                if ($key) {
                    //Multidimensional array; using foo=baz&foo=bar rather than foo[bar]=baz&foo[baz]=bar as this is
                    //what the server expects when creating the signature
                    $k = $key;
                }
                if ($encode) {
                    $normalized[] = $this->encode($k) . '=' . $this->encode($v);
                } else {
                    $normalized[] = $k . '=' . $v;
                }
            }
        }

        return $returnarray ? $normalized : implode('&', $normalized);
    }

    /**
     * Returns an encoded string according to the RFC3986.
     *
     * @param $string
     */
    private function encode ($string)
    {
        return str_replace('%7E', '~', rawurlencode($string));
    }

    /**
     * OAuth1.0 nonce generator
     *
     * @param int $bits
     *
     * @return string
     */
    private function generateNonce ($bits = 64)
    {
        $result          = '';
        $accumulatedBits = 0;
        $random          = mt_getrandmax();
        for ($totalBits = 0; $random != 0; $random >>= 1) {
            ++$totalBits;
        }
        $usableBits = intval($totalBits / 8) * 8;

        while ($accumulatedBits < $bits) {
            $bitsToAdd = min($totalBits - $usableBits, $bits - $accumulatedBits);
            if ($bitsToAdd % 4 != 0) {
                // add bits in whole increments of 4
                $bitsToAdd += 4 - $bitsToAdd % 4;
            }

            // isolate leftmost $bits_to_add from mt_rand() result
            $moreBits = mt_rand() & ((1 << $bitsToAdd) - 1);

            // format as hex (this will be safe)
            $format_string = '%0' . ($bitsToAdd / 4) . 'x';
            $result .= sprintf($format_string, $moreBits);
            $accumulatedBits += $bitsToAdd;
        }

        return $result;
    }
}