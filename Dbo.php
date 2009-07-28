<?php

/**
 * Class Dbo
 *
 * @package SimpleMongoPhp
 * @author Ian White (ibwhite@gmail.com)
 * @version 1.0
 *
 * This is a simple library to allow you to work with simple data objects.
 *
 * To set up, all you need to do is:
 *    - include() or require() the Db.php file, and this file
 *    - define a global variable named $mongo that represents your Mongo connection object
 *    - define('MONGODB_NAME', <the name of your database>);
 *    - create any number of data object classes that extend Dbo
 *    - call Dbo::addClass(<class name>, <collection name>) for each class
 *
 * Example usage (this code does basically the same thing as the sample code in Db.php)
 *   $mongo = new Mongo();
 *   define('MONGODB_NAME', 'lost');
 *   class LostPerson extends Dbo {
 *     function rollCall() {
 *       echo "$this->name is a " . ($this->goodguy ? 'good' : 'bad') . " guy!\n";
 *     }
 *   }
 *   Dbo::addClass('LostPerson', 'people');
 *
 *   Db::drop('people');
 *   Db::batchInsert('people', array(
 *     array('name' => 'Jack', 'sex' => 'M', 'goodguy' => true),
 *     array('name' => 'Kate', 'sex' => 'F', 'goodguy' => true),
 *     array('name' => 'Locke', 'sex' => 'M', 'goodguy' => true),
 *     array('name' => 'Hurley', 'sex' => 'M', 'goodguy' => true),
 *     array('name' => 'Ben', 'sex' => 'M', 'goodguy' => false),
 *   ));
 *   foreach (Dbo::find('LostPerson', array('goodguy' => true), array('sort' => array('name' => 1))) as $p) {
 *     $p->rollCall();
 *   }
 *   $ben = Dbo::findOne('LostPerson', array('name' => 'Ben'));
 *   $locke = Dbo::findOne('LostPerson', array('name' => 'Locke'));
 *   $ben->enemy = Dbo::toRef($locke);
 *   $ben->goodguy = null;
 *   Dbo::save($ben);
 *
 * This library may be freely distributed and modified for any purpose.
 **/
class Dbo {
    public $_data = array();
    
    /**
     * Constructor optionally takes the field values to prepopulate the object with
     *
     * @param array $data
     **/
    function __construct($data = array()) {
        $this->_data = $data;
    }
    
    /**
     * Attribute accessor overload returns a field property, or null if it doesn't exist.
     *
     * The "id" field behaves specially and returns the string representation of the MongoId.
     * This is convenient in a number of situations, especially comparisons:
     *    ($obj1->_id == $obj2->_id) is not a valid test but ($obj1->id == $obj2->id) is
     *
     * @param string $field
     * @return mixed
     **/
    function __get($field) {
        if ($field == 'id') {
            return "$this->_id";
        }
        return isset($this->_data[$field]) ? $this->_data[$field] : null;
    }

    /**
     * Attribute setter overload, set a field property.
     *
     * @param string $field
     * @param mixed $value
     * @return mixed
     **/
    function __set($field, $value) {
        return $this->_data[$field] = $value;
    }
    
    /**
     * isset() overload, checks if a field value is set
     *
     * @param string $field
     * @return boolean
     **/
    function __isset($field) {
        return isset($this->_data[$field]);
    }
    
    /**
     * unset() overload, unsets a field vlaue
     *
     * @param string $field
     * @return boolean
     **/
    function __unset($field) {
        unset($this->_data[$field]);
    }

    /**
     * This function will be called immediately prior to saving an object.
     *
     * Override in subclasses to, for example, set a created_time timestamp, or update dependent
     * fields, or validate the data, or whatever you like.
     *
     **/
    function presave() {
    }

    /**
     * Register that a class belongs with a collection.
     *
     * If the first parameter is a associative array, you can register many classes at once.
     *
     * @param mixed $class
     * @param string $collection
     **/
    static function addClass($class, $collection = null) {
        if (is_array($class)) {
            foreach ($class as $k => $v) {
                self::addClass($k, $v);
            }
        } else {
            if (!isset($GLOBALS['MONGODB_CLASSES'])) {
                $GLOBALS['MONGODB_CLASSES'] = array();
            }
            $GLOBALS['MONGODB_CLASSES'][$collection] = $class;
            if (!isset($GLOBALS['MONGODB_COLLECTIONS'])) {
                $GLOBALS['MONGODB_COLLECTIONS'] = array();
            }
            $GLOBALS['MONGODB_COLLECTIONS'][$class] = $collection;
        }
    }

