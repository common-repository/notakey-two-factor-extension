<?php

/**
 * Class for invoking Notakey Authentication Server
 *
 * @package Two_Factor_Notakey
 */

class NotakeyTokenAcquisitionException extends Exception
{
    public function __construct($scopes, $code = 0, Throwable $previous = null)
    {
        $message = "Token request for $scopes failed";
        parent::__construct($message, $code, $previous);
    }
}

class NotakeyTokenStoreException extends Exception
{
}
class NotakeyApiException extends Exception
{
}

class NasApi
{
    private string $_api_url;
    private string $_api_client_id;
    private string $_api_client_secret;
    private string $_service_id;
    private string $_service_domain;
    private object $_ts;
    private array $_token = array();

    private const MODE_POST = 1;
    private const MODE_PUT = 2;
    private const MODE_GET = 3;
    private const MODE_DELETE = 4;

    public function __construct(string $api_url, string $client_id, string $client_secret, string $service_id, string $service_domain, object &$token_store)
    {
        $this->_api_url = $api_url;
        $this->_api_client_id = $client_id;
        $this->_api_client_secret = $client_secret;
        $this->_service_id = $service_id;
        $this->_service_domain = $service_domain;
        $this->_ts = $token_store;
    }

    private function api_url($path = '', $root = false)
    {
        $url = trim($this->_api_url, '/');

        if (!$root) {
            $url .= '/api/' . $path;
        }

        return $url;
    }

    public function get_onboarding_qr()
    {
        $onboarding_url = 'notakey://qr?a=o&k=' . rawurlencode($this->_service_id);

        if (!empty($this->_service_domain)) {
            $onboarding_url .= '&u=' . rawurlencode($this->_service_domain);
        } else {
            $onboarding_url .= '&u=' . rawurlencode($this->api_url('', true));
        }

        return $onboarding_url;
    }

    public function get_token($scopes)
    {
        $request = [
            "grant_type" => "client_credentials",
            "client_id" => $this->_api_client_id,
            "client_secret" => $this->_api_client_secret,
            "scope" => $scopes,
        ];

        // $params = [];

        // foreach ($request as $k => $v) {
        //     $params[] = $k . '=' . urlencode($v);
        // }

        // $response = \Httpful\Request::post($this->api_url('token') . '?' . implode('&', $params))
        $response = \Httpful\Request::post($this->api_url('token'))
            ->sendsJson()
            ->body($request)
            // --
            // ->expectsJSON()
            // ->authenticateWithDigest($this->_api_client_id, $this->_api_client_secret)
            // ->addHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
            // --
            ->send();

        if ($response->code != 200) {
            throw new NotakeyTokenAcquisitionException($scopes);
        }

        return $response->body;
    }

    private function bearer_token($scopes)
    {
        if (!isset($this->_token[$scopes])) {
            $this->_token[$scopes] = $this->_ts->fetch_token($scopes);

            if (!$this->_token[$scopes]) {
                $this->_token[$scopes] = $this->get_token($scopes)->access_token;
                $this->_ts->store_token($this->_token[$scopes], $scopes);
            }

            if (empty($this->_token[$scopes])) {
                throw new NotakeyTokenStoreException("Token API failure");
            }
        }

        return "Bearer " . $this->_token[$scopes];
    }

    private function req($path, $mode, $request = null, $scopes = null, $attempt = 0)
    {
        if (!isset($scopes)) {
            $scopes = 'urn:notakey:auth';
        }

        if ($mode == self::MODE_POST) {
            $response = \Httpful\Request::post($path)
                ->withAuthorization($this->bearer_token($scopes))
                ->sendsJson()
                ->body($request)
                ->send();
        }

        if ($mode == self::MODE_DELETE) {
            $response = \Httpful\Request::delete($path)
                ->withAuthorization($this->bearer_token($scopes))
                ->send();
        }

        if ($mode == self::MODE_GET) {
            $response = \Httpful\Request::get($path)
                ->withAuthorization($this->bearer_token($scopes))
                ->send();
        }

        if ($mode == self::MODE_PUT) {
            $response = \Httpful\Request::put($path)
                ->withAuthorization($this->bearer_token($scopes))
                ->sendsJson()
                ->body($request)
                ->send();
        }

        if ($attempt == 0) {
            if ($response->code == 403) {
                $this->_ts->clear_token($scopes);
                unset($this->_token[$scopes]);
                $response = $this->req($path, $mode, $request, $scopes, 1);

                if ($response->code == 403) {
                    return $response;
                }
            }
        }

        return $response;
    }

