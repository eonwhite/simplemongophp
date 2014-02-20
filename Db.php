<?php

/**
 * Class Db
 *
 * @package     SimpleMongoPhp
 * @author      Ian White (ibwhite@gmail.com)
 * @version     2.0
 * @contributor Ahmed Mohamed (ahmedamhmd@gmail.com)
 * This library aimed to be simple and I'm literally keeping it that way
 *              Adding support to \MongoClient instead of deprecated \Mongo
 *              Better object oriented design
 **/
class Db
{
    /** @var MongoClient */
    private $mongo;
    /** @var string */
    private $currentDbName;
    /** @var [] */
    private $connections;
    /** @var bool */
    private $read_slave = false;
    /** @var bool */
    private $readOnly = false;

    /**
     * @param null $mongo
     * @param      $dbName
     * @param null $collections
     * @param bool $slave
     */
    public function __construct($mongo = null, $dbName, $collections = null, $slave = false)
    {
        if (!$mongo instanceof \Mongo && is_string($mongo)) {
            $this->mongo         = $mongo;
            $this->currentDbName = $dbName;
            $this->initializeConnection();
        } else {
            $this->addConnection($mongo, $dbName, $collections, $slave);
        }
    }

    /**
     * @return bool
     */
    private function initializeConnection()
    {
        $this->mongo = new MongoClient($this->mongo, ['db' => $this->currentDbName]);

        return $this->mongo->connect();
    }

    /**
     * @return bool
     */
    private function isConnected()
    {
        return $this->mongo !== null && $this->mongo instanceof \MongoClient;
    }

    /**
     * @param boolean $readOnly
     */
    public function setReadOnly($readOnly)
    {
        $this->readOnly = $readOnly;
    }

    /**
     * @return boolean
     */
    public function getReadOnly()
    {
        return $this->readOnly;
    }

    /**
     * @param null $collection
     *
     * @throws Exception
     */
    private function getConnectionInfo($collection = null)
    {
        $info = null;

        // in read-only mode, check for a slave config if set to read slave
        if ($this->readOnly && $this->read_slave) {
            if (isset($this->connections["$collection/slave"])) {
                $info = $this->connections["$collection/slave"];
            } else {
                if (isset($this->connections['/slave'])) {
                    $info = $this->connections['/slave'];
                }
            }
        }

        if (!$info) {
            if (isset($this->connections[$collection])) {
                $info = $this->connections[$collection];
            } else {
                if (isset($this->connections[''])) {
                    $info = $this->connections[''];
                } else {
                    throw new Exception('No connection configuration, call addConnection()');
                }
            }
        }

        if ($info[0] instanceof \MongoClient) {
            $this->mongo = $info[0];
        } else {
            throw new Exception('No connection configuration, call addConnection()');
        }
        $this->currentDbName = $info[1];
        if (!$this->isConnected()) {
            $this->mongo->connect();
        }
    }

    /**
     * @param $collection
     *
     * @return mixed
     */
    private function getDb($collection)
    {
        $this->getConnectionInfo($collection);

        return $this->mongo->selectDB($this->currentDbName);
    }

    /**
     * @param $collection
     *
     * @return \MongoCollection
     */
    private function getCollection($collection)
    {
        $this->getConnectionInfo($collection);

        return $this->mongo->selectCollection($this->currentDbName, $collection);
    }

    /**
     * @param      $mongo
     * @param      $db_name
     * @param null $collections
     * @param bool $slave
     */
    public function addConnection($mongo, $db_name, $collections = null, $slave = false)
    {
        $append = $slave ? '/slave' : '';
        if (!$collections) {
            $this->connections[$append] = array($mongo, $db_name);
        } else {
            foreach ($collections as $c) {
                $this->connections["$c$append"] = array($mongo, $db_name);
            }
        }
    }

    /**
     * @param      $mongo
     * @param      $db_name
     * @param null $collections
     */
    public function addSlaveConnection($mongo, $db_name, $collections = null)
    {
        $this->addConnection($mongo, $db_name, $collections, true);
    }

    /**
     * @param bool $setting
     */
    public function readSlave($setting = true)
    {
        $this->read_slave = $setting;
    }

    /**
     * Returns a MongoId from a string, integer, MongoId, array, or Dbo object
     *
     * @param $obj
     *
     * @return MongoId
     */
    public function id($obj)
    {
        if ($obj instanceof MongoId) {
            return $obj;
        }

        if (is_int($obj)) {
            return $obj;
        }

        if (is_string($obj)) {
            return new MongoId($obj);
        }
        if (is_array($obj)) {
            return $obj['_id'];
        }

        return new MongoId($obj->_id);
    }