    /**
     * Returns the name of the class that is associated with a collection.
     *
     * @param string $collection
     * @return string
     **/
    static function getClass($collection) {
        if (!isset($GLOBALS['MONGODB_CLASSES'][$collection])) {
            throw new Exception("Dbo::getClass cannot find $collection class");
        }
        return $GLOBALS['MONGODB_CLASSES'][$collection];
    }
    
    /**
     * Returns the name of a collection that is associated with a class.
     *
     * @param string $class
     * @return string
     **/
    static function getCollection($class) {
        if (!isset($GLOBALS['MONGODB_COLLECTIONS'][$class])) {
            throw new Exception("Dbo::getCollection cannot find $class collection");
        }
        return $GLOBALS['MONGODB_COLLECTIONS'][$class];
    }

    /**
     * Returns an iterator that will iterate through objects -- this should allow you to
     * go through large datasets without excessive memory allocation.
     *
     * @param string $class
     * @param array $query
     * @param array $options
     * @return Dboiterator
     **/
    static function find($class, $query = array(), $options = array()) {
        $collection = self::getCollection($class);
        $result = Db::find($collection, $query, $options);
        return new Dboiterator($class, $result);
    }

    /**
     * Just like Db::finda() but will return an array of objects
     *
     * @param string $class
     * @param array $query
     * @param array $options
     * @return array
     **/
    static function finda($class, $query = array(), $options = array()) {
        $collection = self::getCollection($class);
        $result = Db::find($collection, $query, $options);
        $objects = array();
        foreach ($result as $data) {
            $objects[] = new $class($data);
        }
        return $objects;
    }

    /**
     * Find a single data object, or null if not found
     *
     * @param string $class
     * @param mixed $id
     * @return Dbo
     **/
    static function findOne($class, $id = array()) {
        $collection = self::getCollection($class);
        $data = Db::findOne($collection, $id);
        return $data ? new $class($data) : null;
    }
    
    /**
     * Saves a data object in the correct collection, calling presave() first
     *
     * @param Dbo $object
     * @return boolean
     **/
    static function save($object) {
        $class = get_class($object);
        $collection = self::getCollection($class);
        $object->presave();
        return Db::save($collection, $object->_data);
    }
    
    /**
     * Removes a data object from its collection
     *
     * @param Dbo $object
     * @return boolean
     **/
    static function remove($object) {
        $class = get_class($object);
        $collection = self::getCollection($class);
        return Db::remove($collection, array('_id' => $object->_id));
    }

    /**
     * Looks up a database reference and returns a data object of the correct class
     *
     * @param array $dbref
     * @return Dbo
     **/
    static function getRef($dbref) {
        $class = self::getClass($dbref['$ref']);
        $data = Db::getRef($dbref);
        return $data ? new $class($data) : null;
    }

    /**
     * Recursively looks up the database references in e.g. an array of database references,
     * returning all references as data objects
     *
     * @param mixed $value
     * @return mixed
     **/
    static function expandRefs($value) {
        if (is_array($value)) {
            if (Db::isRef($value)) {
                return self::getRef($value);
            } else {
                foreach ($value as $k => $v) {
                    $value[$k] = self::expandRefs($v);
                }
            }
        } else if ($value instanceof Dbo) {
            foreach ($value->_data as $k => $v) {
                $value->_data[$k] = self::expandRefs($v);
            }
        }
        return $value;
    }

    /**
     * Converts an object or other data structure recursively to database references.
     *
     * @param mixed $value
     * @return mixed
     **/
    static function toRef($value) {
        if ($value instanceof Dbo) {
            $class = get_class($value);
            $collection = self::getCollection($class);
            return array('$ref' => $collection, '$id' => $value->_id);
        } else if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::toRef($v);
            }
        }
        return $value;
    }
  }

/**
* Helper iterator class for Dbo::find(), implements the Iterator interface so you can
* foreach your way through the returned result and not worry about the details.
**/
class Dboiterator implements Iterator {
    private $class;
    private $resultset;
    
    function __construct($class, $resultset) {
        $this->class = $class;
        $this->resultset = $resultset;
    }

    function current() {
        $result = $this->resultset->current();
        $obj = new $this->class($result);
        $obj->_data = $result;
        return $obj;
    }

    function key() {
        return $this->resultset->key();
    }

    function next() {
        return $this->resultset->next();
    }

    function rewind() {
        return $this->resultset->rewind();
    }

    function valid() {
        return $this->resultset->valid();
    }
}