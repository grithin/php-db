# PHP Db Interaction

## Why?
I considered the current query builders to be unwieldy and lacking some of the features I built in this one.

## Caution
This was a personal project of mine that I built in a day.  Since I decided to stop building projects in PHP, in favor of node, this code has not seen production nor does it have unit tests.  Also, I was writing this readme as I was coding, so it may be off.



## Initializing
```php
$db = new Db(['dsn'=>'sqlite:'.$path]);
$connection_info = [
	'user'=>'bob',
	'password'=>'bob',
	'database'=>'bob',
	'host'=>'localhost',
	'driver'=>'mysql'];
$db = new Db($connection_info);
```

## Invoke Variety

```php
# select
$db->rows('select * from users')
$db->table('users')->rows()

# update
$db->q('update test set name = ? where name = ?', ['bob',' bill']); #  > Result
$db->query('update test set name = ? where name = ?', ['bob',' bill']); # > PDO Result
$db->where('name','bill')->update(['name'=>'bob']); # QueryBuilder fallback
```



## Query Builder


Db invoke allows select query and then format.  B/c does not run query until format, using `row()` will add a `limit 1`.
```php
$db('select * from test')->row();
$r = $db(['select * from test where name = ?', ['bill']])->row();

```


```php
### Grouped Logic ###
$db->where(['bob'=>'sue', 'age'=>123])
	->or(['age'=>5])('name', 'like', '%bob%')
	->and('x', 'y');

/*>
( `bob` = ?
	AND `age` = ?
)
OR  (
	`age` = ?
		AND `name` like ?
)
AND  ( `x` = ? )
*/


### Composition ###
$where1 = $db->build('age', 5);
$db->where(['bob'=>'sue'])->not($where1);

/*>
(
	`bob` = ?
 	AND NOT (  ( `age` = ? )  )
)
*/


### Basic Full ###
$db->where('name', 'bob')
	->or('name', 'like', 'b%')
	->from('users')
	->select(['name', 'COUNT()'])
	->order('name')
	->limit(100)
	->offset(10)
	->group('name')
	->having('COUNT()', '>', 1);
/*>

SELECT `name`, `COUNT()`
FROM `users`
WHERE  ( `name` = ? )
	OR  ( `name` like ? )
ORDER BY `name` ASC
LIMIT 100
OFFSET 10
GROUP BY  `name`
HAVING `COUNT()` > ?
*/


```



## Options
-	`raw`
-	`identities`
-	`not`

```php
$r = $db()->where_with_options('x', 'y', ['raw'=>true]);
```




## Updates, Inserts
```php

### Holding ###
# don't do update, just make QueryBuilder object hold the update
$update = $db()->update_with_options(['name'=>'bob'], ['hold'=>true]);

# use the previous
$db()->where('id', 5)->update($update);


### Repeating ###
$builder = $db();
$builder->where('id', 5)->update('"increment', 'increment + 1');
# because the builder holds the last update, it will re-apply it if called again
$builder->update();

```

## Having
You can also supply another QueryBuilder instance to `having()` (which would allow for OR'ing)

```php
$where_bob = $db->build('name', 'bob');
$query = $db->build()
	->from('users')
	->group('name')
	->having($where_bob);

```


## Joins

Allows any `*_join` statement

It is expected you will do your own joins.  However, you can use QueryBuilder composition for that.
```php
### Basic ###
$where('name', 'bob')
	->from('users')
	->select(['name', 'COUNT()'])
	->left_join('comments', 'users.id = comments.user_id');

### Quoted Identities ###
->left_join('comments', ['users.id'=>'comments.user_id']);

### Variable `on` Input ###
->left_join('comments', ['users.id', 'comments.user_id']);
->left_join('comments', ['users.id', '>', 'comments.user_id']);

### `on` Composition
->left_join('comments', $db->build(['user.id' => 10]));

### Join Composition
/*
It is possible to join one QueryBuilder into another
*/
$where_bob = $db->build('name', 'bob')
	->from('users')
	->select(['name', 'id)'])
	->name('bob'); # here we name this query as a table

$where = $db->build()
	->from('users')
	->select(['name', 'id)'])
	->left_join($where_bob, 'bob.id = users.id'); # here we refer to the name we gave it
/*
SELECT `name`, `id)`
FROM `users`
LEFT JOIN  (
	SELECT `name`, `id)`
	FROM `users`
	WHERE  ( `name` = ? )  
) `bob` ON bob.id = users.id
*/




```



## Notes
-	if driver `mysql`
	-	sets `sql_mode` to ansi to allow interroperability with postgres
	-	sets timezone to UTC
-	access to PDO available with `->under`



## Init Additional
### Special

Already present PDO object
```php
$db = new Db(['PDO'=>$PDO]);
```

Custom loader to produce PDO
```php
$loader = function(){
	return FRAMEWORK_PDO_INSTANCE
};
$db = new Db(['loader'=>$loader]);
```


### Singleton
One main database and one named accessory database

```php
$db = Db::singleton(['dsn'=>'sqlite:'.$path]);

# ...
$db2 = Db::singleton_named('path2', ['dsn'=>'sqlite:'.$path2]);


# calls to the same will produce the same instance
$db === Db::singleton();
$db === Db::singleton(['dsn'=>'sqlite:'.$path]);
$db2 === Db::singleton_named('path2');
$db2 === Db::singleton_named('path2', ['dsn'=>'sqlite:'.$path2]);
```