    /**
     * Returns true if the value passed appears to be a Mongo database reference
     *
     * @param mixed $obj
     *
     * @return boolean
     **/
    public function isRef($value)
    {
        if (!is_array($value)) {
            return false;
        }

        return MongoDBRef::isRef($value);
    }

    /**
     * Returns a Mongo database reference created from a collection and an id
     *
     * @param string $collection
     * @param mixed  $id
     *
     * @return array
     **/
    public function createRef($collection, $id)
    {
        return array('$ref' => $collection, '$id' => $this->id($id));
    }

    /**
     * Returns the Mongo object array that a database reference points to
     *
     * @param array $dbref
     *
     * @return array
     **/
    public function getRef($dbref)
    {
        $db = $this->getDb($dbref['$ref'], true);

        return $db->getDBRef($dbref);
    }

    /**
     * Recursively expands any database references found in an array of references,
     * and returns the expanded object.
     *
     * @param mixed $value
     *
     * @return mixed
     **/
    public function expandRefs($value)
    {
        if (is_array($value)) {
            if ($this->isRef($value)) {
                return $this->getRef($value);
            } else {
                foreach ($value as $k => $v) {
                    $value[$k] = $this->expandRefs($v);
                }
            }
        }

        return $value;
    }

    /**
     * Returns a database cursor for a Mongo find() query.
     *
     * Pass the query and options as array objects (this is more convenient than the standard
     * Mongo API especially when caching)
     *
     * $options may contain:
     *   fields - the fields to retrieve
     *   sort - the criteria to sort by
     *   limit - the number of objects to return
     *   skip - the number of objects to skip
     *
     * @param string $collection
     * @param array  $query
     * @param array  $options
     *
     * @return MongoCursor
     **/
    public function find($collection, $query = array(), $options = array())
    {
        $this->readOnly = true;
        $col            = $this->getCollection($collection);
        $fields         = isset($options['fields']) ? $options['fields'] : array();
        if(!is_array($fields)){
            throw new \InvalidArgumentException('Option `fields` must be an array e.g. `["id"=>true,"title"=>false]`');
        }
        $result         = $col->find($query, $fields);
        if (isset($options['sort']) && $options['sort'] !== null) {
            $result->sort($options['sort']);
        }
        if (isset($options['limit']) && $options['limit'] !== null) {
            $result->limit($options['limit']);
        }
        if (isset($options['skip']) && $options['skip'] !== null) {
            $result->skip($options['skip']);
        }

        return $result;
    }

    /**
     * Just like find, but return the results as an array (of arrays)
     *
     * @param string $collection
     * @param array  $query
     * @param array  $options
     *
     * @return array
     **/
    public function finda($collection, $query = array(), $options = array())
    {
        $result = $this->find($collection, $query, $options);
        $array  = array();
        foreach ($result as $val) {
            $array[] = $val;
        }

        return $array;
    }

    /**
     * Do a find() but return an array populated with one field value only
     *
     * @param string $collection
     * @param string $field
     * @param array  $query
     * @param array  $options
     *
     * @return array
     **/
    public function findField($collection, $field, $query = array(), $options = array())
    {
        $options['fields'] = array($field => 1);
        $result            = $this->find($collection, $query, $options);
        $array             = array();
        foreach ($result as $val) {
            $array[] = $val[$field];
        }

        return $array;
    }

    /**
     * Do a find() returned as an associative array mapping one field to another
     *
     * @param string $collection
     * @param string $key_field
     * @param string $value_field
     * @param array  $query
     * @param array  $options
     *
     * @return array
     **/
    public function findAssoc($collection, $key_field, $value_field, $query = array(), $options = array())
    {
        $options['fields'] = array($key_field => 1, $value_field => 1);
        $result            = $this->find($collection, $query, $options);
        $array             = array();
        foreach ($result as $val) {
            $array[$val[$key_field]] = $val[$value_field];
        }

        return $array;
    }

    /**
     * Find a single object -- like Mongo's findOne() but you can pass an id as a shortcut
     *
     * @param string $collection
     * @param mixed  $id
     *
     * @return array
     **/
    public function findOne($collection, $id = 0)
    {
        $this->readOnly = true;
        $col            = $this->getCollection($collection);
        if ($id === 0) {
            return $col->findOne();
        }
        if (!is_array($id)) {
            $id = array('_id' => $this->id($id));
        }

        return $col->findOne($id);
    }

    /**
     * Count the number of objects matching a query in a collection (or all objects)
     *
     * @param string $collection
     * @param array  $query
     *
     * @return integer
     **/
    public function count($collection, $query = array())
    {
        $this->readOnly = true;
        $col            = $this->getCollection($collection);
        if ($query) {
            $res = $col->find($query);

            return $res->count();
        } else {
            return $col->count();
        }
    }

