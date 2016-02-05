<?php
require_once('lib/sag/Sag.php');	// you may adjust this path

/**
 * ConvenientCouch singleton instance.
 * @var ConvenientCouch
 */
$dbInstance = null;

/**
 * ConvenientCouch singleton creation.
 * @param string $dbName create ConvenientCouch singleton instance with database name $dbName
 * @param string $defaultDesignDoc default CouchDB design document that contains the views
 * @param string $host CouchDB host
 * @param string $port CouchDB port
 * @param string $pathPrefix optional CouchDB URL path prefix
 * @return ConvenientCouch|null
 */
function ConvenientCouchCreateInstance($dbName, $defaultDesignDoc, $host='localhost', $port=5984, $pathPrefix=null) {
    global $dbInstance;

    $dbInstance = new ConvenientCouch($dbName, $defaultDesignDoc, $host, $port, $pathPrefix);

    return $dbInstance;
}

/**
 * Return ConvenientCouch singleton instance.
 * @return ConvenientCouch singleton instance.
 */
function ConvenientCouchGetInstance() {
    global $dbInstance;

    return $dbInstance;
}

/**
 * Class ConvenientCouch. Database API for CouchDB access.
 */
class ConvenientCouch {
    public static $KEY_LOOKUP_SINGLE = 1;   /** for single key lookup "&key=<value>" */
    public static $KEY_LOOKUP_MULTI = 2;    /** for multi key lookup "&keys=<value1>,<value2>,..." */
    public static $KEY_LOOKUP_RANGE = 3;    /** for key range lookup "&startkey=<value1>&endkey=<value2>" */

    private static $DEFAULT_MAX_KEY_COUNT = 200;    /** default maximum number of keys to use for multi key lookup */

    /**
     * Sag CouchDB API object.
     * @var Sag
     */
    private $sag;

    /**
     * Database name
     * @var string
     */
    private $dbName;

    /**
     * Default design document for views.
     * @var string
     */
    private $designDoc;


    /**
     * Constructor
     * @param string $dbName CouchDB database name
     * @param string $defaultDesignDoc default CouchDB design document that contains the views
     * @param string $host CouchDB host
     * @param string $port CouchDB port
     * @param string $pathPrefix optional CouchDB URL path prefix
     * @throws Exception
     * @throws SagCouchException
     * @throws SagException
     */
    public function __construct($dbName, $defaultDesignDoc, $host='localhost', $port=5984, $pathPrefix=null) {
        $this->sag = new Sag($host, $port);
        if (!is_null($pathPrefix)) {
	        $this->sag->setPathPrefix($pathPrefix);
	    }
	    
        $this->sag->setDatabase($dbName);
        $this->dbName = $dbName;
        
        $this->designDoc = $defaultDesignDoc;
    }
    
    /**
     * Set default design document for views to $doc
     * @param string $doc default CouchDB design document that contains the views
     */
    public function setDesignDoc($doc) {
    	$this->designDoc = $doc;
    }
    
    /**
     * Get default design document for views
     * @return string default design document for views
     */
    public function getDesignDoc() {
		return $this->designDoc;    
    }

    /**
     * Fetch a document by CouchDB document ID.
     * @param string $id CouchDB document ID
     * @return mixed document
     * @throws SagException
     */
    public function fetchDocById($id) {
        return $this->sag->get($id);
    }

    /**
     * Fetch results of a view by matching keys.
     * @param string $view view name
     * @param mixed $key key to match
     * @param bool $includeDocs use include_docs parameter in query
     * @return mixed document
     * @throws Exception
     */
    public function fetchViewWithKeyMatch($view, $key, $includeDocs=false) {
        return $this->fetchView($view, $key, self::$KEY_LOOKUP_SINGLE, null, $includeDocs);
    }

    /**
     * Fetch results of a view by matching multiple keys.
     * @param string $view view name
     * @param array $keys keys array to match
     * @param bool $includeDocs use include_docs parameter in query
     * @param int $maxKeyCount maximum number of keys to use in a single request
     * @param bool $mergeResult merge result into one object with body->rows
     * @return mixed document
     * @throws Exception
     */
    public function fetchViewWithKeysMatch($view, $keys, $includeDocs=false, $maxKeyCount=null, $mergeResult=true) {
        $maxKeyCount = $maxKeyCount === null ? self::$DEFAULT_MAX_KEY_COUNT : $maxKeyCount;

        // do not exceed $maxKeyCount (can lead to HTTP 414 error - maximum request URI exceeded)
        $lenKeys = count($keys);
        $slices = max($lenKeys / $maxKeyCount, 1);
        $fullRes = array();
        for ($slice = 0; $slice < $slices; $slice++) {
            $keysSlice = array_slice($keys, $slice * $maxKeyCount, $maxKeyCount);
            $resSlice = $this->fetchView($view, $keysSlice, self::$KEY_LOOKUP_MULTI, null, $includeDocs);
            array_push($fullRes, $resSlice);
        }

        if ($mergeResult) {
            $mergedRes = new stdClass();
            $mergedRes->body = new stdClass();
            $mergedRes->body->rows = array();
            foreach ($fullRes as $resSlice) {
                $mergedRes->body->rows = array_merge($mergedRes->body->rows, $resSlice->body->rows);
            }

            return $mergedRes;
        } else {
            return $fullRes;
        }
    }

