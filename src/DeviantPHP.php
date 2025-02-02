<?php

namespace DeviantPHP;

use Illuminate\Support\Facades\Log;

class DeviantPHP {

    var $client_id, $client_secret, $redirect_uri, $scope;

    private $da_oauth_url = "https://www.deviantart.com/oauth2";
    private $da_oauth_resource = "https://www.deviantart.com/api/v1/oauth2/";
    private $da_oauth_placebo = "https://www.deviantart.com/api/v1/oauth2/placebo";


    private $access_token, $refresh_token = null;

    function __construct($params=array()) {
        $vars = array("client_id", "client_secret", "redirect_uri", "scope");

        foreach ($vars as $var) {
            if (!empty($params[$var]))
                $this->$var = $params[$var];
        }

        $this->checkFunctions();
    }

    public function authenticate() {
        if (!empty($_GET['code'])) {
            $code = $_GET['code'];
            $access_token = $this->getAccessToken($code);
        } else {
            $url = $this->createAuthUrl();
            $this->redirect($url);
        }
    }

    public function createAuthUrl() {
        try {
            if (empty($this->client_id)) throw new \Exception("The client_id must not be empty.");
            if (empty($this->client_secret)) throw new \Exception("The client_secret must not be empty.");
            if (empty($this->redirect_uri)) throw new \Exception("The redirection_uri must not be empty.");
            if (empty($this->scope)) throw new \Exception("The scope must not be empty.");

            $url = $this->da_oauth_url . "/authorize?response_type=code&client_id={$this->client_id}&redirect_uri={$this->redirect_uri}&scope={$this->scope}";
            return $url;
        } catch(Exception $e) {
            echo $e->getMessage();
        }
    }

    public function getAccessToken($code) {
        $data = array();
        $data["grant_type"] = "authorization_code";
        $data["client_id"] = $this->client_id;
        $data["client_secret"] = $this->client_secret;
        $data["redirect_uri"] = $this->redirect_uri;
        $data["code"] = $code;

        try {
            $url = $this->da_oauth_url . "/token";
            $result = json_decode($this->doCurl($url, $data), true);
            if (!empty($result["error"])) throw new \Exception($result["error_description"]);
            $this->setToken($result["access_token"], $result["refresh_token"]);
        } catch(Exception $e) {
            echo $e->getMessage();
        }
    }

    public function refreshToken() {
        try {
            if (empty($this->refresh_token)) throw new \Exception("The refresh_token is empty.");
            $data = array();
            $data["grant_type"] = "refresh_token";
            $data["client_id"] = $this->client_id;
            $data["client_secret"] = $this->client_secret;
            $data["refresh_token"] = $this->refresh_token;

            $url = $this->da_oauth_url . "/token";
            $result = json_decode($this->doCurl($url, $data), true);
            if (!empty($result["error"])) throw new \Exception($result["error_description"]);
            $this->setToken($result["access_token"], $result["refresh_token"]);
        } catch(Exception $e) {
            echo $e->getMessage();
        }
    }

    public function uploadFile($filename) {
        try {
            if (!$this->isAuthenticated()) $this->refreshToken();

            $data = array();
            $data["title"] = basename($filename);
            $data["access_token"] = $this->access_token;
            $data["file"] = curl_file_create($filename);

            $result = json_decode($this->doCurl($this->da_oauth_resource . "stash/submit", $data), true);
            if (!empty($result["error"])) throw new \Exception($result["error_description"]);
            return $result;
        } catch(Exception $e) {
            echo $e->getMessage();
        }
    }

    // Get authorized user (whoami)
    public function getUser() {
        try {
            if (!$this->isAuthenticated()) $this->refreshToken();

            $data = array();
            $data["access_token"] = $this->access_token;

            $result = json_decode($this->doCurl($this->da_oauth_resource . "user/whoami", $data), true);
            if (!empty($result["error"])) throw new \Exception($result["error_description"]);
            return $result;
        } catch(Exception $e) {
            echo $e->getMessage();
        }
    }

    // Get profile info
    public function getCollections($username, $session = false, $mature = true, $limit = 24, $offset = 0) {
        try {
            if (!$this->isAuthenticated()) $this->refreshToken();

            $data = array();
            $data["access_token"] = $this->access_token;
            $data["with_session"] = $session;
            $data["mature_content"] = $mature;
            $data["limit"] = $limit;
            $data["offset"] = $offset;

            $result = json_decode($this->doCurl($this->da_oauth_resource . "collections/all", $data));
            if (isset($result->error)) throw new \Exception($result->error_description);
            return $result;
        } catch(Exception $e) {
            echo $e->getMessage();
        }
    }

    public function isAuthenticated() {
        try {
            if (empty($this->access_token)) throw new \Exception("The access_token is empty.");
            if (empty($this->refresh_token)) throw new \Exception("The refresh_token is empty.");
            $data = array();
            $data["access_token"] = $this->access_token;

            $result = json_decode($this->doCurl($this->da_oauth_placebo, $data), true);
            return ($result["status"] == "success")?true:false;
        } catch(Exception $e) {
            echo $e->getMessage();
        }
    }

    // getter/setter functions
    public function setRedirect($url=null) {
        $this->redirect_uri = $url;
    }

    public function getRedirect() {
        return $this->redirect_uri;
    }

    public function setToken($access_token=null, $refresh_token=null) {
        $this->access_token = $access_token;
        $this->refresh_token = $refresh_token;
    }

    public function getToken() {
        return array("access_token" => $this->access_token, "refresh_token" => $this->refresh_token);
    }

    public function setCredentials($credentials = array()) {
        $this->client_id = $credentials["client_id"];
        $this->client_secret = $credentials["client_secret"];
    }

    public function getCredentials() {
        return array("client_id" => $this->client_id, "client_secret" => $this->client_secret);
    }

    private function checkFunctions() {
        if (!function_exists('curl_file_create')) {
            function curl_file_create($filename, $mimetype = '', $postname = '') {
                return "@$filename;filename="
                    . ($postname ?: basename($filename))
                    . ($mimetype ? ";type=$mimetype" : '');
            }
        }
    }

    // private functions
    private function redirect($url) {
        try {
            if (empty($url)) throw new \Exception("Cannot redirect.");
            if (headers_sent()) throw new \Exception("Headers already sent.");
            header("Location: $url");
            exit;
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    // curl call
    private function doCurl($url, $data = array()) {
        $ch = curl_init();
        $url = $url . '?' . http_build_query($data);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, count($data));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        //execute post
        $result = curl_exec($ch);

        //close connection
        curl_close($ch);

        return $result;
    }
}

?>
