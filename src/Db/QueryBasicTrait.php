<?php

namespace Grithin\Db;

use Grithin\Tool;



trait QueryBasicTrait{
	/** latest result set returning from $db->query() */
	public $result;
	/** last method call, args, and last sql thing (which might be SQL string + variables, or just SQL string). Ex  [call:[fn,args],sql:sql] */
	public $last_sql;



	public $quote_cache = []; #< since quote with Db function may involve request to Db, to minimize requests, cache these
	/** returns escaped string with quotes.	Use on values to prevent injection. */
	/**
	@param	v	the value to be quoted
	*/
	public function quote($v, $use_cache=true){
		$this->ensure_loaded(); # lazy loading

		if(is_numeric($v)){ # numerics don't need quoting
			return $v;
		}
		if(!Tool::is_scalar($v)){
			$v = (string)$v;
		}

		# caching
		if($use_cache && strlen($v)<=250){ # no reason to expect multiple occurrence of same long text quotes
			if(!$this->quote_cache[$v]){
				$this->quote_cache[$v] = $this->under->quote($v);
			}
			return $this->quote_cache[$v];
		}
		return $this->under->quote($v);
	}
	/** handles [a-z9-9_] style identities without asking Db to do the quoting */
	public function identity_quote($identity,$separation=true){
		if($this->driver == 'sqlite'){ # doesn't appear to accept seperation
			if(strpos($identity,'.')!==false){
				# sqlite doesn't handle assigning . quoted columns on results, so just ignore and hope nothing cause syntax error
				return $identity;
			}
		}
		$quote = $this->quote_style;
		$identity = $quote.$identity.$quote;
		#Fields like user.id to "user"."id"
		if($separation && strpos($identity,'.')!==false){
			$identity = implode($quote.'.'.$quote,explode('.',$identity));
		}
		return $identity;
	}
	/** return last run sql */
	public function last_sql(){
		if(!is_string($this->last_sql)){
			return json_encode($this->last_sql);
		}else{
			return $this->last_sql;
		}
	}
	/** perform database query */
	/**
	@param	sql	the sql to be run
	@return the executred PDOStatement object
	*/
	public function query($sql){
		$this->ensure_loaded(); # lazy loading

		# clear opened, unclosed cursors, if any
		if($this->result){
			$this->result->closeCursor();
		}


		# Generate a prepared statement
		if(is_array($sql)){
			$sql = $this->prepare($sql);
		}elseif($sql instanceof Psql){
			$sql = $this->prepare($sql);
		}


		if(is_a($sql, \PDOStatement::class)){
			$success = false;
			$this->last_sql = [$sql->queryString, $sql->psql->variables];
			try{
				$success = $sql->execute($sql->psql->variables);
			}catch(\Exception $e){}

			if(!$success){
				$this->handle_error($sql);
			}
			$this->result = $sql;
		}else{
			$this->last_sql = $sql;
			$this->result = $this->under->query($sql);
		}

		$this->handle_error($this->under);

		if(!$this->result){
			$this->result = $this->retry(__FUNCTION__, [$sql]);
		}
		return $this->result;
	}
	/** return a Db\Result instead of a PDO result */
	public function q($sql){
		$res = $this->query($sql);
		return new Result($this, $res, $sql);
	}



	/** runs self::psqls, creates a PDOStatement, sets a custom `variables` attribute of the PDOStatement object, returning that PDOStatement */
	public function prepare($psql){
		$this->ensure_loaded(); # lazy loading

		if(is_array($psql)){
			$psql = Psql::many_to_one($psql);
		}


		if($this->result){
			$this->result->closeCursor();
		}

		$this->last_sql = $psql;
		# PDO doesn't bind variables until it executes the statement, so just present the SQL
		$prepared = $this->under->prepare($psql->sql, array(\PDO::ATTR_CURSOR => \PDO::CURSOR_FWDONLY));

		$this->handle_error($this->under);

		if(!$prepared){
			$prepared = $this->retry(__FUNCTION__, func_get_args());
		}
		$prepared->psql = $psql; # add property psql here so we can bind the variables during execution
		return $prepared;
	}
	/** Used for prepared statements, returns raw PDO result */
	/** Ex: $db->as_rows($db->exec('select * from languages where id = :id', [':id'=>181]) */
	/**

	@return	executed PDOStatement

	Takes a mix of sql strings and variable arrays, as either a single array parameter, or as parameters
	Examples
		-	single array: $db->exec(['select * from user where id = :id',[':id'=>1]])
		-	as params: $db->exec('select * from','user where id = :id',[':id'=>1],'and id = :id2',[':id2'=>1] );
	*/
	public function exec(){
		return $this->query(call_user_func_array([$this,'prepare'], func_get_args()));
	}

}