    public function auth($request)
    {
        $response = $this->req($this->api_url('v3/services/' . $this->_service_id . '/auth'), self::MODE_POST, $request);

        if ($response->code >= 400) {
            throw new NotakeyApiException("Authentication create request failure");
        }

        return $response->body;
    }

    public function service()
    {
        $response = $this->req($this->api_url('v3/services/' . $this->_service_id), self::MODE_GET);

        if ($response->code != 200) {
            throw new NotakeyApiException("Authentication service request failure HTTP:$response->code");
        }

        return $response->body;
    }

    public function user_exists($username)
    {
        return $this->get_user($username) !== false;
    }

    public function get_user($username)
    {
        $response = $this->req($this->api_url('v3/services/' . $this->_service_id . '/user/' . $username), self::MODE_GET, null, 'urn:notakey:user');

        if ($response->code == 404) {
            return false;
        }

        if ($response->code != 200) {
            throw new NotakeyApiException("User service request failure HTTP:$response->code");
        }

        return $response->body;
    }

    public function create_user($userdata)
    {
        $response = $this->req($this->api_url('v3/services/' . $this->_service_id . '/users'), self::MODE_POST, $userdata, 'urn:notakey:user urn:notakey:usermanager');

        if ($response->code != 200) {
            throw new NotakeyApiException("User create request failure HTTP:$response->code");
        }

        return $response->body;
    }

    public function delete_user($username)
    {
        $u = $this->get_user($username);
        if ($u) {
            return $this->delete_user_by_keyname($u->keyname);
        }

        return false;
    }

    public function update_user_by_keyname($user_keyname, $userdata)
    {
        $response = $this->req($this->api_url('v3/services/' . $this->_service_id . '/users/' . $user_keyname), self::MODE_PUT, $userdata, 'urn:notakey:user urn:notakey:usermanager');

        if ($response->code != 200) {
            throw new NotakeyApiException("User update request failure HTTP:$response->code");
        }

        return $response->body;
    }

    public function sync_user($username, $userdata)
    {
        $u = $this->get_user($username);
        if ($u) {
            return $this->update_user_by_keyname($u->keyname, $userdata);
        } else {
            return $this->create_user($userdata);
        }
    }

    public function delete_user_by_keyname($user_keyname)
    {
        $response = $this->req($this->api_url('v3/services/' . $this->_service_id . '/users/' . $user_keyname), self::MODE_DELETE, null, 'urn:notakey:user urn:notakey:usermanager');

        if ($response->code != 200) {
            throw new NotakeyApiException("User delete request failure HTTP:$response->code");
        }

        return $response->body;
    }

    public function reset_user_devices($username)
    {
        if (!($user = $this->get_user($username))) {
            return false;
        }

        $devices = $this->get_user_devices($user->keyname);

        foreach ($devices as $d) {
            $response = $this->req($this->api_url('v3/services/' . $this->_service_id . '/users/' . $user->keyname . '/devices/' . $d->keyname), self::MODE_DELETE, null, 'urn:notakey:user urn:notakey:devicemanager');

            if ($response->code != 204) {
                throw new NotakeyApiException("Device service delete request failure HTTP:$response->code");
            }
        }

        return true;
    }

    public function get_user_devices($user_keyname)
    {
        $response = $this->req($this->api_url('v3/services/' . $this->_service_id . '/users/' . $user_keyname . '/devices'), self::MODE_GET, null, 'urn:notakey:user');

        if ($response->code != 200) {
            throw new NotakeyApiException("Device service request failure HTTP:$response->code");
        }

        return $response->body;
    }

    public function can_be_onboarded($username)
    {
        if (!($user = $this->get_user($username))) {
            return false;
        }

        $devices = $this->get_user_devices($user->keyname);

        if ($user->max_user_device_count == 0 || $user->max_user_device_count > count($devices)) {
            return true;
        }

        return false;
    }

    public function status($uuid)
    {
        $response = $this->req($this->api_url('v3/services/' . $this->_service_id . '/auth/' . $uuid), self::MODE_GET);

        if ($response->code != 200) {
            throw new NotakeyApiException("Authentication status request failure HTTP:$response->code");
        }

        return $response->body;
    }
}
