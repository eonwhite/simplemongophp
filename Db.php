<?php

/**
 * Class Db
 *
 * @package SimpleMongoPhp
 * @author Ian White (ibwhite@gmail.com)
 * @version 1.0
 *
 * This is a simple library to wrap around the Mongo API and make it a little more convenient
 * to use for a PHP web application.
 *
 * To set up, all you need to do is:
 *    - include() or require() this file
 *    - define a global variable named $mongo that represents your Mongo connection object
 *    - define('MONGODB_NAME', <the name of your database>);
 *
 * Example usage:
 *   $mongo = new Mongo();
 *   define('MONGODB_NAME', 'lost');
 *
 *   Db::drop('people');
 *   Db::batchInsert('people', array(
 *     array('name' => 'Jack', 'sex' => 'M', 'goodguy' => true),
 *     array('name' => 'Kate', 'sex' => 'F', 'goodguy' => true),
 *     array('name' => 'Locke', 'sex' => 'M', 'goodguy' => true),
 *     array('name' => 'Hurley', 'sex' => 'M', 'goodguy' => true),
 *     array('name' => 'Ben', 'sex' => 'M', 'goodguy' => false),
 *   ));
 *   foreach (Db::find('people', array('goodguy' => true), array('sort' => array('name' => 1))) as $p) {
 *     echo $p['name'] " is a good guy!\n";
 *   }
 *   $ben = Db::findOne('people', array('name' => 'Ben'));
 *   $locke = Db::findOne('people', array('name' => 'Locke'));
 *   $ben['enemy'] = Db::createRef('people', $locke);
 *   $ben['goodguy'] = null;
 *   Db::save('people', $ben);
 *
 * See the Dbo.php class for how you could do the same thing with data objects.
 *
 * This library may be freely distributed and modified for any purpose.
 **/
