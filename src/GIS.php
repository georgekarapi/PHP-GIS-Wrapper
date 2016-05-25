<?php


namespace GISwrapper;

class GIS
{
    private $_auth;
    private $_subs;
    private $_cache;

    function __construct($auth, $apidoc = "https://gis-api.aiesec.org/v2/docs.json")
    {
        // check that $auth implements the AuthProvider interface
        if ($auth instanceof AuthProvider) {
            $this->_auth = $auth;
        } else {
            throw new InvalidAuthProviderException("The given object does not implement the AuthProvider interface.");
        }

        if(is_array($apidoc)) {
            $this->_cache = $apidoc;
        } else {
            $this->_cache = $this->generateSimpleCache($apidoc);
        }

        // initialize array with sub endpoints and apis
        $this->_subs = array();
    }

    public function __get($name)
    {
        if(array_key_exists($name, $this->_subs)) {
            return $this->_subs[$name];
        } elseif(array_key_exists($name, $this->_cache)) {
            if(!is_array($this->_cache[$name])) {
                $this->_cache[$name] = $this->proceedSubCache($this->_cache[$name], $name);
            }
            $this->_subs[$name] = APISubFactory::factory($this->_cache[$name], $this->_auth);
            return $this->_subs[$name];
        }
    }

    public function __set($name, $value) {
        if(array_key_exists($name, $this->_cache)) {
            if($value instanceof API || is_subclass_of($value, API::class)) {
                $this->_subs[$name] = $value;
            } elseif(is_array($value)) {
                if(!isset($this->_subs[$name])) {
                    $this->_subs[$name] = APISubFactory::factory($this->_cache[$name], $this->_auth);
                }
                foreach($value as $key => $v) {
                    $this->_subs[$name]->$key = $v;
                }
            }
        } else {
            trigger_error("Property " . $name . " does not exist", E_USER_ERROR);
        }
    }

    public function __isset($name)
    {
        return isset($this->_subs[$name]);
    }

    public function __unset($name)
    {
        if(isset($this->_subs[$name])) {
            unset($this->_subs[$name]);
        }
    }

    public function exists($name) {
        return array_key_exists($name, $this->_cache);
    }

    public function getCache() {
        return $this->_cache;
    }

    public static function generateSimpleCache($apidoc) {
        $cache = array();
        $root = GIS::loadJSON($apidoc);

        if($root === false) {
            throw new NoResponseException("Could not load swagger root file");
        } elseif($root === null || !isset($root->apis) || !is_array($root->apis)) {
            throw new InvalidSwaggerFormatException("Invalid swagger file");
        } else {
            if(!in_array('application/json', $root->produces)) {
                throw new RequirementsException("API does not produce JSON");
            } else {
                foreach($root->apis as $api) {
                    $name = explode('.', basename($api->path))[0];
                    $cache[$name] = $root->basePath . str_replace("{format}", "json", $api->path);
                }
            }
        }
        return $cache;
    }

    public static function generateFullCache($apidoc) {
        $cache = GIS::generateSimpleCache($apidoc);
        foreach($cache as $name => $data) {
            if(!is_array($data)) $cache[$name] = GIS::proceedSubCache($data, $name);
        }
        return $cache;
    }