    /**
     * Fetch results of a view by matching key range.
     * @param string $view view name
     * @param array $keyRange key range [array(start, end)]
     * @param bool $includeDocs use include_docs parameter in query
     * @return mixed document
     * @throws Exception
     */
    public function fetchViewWithKeyRange($view, array $keyRange, $includeDocs=false) {
        return $this->fetchView($view, $keyRange, self::$KEY_LOOKUP_RANGE, null, $includeDocs);
    }

    /**
     * Fetch results of a view with grouping.
     * @param string $view view name
     * @param mixed $grouping grouping level: null for no grouping, true for exact grouping, integer 0-9 for grouping level
     * @return mixed document
     * @throws Exception
     */
    public function fetchViewWithGrouping($view, $grouping) {
        return $this->fetchView($view, null, self::$KEY_LOOKUP_SINGLE, $grouping);
    }

    /**
     * Fetch results of a view.
     * @param string $view view name
     * @param mixed $key single key or key range array or mulitple keys array
     * @param int $keyLookupType lookup type according to self::$KEY_LOOKUP_*
     * @param mixed $grouping grouping level: null for no grouping, true for exact grouping, integer 0-9 for grouping level
     * @param bool $includeDocs use include_docs parameter in query
     * @return mixed document
     * @throws Exception
     * @throws SagException
     */
    public function fetchView($view, $key=null, $keyLookupType=1, $grouping=null, $includeDocs=false) {
        $url = '/_design/' . $this->designDoc . '/_view/' . $view;

        $glue = '?';

        if ($key) {
            if ($keyLookupType == self::$KEY_LOOKUP_RANGE) {   // is startkey, endkey range
                assert(is_array($key));
                if (is_array($key[0])) {
                    $url .= $glue . 'startkey=' . $this->implodeArrayKey($key[0]);
                } else {
                    $url .= $glue . 'startkey=' . $this->encodeURLParamValue($key[0]);
                }

                $glue = '&';

                if (is_array($key[1])) {
                    $url .= $glue . 'endkey=' . $this->implodeArrayKey($key[1]);
                } else {
                    $url .= $glue . 'endkey=' . $this->encodeURLParamValue($key[1]);
                }
            } elseif ($keyLookupType == self::$KEY_LOOKUP_SINGLE) {    // is exact match
                if (is_array($key)) {
                    $url .= $glue . 'key=' . $this->implodeArrayKey($key);
                } else {
                    $url .= $glue . 'key=' . $this->encodeURLParamValue($key);
                }
                $glue = '&';
            } elseif ($keyLookupType == self::$KEY_LOOKUP_MULTI) {
                assert(is_array($key));

                if (is_array($key[0])) {
                    $keysQueryArr = array();
                    foreach ($key as $k) {
                        array_push($keysQueryArr, $this->implodeArrayKey($k));
                    }
                } else {
                    $keysQueryArr = $key;
                }

                $url .= $glue . 'keys=[' . implode(',', $keysQueryArr) . ']';
            } else {
                throw  new Exception('invalid key lookup type:' . $keyLookupType);
            }
        }

        if ($grouping !== null) {
            if (is_bool($grouping)) {
                $url .= $glue . 'group=' . (string)$grouping;
            } else {
                $url .= $glue . 'group_level=' . (int)$grouping;
            }
            $glue = '&';
        }

        if ($includeDocs) {
            $url .= $glue . 'include_docs=true';
            //$glue = '&';
        }

//        var_dump($url);

        return $this->sag->get($url);
    }

    /**
     * Fetch all documents of type $type
     * @param mixed $type document type
     * @return mixed document
     */
    public function fetchDocByType($type) {
        return $this->fetchViewWithKeyMatch('docs_by_type', $type);
    }

    /**
     * Return CouchDB result body in a view $view, whose key matches $lookupVal.
     * @param string $view view name
     * @param mixed $lookupVal key lookup value
     * @param bool $exactKeyMatch true: use exact key matching (single dim. keys), false: inexact matching (multidim. keys)
     * @param bool $includeDocs use include_docs parameter in query
     * @return mixed objects array
     */
    public function fetchResultBodyFromViewByKey($view, $lookupVal, $exactKeyMatch=true, $includeDocs=false) {
        if ($exactKeyMatch) {
            $viewRes = $this->fetchViewWithKeyMatch($view, $lookupVal, $includeDocs)->body;
        } else {
            // crazy couch db
            if (is_numeric($lookupVal)) {
                $lookupLimit = $lookupVal + 1;
            } else if (is_string($lookupVal)) {
                $lookupLimit = $lookupVal . '1';
            } else {
                throw new Exception('lookup value must be either numeric or a string');
            }

            $viewRes = $this->fetchViewWithKeyRange($view, array(array($lookupVal), array($lookupLimit)),
                $includeDocs)->body;
        }

        return $viewRes->rows;
    }

