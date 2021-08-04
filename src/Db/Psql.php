<?php

namespace Grithin\Db;

use Grithin\Tool;
use Grithin\Arrays;

class Psql{
	public $sql = '';
	public $variables = [];
	public function __construct($sql, $variables=[]){
		$this->sql = $sql;
		if(is_array($variables) || is_object($variables)){
			$this->variables = Arrays::from($variables);
		}
	}
	public function __toString(){
		return $this->sql;
	}



	/** Conform some input to a psql construct input */
	/**
	A `psql` is an array of the form [sql_string, variables] or [sql_string]

	There are a number of ways input can arrive and be transormed
	into a psql.  This handles that transformation.
	*/
	/** return
	[< query string >, < variables >]
	*/
	/** params
	< input >
		< sql string >
		||
		< array variables >
		||
		[
			(
				< sql string >
				||
				< variables >
			), ...
		]
	*/
	public static function conform($input, $combine = null){
		static $class = __CLASS__;

		# may already by a Psql
		if($input instanceof $class){
			return [$input->sql, $input->variables];
		}


		$combine = self::separator_conform($combine);

		if(is_string($input)){
			return [$input, []];
		}elseif(is_array($input)){
			$sql = [];
			$variables = [];
			foreach($input as $v){
				if(is_string($v)){
					$sql[] = $v;
				}else{
					if(!is_array($v)){
						throw new \Exception('Non-conforming psql input');
					}
					$variables = array_merge($variables, $v);
				}
			}
			$sql = implode($combine, $sql);
			return [$sql, $variables];
		}else{
			throw new \Exception('Unrecognized input');
		}
	}


	public static function separator_conform($combine=null){
		if($combine === null){
			$combine = "\n";
		}else{
			$combine = strtolower(trim($combine));
			if($combine == 'or'){
				$combine = "\n\tOR ";
			}elseif($combine == 'and'){
				$combine = "\n\tAND ";
			}
		}

		return $combine;
	}


	/** determine if a variable conforms to what is recognized as a psql */
	public static function conforms($x){
		if(isset($x[0]) && is_string($x[0])){
			if(!isset($x[0]) || (is_array($x[1]) || is_object($x[1]))){
				return true;
			}
			return false;
		}
		return false;
	}
	public static function from($conform){
		static $class = __CLASS__;
		$psql = self::conform($conform);
		return new $class($psql[0], $psql[1]);
	}


	/** Combine multiple psqls into a single psql using a separator */
	/** params
	< psqls > [
			(< psql > | < sql >), ...
		]
	< combine > < "" | "OR" | "AND" > < the way to combined the SQL string >
	*/
	/** definitions
	psql: [
			< sql >
			< variables > [
				< variable >, ...
			]
		]
	*/

	/** notes
	-	on nulls: `null` is properly filled, but still will not work in conventional parts:
		`['id is ?', [null]]` works
		`['id = ?', [null]]` works, but fails to find anything
		`['id in (?)', [null]]` works, but fails to find anything
		-	for lists including null, must separate:
			`['id in (?, ?) or id is ?', [1, 2, null]]`
	*/
	/** Example: combining wheres
	(	[psql, psql], ' AND ' 	)
	*/
	public static function many_to_one($psqls, $combine=null){
		$combine = self::separator_conform($combine);

		$sql = [];
		$variables = [];

		foreach($psqls as $psql){
			list($psql_sql, $psql_variables) = self::conform($psql);
			if($psql_sql){
				$sql[] = $psql_sql;
			}
			if($psql_variables){
				$variables = array_merge($variables, $psql_variables);
			}
		}
		$sql = implode($combine, $sql);
		# ensure no non-scalar variables

		foreach($variables as $variable){
			# if this is a non-scalar and does not have a __toString method, error
			if(!self::variable_conforms($variable)){
				throw new \Exception('Non scalar SQL statement variable: '.var_export($variable, true));
			}
		}
		return new Psql($sql, $variables);
	}
	/** check if a variable conforms to something usable */
	public static function variable_conforms($variable){
		if(!Tool::is_scalar($variable) &&  ! (is_object($variable) && method_exists($variable, '__toString')) ){
			return false;
		}
		return true;
	}

}
