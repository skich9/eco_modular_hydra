<?php
class Header {
    
    private $header = '';
    
    function __construct(){
        $this->header = [
            'Method: POST',
            'Connection: Keep-Alive',
            'User-Agent: PHP-SOAP-CURL',
            'apikey: TokenApi eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJjZXRhMzgiLCJjb2RpZ29TaXN0ZW1hIjoiNzI1QzQxMDNDMTlFNzE5MkEzRUIzNUUiLCJuaXQiOiJINHNJQUFBQUFBQUFBRE8yc0RDMk1ETXdzZ1FBbEZQd2JBa0FBQUE9IiwiaWQiOjExMDA0NzIsImV4cCI6MTczNTQzMDQwMCwiaWF0IjoxNzA0MTY0NDcyLCJuaXREZWxlZ2FkbyI6Mzg4Mzg2MDI5LCJzdWJzaXN0ZW1hIjoiU0ZFIn0.HtlG31tLuqiz9yww5sX3dHajqTdKjQljgNKpJOgjS2FgLO4HA2lyiu1bXe2jYxEbOZNEvpJInMoQiJNDOaGDXg',
            'Content-Type: text/xml;charset=UTF-8'
        ];
    }

    public function getHeader(){
        return $this->header;
    }
}
?>