    /**
     * Return first result document from view $view whose key matches exactly $lookupVal.
     * @param string $view view name
     * @param mixed $lookupVal key lookup value
     * @param bool $exactKeyMatch true: use exact key matching (single dim. keys), false: inexact matching (multidim. keys)
     * @param bool $includeDocs use include_docs parameter in query
     * @return mixed single object
     */
    public function fetchFirstResultDocFromViewByKey($view, $lookupVal, $exactKeyMatch=true, $includeDocs=false) {
        $viewRes = $this->fetchResultBodyFromViewByKey($view, $lookupVal, $exactKeyMatch, $includeDocs);

        return $viewRes[0];
    }

    /**
     * Compose a single, combined document from an array of linked documents. The linked documents come from a CouchDB
     * join query with 'include_docs=true' parameter (see http://docs.couchdb.org/en/latest/couchapp/views/joins.html)
     * @param array $linkedDocs linked documents. one element must be the base document. it has a "value" property of NULL
     * @return stdClass single, combined document object
     * @throws Exception when no base document was found
     */
    public function composeSingleDocFromLinkedDocs(array $linkedDocs) {
        // 1. find the base document: this is the one with a value of NULL
        $baseDoc = null;
        foreach ($linkedDocs as $doc) {
            if (is_null($doc->value)) {
                assert(isset($doc->doc) && is_a($doc->doc, 'stdClass'));
                $baseDoc = $doc->doc;
            }
        }

        if (is_null($baseDoc)) {
            throw new Exception('no base document was fetched from the DB');
        }

        // 2. merge the linked documents with the base document
        $unwantedDocObjAttr = array('_id', '_rev', 'type');   // we do not add these attributes to the base doc
        foreach ($linkedDocs as $lDoc) {
            if (!is_null($lDoc->value)) {
                if (!isset($lDoc->doc) && !is_a($lDoc->doc, 'stdClass')) {
                    continue;
                }
                $lDocObj = $lDoc->doc;
                assert(isset($lDocObj->type));
                $lDocType = $lDocObj->type;
                if ($lDocType == 'type') {
                    $lDocType = 'type_obj';
                }

                // find out if we have a relation to multiple objects: this is the case when the related id property
                // in the base document ends with "_ids" and not with "_id"
                $idsKey = $lDocType . '_ids';
                $isRelationToList = isset($baseDoc->$idsKey);

                // create the related object if necessary
                if (!isset($baseDoc->$lDocType)) {
                    if ($isRelationToList) {
                        $baseDoc->$lDocType = array(new stdClass());    // create an array because we await a list
                    } else {
                        $baseDoc->$lDocType = new stdClass();   // create a normal object
                    }
                } else {
                    if ($isRelationToList) {    // add another empty object to the list
                        array_push($baseDoc->$lDocType, new stdClass());
                    }
                }

                // get pointer to the object that is joined with the base document
                if ($isRelationToList) {
                    $joinedAttrObj = end($baseDoc->$lDocType);
                } else {
                    $joinedAttrObj = $baseDoc->$lDocType;
                }

                foreach ($lDocObj as $k => $v) {
                    if (in_array($k, $unwantedDocObjAttr)) continue;    // filter out unwanted attributes

                    // join this attribute with the base document
                    $joinedAttrObj->$k = $v;
                }
            }
        }

        return $baseDoc;
    }

    /**
     * Helper function to join keys that are arrays in the form of ["value1", "value2"]
     */
    private function implodeArrayKey($k) {
        $s = '[';
        foreach ($k as $p) {
            $s .= $this->encodeURLParamValue($p) . ',';
        }
        $s[strlen($s)-1] = ']';

        return $s;
    }
	
	/**
	 * Encode an URL parameter value (used for CouchDB queries).
	 * If $p is not numeric, wrap quotes around the parameter
	 * @param mixed $p parameter value
	 * @return string URL encoded parameter value
	 * @throws Exception
	 */
	private function encodeURLParamValue($p) {
		if (is_numeric($p)) {
			$q = urlencode($p);
		} else if (is_string($p)) {
			$q = urlencode('"' . $p . '"');
		} else if (is_object($p) && !(array)$p) {
			$q = "{}";
		} else {
			throw new Exception('URL parameter must be either numeric or a string or an empty object');
		}

		return $q;
	}
}