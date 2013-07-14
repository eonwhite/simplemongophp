# SimpleMongoPhp #
## by Ian White (ibwhite@gmail.com) ##

This is a very simple library to wrap around the Mongo API and make it a
little more convenient to use for a PHP web application.

There are some other libraries you can use if you need a more complex object-
document mapper. The goal of this one is to be simple, useful, and fast.

SimpleMongoPhp was developed for Business Insider (businessinsider.com), and
is used in production there. If you wind up using this library, let me know!
It's under the Apache license, which is the same license as the Mongo PHP
driver itself.

There are two classes -- you can use just Db.php as a standalone to simplify
collection access, or you can use Dbo.php as well for light data objects.


## Key features ##

Just a library, doesn't lock you into an approach or attempt to abstract away
the database. You can use objects but have the flexibility to drop into Db::
calls.

Shortcuts to return results as an array (finda, findAssoc, etc), and querying
using array options rather than method chaining. This is important if you want
to cache your queries and results.

Handy objects with attribute accessor syntax, presave/preremove hooks.

Support for Mongo-style dot notation when accessing object properties

Utilities for automatic Dbref expansion

No more calling getCollection() all the time!


## Db.php ##

Db is a very light wrapper that mostly shortcuts the tedious getCollection()
process. It uses an "array options" style for querying rather than method
chaining, because this is easier to cache.

To set up, all you need to do is:
* include() Db.php
* call Db::addConnection(<new Mongo object>, <name of your database>)
  
Example usage:
```php
$mongo = new Mongo();
define('MONGODB_NAME', 'lost');

Db::drop('people');
Db::batchInsert('people', array(
  array('name' => 'Jack', 'sex' => 'M', 'goodguy' => true),
  array('name' => 'Kate', 'sex' => 'F', 'goodguy' => true),
  array('name' => 'Locke', 'sex' => 'M', 'goodguy' => true),
  array('name' => 'Hurley', 'sex' => 'M', 'goodguy' => true),
  array('name' => 'Ben', 'sex' => 'M', 'goodguy' => false),
));
foreach (Db::find('people',
                  array('goodguy' => true),
                  array('sort' => array('name' => 1))) as $p) {
  echo $p['name'] . " is a good guy!\n";
}
$ben = Db::findOne('people', array('name' => 'Ben'));
$locke = Db::findOne('people', array('name' => 'Locke'));
$ben['enemy'] = Db::createRef('people', $locke);
$ben['goodguy'] = null;
Db::save('people', $ben);
```

## Dbo.php ##

Dbo builds on top of Db to support light data objects. ActiveRecord-style
abstractions for relationships are not supported and probably won't be.

Generally, your data objects extend the Dbo class. You can call most of the
same static methods you can call on Db; you just get back classed objects
instead of associative arrays.

To set up, all you need to do is:
* include() or require() both Db.php and Dbo.php
* call Db::addConnection(<new Mongo object>, <name of your database>)
* create any number of data object classes that extend Dbo
* call Dbo::addClass(<class name>, <collection name>) for each class


Example usage (this code does basically the same thing as the code above)
```php
Db::addConnection(new Mongo(), 'lost');
class LostPerson extends Dbo {
  function rollCall() {
    echo "$this->name is a " . ($this->goodguy ? 'good' : 'bad') . " guy!\n";
  }
}
Dbo::addClass('LostPerson', 'people');

Db::drop('people');
Db::batchInsert('people', array(
  array('name' => 'Jack', 'sex' => 'M', 'goodguy' => true),
  array('name' => 'Kate', 'sex' => 'F', 'goodguy' => true),
  array('name' => 'Locke', 'sex' => 'M', 'goodguy' => true),
  array('name' => 'Hurley', 'sex' => 'M', 'goodguy' => true),
  array('name' => 'Ben', 'sex' => 'M', 'goodguy' => false),
));
foreach (Dbo::find('LostPerson',
                   array('goodguy' => true),
                   array('sort' => array('name' => 1))) as $p) {
  $p->rollCall();
}
$ben = Dbo::findOne('LostPerson', array('name' => 'Ben'));
$locke = Dbo::findOne('LostPerson', array('name' => 'Locke'));
$ben->enemy = Dbo::toRef($locke);
$ben->goodguy = null;
Dbo::save($ben);
  
$jack = Dbo::findOne('LostPerson', array('name' => 'Jack'));
$jack->{"skills.surgery"} = 8;
$jack->{"skills.leadership"} = 3;
echo "Jack is a " . $jack->{"skills.surgery"} . " at surgery...\n";
echo "... but a " . $jack->skills['leadership'] . " at leadership.\n";  
```  
  
## Version Notes ##

### 1.0 - 07/28/2009 ###
* First release!

### 1.1 - 12/29/2009 ###
* You can now use mongo "dot notation" to get/set Dbo objects, like so:
  ```php
  $jack->{"skills.surgery"} = 8;
  $jack->{"skills.leadership"} = 3;
  ```

  This will get saved as ```{ skills: { surgery: 8, leadership: 3 }}```

  You could retrieve how good Jack is at surgery one of two ways:
  ```$jack->skills['surgery']```
    or
  ```$jack->{'skills.surgery'}```
  
 * Added preremove() hook to go with presave()
 * Made Dboiterator Countable
 
### 1.2 - 9/29/2010 ###
 * No dependency on the MONGODB_NAME or $mongo globals
 * Support for by-collection sharding -- set different collections
   to talk to different Mongo instances using Db::addConnection() --
   useful for poor-man's sharding such as having a different box for
   your analytics collection
 * Support for readSlave() -- tell a script to perform all reads
   on a slave and writes on a master (will add replica set SLAVE_OK
   support when that gets added to Mongo)


## License Information ##

Copyright 2009 Ian White

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

  http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