    /**
     * Save a Mongo object -- just a simple shortcut for MongoCollection's save()
     *
     * @param string $collection
     * @param array  $data
     *
     * @return boolean
     **/
    public function save($collection, $data)
    {
        $col = $this->getCollection($collection);

        return $col->save($data);
    }

    public function insert($collection, $data)
    {
        $col = $this->getCollection($collection);

        return $col->insert($data);
    }

    public function lastError($collection = null)
    {
        $db = $this->getDb($collection);

        return $db->lastError();
    }

    /**
     * Shortcut for MongoCollection's update() method
     *
     * @param string  $collection
     * @param array   $criteria
     * @param array   $newobj
     * @param boolean $upsert
     *
     * @return boolean
     **/
    public function update($collection, $criteria, $newobj, $options = array())
    {
        $col = $this->getCollection($collection);
        if ($options === true) {
            $options = array('upsert' => true);
        }
        if (!isset($options['multiple'])) {
            $options['multiple'] = false;
        }

        return $col->update($criteria, $newobj, $options);
    }

    public function updateConcurrent($collection, $criteria, $newobj, $options = array())
    {
        $col = $this->getCollection($collection);
        if (!isset($options['multiple'])) {
            $options['multiple'] = false;
        }
        $i = 0;
        foreach ($col->find($criteria, array('fields' => array('_id' => 1))) as $obj) {
            $col->update(array('_id' => $obj['_id']), $newobj);
            if (empty($options['multiple'])) {
                return;
            }
            if (!empty($options['count_mod']) && $i % $options['count_mod'] == 0) {
                if (!empty($options['count_callback'])) {
                    call_user_func($options['count_callback'], $i);
                } else {
                    echo '.';
                }
            }
            $i++;
        }
    }

    /**
     * Shortcut for MongoCollection's update() method, performing an upsert
     *
     * @param string $collection
     * @param array  $criteria
     * @param array  $newobj
     *
     * @return boolean
     **/
    public function upsert($collection, $criteria, $newobj)
    {
        return $this->update($collection, $criteria, $newobj, true);
    }

    /**
     * Shortcut for MongoCollection's remove() method, with the option of passing an id string
     *
     * @param string  $collection
     * @param array   $criteria
     * @param boolean $just_one
     *
     * @return boolean
     **/
    public function remove($collection, $criteria, $just_one = false)
    {
        $col = $this->getCollection($collection);
        if (!is_array($criteria)) {
            $criteria = array('_id' => $this->id($criteria));
        }

        return $col->remove($criteria, $just_one);
    }

    /**
     * Shortcut for MongoCollection's drop() method
     *
     * @param string $collection
     *
     * @return boolean
     **/
    public function drop($collection)
    {
        $col = $this->getCollection($collection);

        return $col->drop();
    }

    /**
     * Shortcut for MongoCollection's batchInsert() method
     *
     * @param string $collection
     * @param array  $array
     *
     * @return boolean
     **/
    public function batchInsert($collection, $array)
    {
        $col = $this->getCollection($collection);

        return $col->batchInsert($array);
    }

    public function group($collection, array $keys, array $initial, $reduce, array $condition = array())
    {
        $this->readOnly = true;
        $col            = $this->getCollection($collection);

        return $col->group($keys, $initial, $reduce, $condition);
    }

    /**
     * Shortcut for MongoCollection's ensureIndex() method
     *
     * @param string $collection
     * @param array  $keys
     *
     * @return boolean
     **/
    public function ensureIndex($collection, $keys, $options = array())
    {
        $col = $this->getCollection($collection);

        return $col->ensureIndex($keys, $options);
    }

    /**
     * Ensure a unique index
     *
     * @param string $collection
     * @param array  $keys
     *
     * @return boolean
     **/
    public function ensureUniqueIndex($collection, $keys, $options = array())
    {
        $options['unique'] = true;

        return $this->ensureIndex($collection, $keys, $options);
    }

    /**
     * Shortcut for MongoCollection's getIndexInfo() method
     *
     * @param string $collection
     *
     * @return array
     **/
    public function getIndexInfo($collection)
    {
        $this->readOnly = true;
        $col            = $this->getCollection($collection);

        return $col->getIndexInfo();
    }

    /**
     * Shortcut for MongoCollection's deleteIndexes() method
     *
     * @param string $collection
     *
     * @return boolean
     **/
    public function deleteIndexes($collection)
    {
        $col = $this->getCollection($collection);

        return $col->deleteIndexes();
    }
}