class Db {
    /**
     * Returns a MongoId from a string, MongoId, array, or Dbo object
     *
     * @param mixed $obj
     * @return MongoId
     **/
     static function id($obj) {
        if ($obj instanceof MongoId) {
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
     * @return boolean
     **/
    static function isRef($value) {
        if (!is_array($value)) {
            return false;
        }
        return MongoDBRef::isRef($value);
    }

    /**
     * Returns a Mongo database reference created from a collection and an id
     *
     * @param string $collection
     * @param mixed $id
     * @return array
     **/
    static function createRef($collection, $id) {
        return array('$ref' => $collection, '$id' => self::id($id));
    }

    /**
     * Returns the Mongo object array that a database reference points to
     *
     * @param array $dbref
     * @return array
     **/
    static function getRef($dbref) {
        global $mongo;
        $db = $mongo->selectDB(MONGODB_NAME);
        return $db->getDBRef($dbref);
    }

    /**
     * Recursively expands any database references found in an array of references,
     * and returns the expanded object.
     *
     * @param mixed $value
     * @return mixed
     **/
    static function expandRefs($value) {
        if (is_array($value)) {
            if (self::isRef($value)) {
                return self::getRef($value);
            } else {
                foreach ($value as $k => $v) {
                    $value[$k] = self::expandRefs($v);
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
     * @param array $query
     * @param array $options
     * @return MongoCursor
     **/
    static function find($collection, $query = array(), $options = array()) {
        global $mongo;
        $col = $mongo->selectCollection(MONGODB_NAME, $collection);
        $fields = isset($options['fields']) ? $options['fields'] : array();
        $result = $col->find($query, $fields);
        if (isset($options['sort'])) {
            $result->sort($options['sort']);
        }
        if (isset($options['limit'])) {
            $result->limit($options['limit']);
        }
        if (isset($options['skip'])) {
            $result->skip($options['skip']);
        }
        return $result;
    }

    /**
     * Just like find, but return the results as an array (of arrays)
     *
     * @param string $collection
     * @param array $query
     * @param array $options
     * @return array
     **/
    static function finda($collection, $query = array(), $options = array()) {
        $result = self::find($collection, $query, $options);
        $array = array();
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
     * @param array $query
     * @param array $options
     * @return array
     **/
    static function findField($collection, $field, $query = array(), $options = array()) {
        $options['fields'] = array($field => 1);
        $result = self::find($collection, $query, $options);
        $array = array();
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
     * @param array $query
     * @param array $options
     * @return array
     **/
    static function findAssoc($collection, $key_field, $value_field, $query = array(), $options = array()) {
        $options['fields'] = array($key_field => 1, $value_field => 1);
        $result = self::find($collection, $query, $options);
        $array = array();
        foreach ($result as $val) {
            $array[$val[$key_field]] = $val[$value_field];
        }
        return $array;
    }
    
    /**
     * Find a single object -- like Mongo's findOne() but you can pass an id as a shortcut
     *
     * @param string $collection
     * @param mixed $id
     * @return array
     **/
    static function findOne($collection, $id) {
        global $mongo;
        $col = $mongo->selectCollection(MONGODB_NAME, $collection);
        if (!is_array($id)) {
            $id = array('_id' => self::id($id));
        }
        return $col->findOne($id);
    }

    /**
     * Count the number of objects matching a query in a collection (or all objects)
     *
     * @param string $collection
     * @param array $query
     * @return integer
     **/
    static function count($collection, $query = array()) {
        global $mongo;
        $col = $mongo->selectCollection(MONGODB_NAME, $collection);
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
     * @param array $data
     * @return boolean
     **/
    static function save($collection, $data) {
        global $mongo;
        $col = $mongo->selectCollection(MONGODB_NAME, $collection);
        return $col->save($data);
    }

    /**
     * Shortcut for MongoCollection's update() method
     *
     * @param string $collection
     * @param array $criteria
     * @param array $newobj
     * @param boolean $upsert
     * @return boolean
     **/
    static function update($collection, $criteria, $newobj, $upsert = false) {
        global $mongo;
        $col = $mongo->selectCollection(MONGODB_NAME, $collection);
        return $col->update($criteria, $newobj, $upsert);
    }

    /**
     * Shortcut for MongoCollection's update() method, performing an upsert
     *
     * @param string $collection
     * @param array $criteria
     * @param array $newobj
     * @return boolean
     **/
    static function upsert($collection, $criteria, $newobj) {
        return self::update($collection, $criteria, $newobj, true);
    }

    /**
     * Shortcut for MongoCollection's remove() method, with the option of passing an id string
     *
     * @param string $collection
     * @param array $criteria
     * @param boolean $just_one
     * @return boolean
     **/
    static function remove($collection, $criteria, $just_one = false) {
        global $mongo;
        $col = $mongo->selectCollection(MONGODB_NAME, $collection);
        if (!is_array($criteria)) {
            $criteria = array('_id' => self::id($criteria));
        }
        return $col->remove($criteria, $just_one);
    }

    /**
     * Shortcut for MongoCollection's drop() method
     *
     * @param string $collection
     * @return boolean
     **/
    static function drop($collection) {
        global $mongo;
        $col = $mongo->selectCollection(MONGODB_NAME, $collection);
        return $col->drop();
    }

    /**
     * Shortcut for MongoCollection's batchInsert() method
     *
     * @param string $collection
     * @param array $array
     * @return boolean
     **/
    static function batchInsert($collection, $array) {
        global $mongo;
        $col = $mongo->selectCollection(MONGODB_NAME, $collection);
        return $col->batchInsert($array);
    }

    /**
     * Shortcut for MongoCollection's ensureIndex() method
     *
     * @param string $collection
     * @param array $keys
     * @return boolean
     **/
    static function ensureIndex($collection, $keys) {
        global $mongo;
        $col = $mongo->selectCollection(MONGODB_NAME, $collection);
        return $col->ensureIndex($keys);
    }

    /**
     * Ensure a unique index (there is no direct way to do this in the MongoCollection API now)
     *
     * @param string $collection
     * @param array $keys
     * @return boolean
     **/
    static function ensureUniqueIndex($collection, $keys) {
        global $mongo;
        $name_parts = array();
        foreach ($keys as $k => $v) {
            $name_parts[] = $k;
            $name_parts[] = $v;
        }
        $name = implode('_', $name_parts);
        $col = $mongo->selectCollection(MONGODB_NAME, 'system.indexes');
        $col->save(array('ns' => MONGODB_NAME . ".$collection",
                         'key' => $keys,
                         'name' => $name,
                         'unique' => true));
    }

    /**
     * Shortcut for MongoCollection's getIndexInfo() method
     *
     * @param string $collection
     * @return array
     **/
    static function getIndexInfo($collection) {
        global $mongo;
        $col = $mongo->selectCollection(MONGODB_NAME, $collection);
        return $col->getIndexInfo();
    }

    /**
     * Shortcut for MongoCollection's deleteIndexes() method
     *
     * @param string $collection
     * @return boolean
     **/
    static function deleteIndexes($collection) {
        global $mongo;
        $col = $mongo->selectCollection(MONGODB_NAME, $collection);
        return $col->deleteIndexes();
    }
}