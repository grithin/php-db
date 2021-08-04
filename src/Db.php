<?php
namespace Grithin;
use Grithin\Arrays;
use Grithin\Tool;
use Grithin\Debug;
use Grithin\Db\QueryBuilder;
use Grithin\Db\Lock;

/** Interact with a database */

Class Db{
	use \Grithin\Traits\ClassSingletons;
	use Db\ConnectionTrait;
	use Db\PublicInfoTrait;
	use Db\QueryBasicTrait;
	use Db\TransactionsTrait;
	use Db\SchemaTrait;

	#+ defer to QueryBuilder {
	public function __invoke(){
		return call_user_func_array([$this, 'build'], func_get_args());
	}
	public function __call($method, $params){
		# pass on to QueryBuilder if it has the method
		if(QueryBuilder::__methodCallable($method)){
			$builder = new QueryBuilder($this);
			return call_user_func_array([$builder, $method], $params);
		}else{
			throw new \Exception('No such method "'.$method.'"');
		}
	}
	public function build(){
		$builder = new QueryBuilder($this);
		$args = func_get_args();
		if($args){
			call_user_func_array([$builder, 'where'], func_get_args());
		}
		return $builder;
	}
	#+ }

	public function lock($name, $options=[]){
		return new Lock($this, $name, $options);
	}


	/**
		PDOStatement and PDO object both have `errorCode` and `errorInfo`, and a statement may have an error without showing up in the PDO object.
	*/
	public function handle_error($errorable=null, $additional_info=false){
		if((int)$errorable->errorCode()){
			$error = $errorable->errorInfo();
			$error = "--DATABASE ERROR--\n".' ===ERROR: '.$error[0].'|'.$error[1].'|'.$error[2];
			if($additional_info){
				$error .= "\n===ADDITIONAL: ".$additional_info;
			}
			$error .= "\n ===SQL: ".$this->last_sql();
			Debug::toss($error, 'DbException');
		}
	}
	/** when nothing has been returned, there was likely a timeout, so try to reconnect once*/
	public function retry($function, $arguments){
		if($this->reconnecting){
			Debug::toss("--DATABASE ERROR--\nNo result, likely connection timeout", 'DbException');
		}
		$this->reconnecting = true;
		$this->load();
		$return = call_user_func_array([$this, $function], $arguments);
		$this->reconnecting = false;
		return $return;
	}
}