    private static function proceedSubCache($url, $baseName) {
        // prepare cache with API as root
        $cache = array('endpoint' => false, 'dynamicSub' => false);

        // load api manifest
        $manifest = GIS::loadJSON($url);
        if($manifest === false) {
            throw new NoResponseException("Could not load API swagger file");
        } elseif($manifest === null) {
            throw new InvalidSwaggerFormatException("Invalid API swagger file");
        } else {
            foreach($manifest->apis as $api) {
                // prepare endpoint
                $endpoint = array(
                    'summary' => $api->summary,
                    'path' => str_replace('.{format}', '.json', $manifest->basePath . $api->path),
                    'endpoint' => true,
                    'dynamic' => false,
                    'dynamicSub' => false,
                    'subs' => array(),
                    'paged' => false,
                    'operations' => array(),
                    'params' => array()
                );
                // when the filename part of the path starts with a {, the endpoint is dynamic
                if(substr(basename($api->path), 0, 1) == '{') $endpoint['dynamic'] = true;

                // add all the operations
                foreach($api->operations as $operation) {
                    // check for json support
                    if(!in_array('application/json', $operation->produces)) {
                        throw new RequirementsException("An Operation does not produce JSON");
                    }

                    // add operation
                    if(!in_array($operation->httpMethod, $endpoint['operations'])) {
                        $endpoint['operations'][] = $operation->httpMethod;
                    }

                    // add parameters
                    foreach($operation->parameters as $parameter) {
                        if($parameter->name == "page" || $parameter->name == "per_page") {
                            $endpoint['paged'] = true;
                        } elseif($parameter->name != "access_token" && $parameter->paramType != "path") {
                            $m = array(
                                'type' => $parameter->dataType,
                                'required' => $parameter->required
                            );
                            $names = explode('[', $parameter->name);
                            if(count($names) == 1) {    // level 1 parameter
                                if(!isset($endpoint['params'][$names[0]])) {
                                    $endpoint['params'][$names[0]] = array('subparams' => array(), 'operations' => array());
                                }

                                // place data about this method in the endpoint
                                $endpoint['params'][$names[0]]['operations'][$operation->httpMethod] = $m;
                            } else {    // level n parameter
                                $ref = &$endpoint['params'][$names[0]];
                                unset($names[0]);
                                foreach($names as $name) {
                                    // remove the closing bracket from the array notation
                                    $name = str_replace(']', '', $name);

                                    // if intermediate param does not exist yet, create it
                                    if(!isset($ref['subparams'][$name])) $ref['subparams'][$name] = array('subparams' => array(), 'operations' => array());

                                    // move reference to the next subparam
                                    $ref = &$ref['subparams'][$name];
                                }

                                // place data about this method in the endpoint
                                $ref['operations'][$operation->httpMethod] = $m;
                            }
                        }
                    }
                }

                // prepare path for endpoint placement
                $path = str_replace('.{format}', '', $api->path);
                $path = str_replace('/' . $manifest->apiVersion . '/' . $baseName . '/', '' , $path);
                $path = explode('/', $path);

                // place endpoint
                if(str_replace('.{format}', '', $api->path) == '/' . $manifest->apiVersion . '/' . $baseName) { // root endpoint
                    if(is_array($cache['subs'])) $endpoint['subs'] = $cache['subs'];
                    $cache = $endpoint;
                } elseif(count($path) == 1) {   // level 1 sub endpoint
                    // check for already added subs as well as the dynamicSub property and keep them
                    if(isset($cache['subs'][$path[0]]['subs'])) $endpoint['subs'] = $cache['subs'][$path[0]]['subs'];
                    if(isset($cache['subs'][$path[0]]['dynamicSub']) && $cache['subs'][$path[0]]['dynamicSub']) $endpoint['dynamicSub'] = true;

                    // place endpoint
                    $cache['subs'][$path[0]] = $endpoint;

                    // check if endpoint is dynamic and set dynamicSub of root endpoint/api accordingly
                    if($endpoint['dynamic']) $cache['dynamicSub'] = true;
                } else {    // level n sub endpoint
                    $oldref = null;
                    $ref = &$cache;
                    foreach($path as $p) {
                        // if intermediate endpoint or api does not exist yet, create it as API
                        if(!isset($ref['subs'][$p])) $ref['subs'][$p] = array('endpoint' => false, 'subs' => array());

                        // if endpoint is dynamic, also save oldref to set dynamicSub
                        if($endpoint['dynamic']) $oldref = $ref;

                        // move reference to next intermediate endpoint
                        $ref = &$ref['subs'][$p];
                    }
                    if($endpoint['dynamic'] && $oldref != null) $oldref['dynamicSub'] = true;

                    // check for already added subs as well as the dynamicSub property and keep them
                    if(isset($ref['subs'])) $endpoint['subs'] = $ref['subs'];
                    if(isset($ref['dynamicSub']) && $ref['dynamicSub']) $endpoint['dynamicSub'] = true;

                    // place endpoint
                    $ref = $endpoint;
                }
            }
        }
        return $cache;
    }

    public static function loadJSON($url) {
        $root = false;
        $attempts = 0;
        while(!$root && $root !== null && $attempts < 3) {
            $root = file_get_contents($url);
            if($root !== false) {
                $root = json_decode($root);
            }
            $attempts++;
        }
        return $root;
    }
}