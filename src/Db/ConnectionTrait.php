<?php

namespace Grithin\Db;

trait ConnectionTrait{
	/** Construct a new instance of the lazy loaded DB (does not connect to DB until necessary) */
	/** params
	< connection_info >
		driver: < ex: 'mysql'|'postgres'|'sqlite' >
		database: < database name >
		host: < host ip >
		user: <>
		password: <>
		backup: < another connection_info array, also with allowance for more nested backup keys >
	< options >	{
			loader: <(
				< external loader function that returns a PDO instance and is given params ($dsn, $user, $password)  >
				< this can be used to allow Db to use the same PDO instance another framework already made >
			)>
			pdo: < PDO instance to use >
			sql_mode: < blank or `ANSI`.  defaults to `ANSI`.  Controls quote style of ` or " >
		}
	*/
	public function __construct($connection_info=[], $options=[]){
		$this->connection_info = $connection_info;
		$this->quote_style = '`';
		$this->options = array_merge(['sql_mode'=>'ANSI'], $options);;
		if(!empty($options['pdo'])){
			$this->loaded = true;
			$this->under = $pdo;
			$this->driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
		}else{
			/* must set the driver because some methods (like identity_quote) rely on it */
			if(!empty($connection_info['driver'])){
				$this->driver = $connection_info['driver'];
			}elseif(!empty($connection_info['dsn'])){
				$this->driver = explode(':', $connection_info['dsn'], 2)[0];
			}else{
				throw new \Exception('missing driver');
			}
		}
	}

	protected $under;
	public function __get($key){
		# must load before PDO is present
		if($key == 'under'){
			$this->ensure_loaded();
			return $this->under;
		}
	}

	public $loaded = false; # < for lazy loading
	/** make sure Db has been connected to before doing things */
	public function ensure_loaded(){
		if(!$this->loaded){
			$this->load();
		}
	}
	public $driver; # the driver.  Ex 'mysql'
	/** actually connect to the database, or call the custom loader
	This will attempt to load the backup if the main fails and backup connection info is provided
	 */
	public function load(){
		if($this->loaded){
			return;
		}
		if(empty($this->connection_info['dsn'])){
			$this->connection_info['dsn'] =  self::make_dsn($this->connection_info);
		}

		try{
			$this->connect($this->connection_info);
		}catch(\PDOException $e){
			# if there is a backup connection, try that
			if(!empty($this->connection_info['backup'])){
				$this->connection_info = $this->connection_info['backup'];
				$this->load();
				return;
			}
			throw $e;
		}
		$this->driver = $this->under->getAttribute(\PDO::ATTR_DRIVER_NAME);
		$this->loaded = true;
		if($this->driver=='mysql'){
			if($this->options['sql_mode'] == 'ANSI'){
				$this->query('SET SESSION sql_mode=\'ANSI\'');
				$this->quote_style = '"';
			}
			# force UTC
			$this->query('SET SESSION time_zone=\'+00:00\'');
			#$this->under->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
		}
	}
	/** try to connect using a connection_info array */
	public function connect($connection_info){
		if(!empty($this->options['loader'])){ # use custom loader if available
			$this->under = $this->options['loader']($this->connection_info);
			if(!$this->under || !($this->under instanceof \PDO)){
				throw new \Exception('Loader function did not provide PDO instance');
			}
		}else{ # use regular PDO instance construction
			$this->connection_info = array_merge(['user'=>null, 'dsn'=>null, 'password'=>null], $this->connection_info);
			$this->under = new \PDO($this->connection_info['dsn'], $this->connection_info['user'], $this->connection_info['password']);
			$this->under->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		}
	}

	/** make the DSN string from an array of info */
	public static function make_dsn($connection_info){
		$connection_info['port'] = !empty($connection_info['port']) ? $connection_info['port'] : '3306';
		return $connection_info['driver'].':dbname='.$connection_info['database'].';host='.$connection_info['host'].';port='.$connection_info['port'];
	}
}