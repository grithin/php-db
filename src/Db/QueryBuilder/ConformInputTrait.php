<?php

namespace Grithin\Db\QueryBuilder;

use \Grithin\Db;
use \Grithin\Db\Psql;
use \Grithin\Db\Result;
use \Grithin\Tool;
use \Grithin\Dictionary;

trait ConformInputTrait{

	#+ { ======== utility functions ======== {

	public function psql_apply_options($psql, $options=[]){
		if(!empty($options['not'])){
			$psql->sql = 'NOT ( '.$psql->sql.' )';
		}
		return $psql;
	}


	/** field name may have special characters to use as interpretation of how to use it
	The convenience of a dictionary map of fields to values can be expanded by allowing special
	indicators within the field name that could not be confused with actual field characters

	prefixes
	-	':' the value is an identity, quote it as such
	-	'"' don't escape value
	-	'!' apply a not
	-	'?compare?' use compare instead of the default comparison
		-	should come last since it can be used with other flags
	*/
	public static function field_extract_indicators($field){
		static $possible_flags = ['"'=>self::FIELD_FLAG_RAW,'!'=>self::FIELD_FLAG_NOT,'?'=>self::FIELD_FLAG_COMPARE,':'=>self::FIELD_FLAG_IDENTITY];
		if($field && isset($possible_flags[$field[0]])){
			$flags = [];
			$additional = [];
			$flag = $possible_flags[$field[0]];
			$flags[] = $flag;

			$field = substr($field, 1);
			if($flag != self::FIELD_FLAG_COMPARE){
				if($field && isset($possible_flags[$field[0]])){
					$flags[] = $possible_flags[$field[0]];
				}
			}
			if(in_array(self::FIELD_FLAG_COMPARE, $flags)){
				preg_match('/(.*)?\?(.*)/', $field, $match);
				$field = $match[1];
				$additional['compare'] = $match[0];
			}
			return ['flags'=>$flags, 'additional'=>$additional, 'field'=>$field];
		}
		return false;
	}
	/** extract field indicators and translate to options, returning [$field, $options] */
	public function field_indicators_to_options($field){
		$options = [];
		$indicators = $this->field_extract_indicators($field);
		if($indicators){
			$flags = $indicators['flags'];
			if(in_array(self::FIELD_FLAG_COMPARE, $flags)){
				$options['compare'] = $indicators['additional']['compare'];
			}
			if(in_array(self::FIELD_FLAG_NOT, $flags)){
				$options['not'] = true;
			}
			if(in_array(self::FIELD_FLAG_RAW, $flags)){
				$options['raw'] = true;
			}
			if(in_array(self::FIELD_FLAG_IDENTITY, $flags)){
				$options['identity'] = true;
			}
		}
		return [$field, $options];
	}

	#+ } ======== utility functions ======== }


	#+ { ======== into functions ======== {
	public function psql_from_into($args, $options=[]){
		static $class = __CLASS__;

		$count = count($args);
		if($count == 1){
			if(is_object($args[0]) && $args[0] instanceof $class){
				# it's a QueryBuilder object, extract psql
				return $this->psql_apply_options($args[0]->to_psql(), $options);
			}elseif(Psql::conforms($args[0])){
				# it's a psql, add it
				return $this->psql_apply_options($args[0], $options);
			}elseif(is_array($args[0])|| is_object($args[0])){
				# it's an arrayable, assume '=' comparison of key to value
				$psqls = $this->psql_from_into_array($args[0], $options);
				return Psql::many_to_one($psqls, ', ');
			}elseif(is_string($args[0])){
				# it's a string, turn it into a psql and add it
				return $this->psql_apply_options([$args[0]], $options);
			}else{
				throw new \Exception('Unrecognized where argument');
			}
		}elseif($count == 2){
			if(is_array($args[1])){
				# handle old style inputs of the form ->insert($table, $set_dictionary);
				$this->table($args[0]);
				return $this->psql_from_into([$args[1]], $options);
			}else{
				return $this->psql_from_into_params($args[0], $args[1], '=', $options);
			}
		}elseif($count == 3){
			# the 3rd value is actually the options, so recall
			return $this->psql_from_into(array_slice($args,0,2), $args[2]);
		}
		throw new \Exception('Bad argument count');
	}
	public function kvvs_from_dictionary($array, $options=[]){
		$kvvs = [];
		foreach($array as $k=>$v){
			$kvvs[] = $this->kvv_from_into_params($k, $v, '=', $options);
		}
		return $kvvs;
	}
	/** from array input, which could be a dictionary or an array of dictionaries, make a psql */
	public function psql_from_into_array($array, $options=[]){
		$sets = [];
		if(!Dictionary::is($array)){
			# this is a list of records, not just one record dictionary
			foreach($array as $record){
				$sets[] = $this->kvvs_from_dictionary($record, $options);
			}
		}else{
			$sets[] = $this->kvvs_from_dictionary($array, $options);
		}
		$keys = array_map(function($x){ return $x[0]; }, $sets[0]);
		$values_sets = [];
		$variables = [];
		foreach($sets as $set){
			$value_set = [];
			foreach($set as $v){
				$value_set[] = $v[1];
				if($v[2]){
					$variables[] = $v[2];
				}
			}
			$values_sets[] = $value_set;
		}
		return $this->psql_from_ksv($keys, $values_sets, $variables);
	}
	static function psql_from_ksv($keys, $value_sets, $variables){
		$sql = ' ('.implode(',',$keys).")\t\nVALUES ";
		$sets = [];
		foreach($value_sets as $values){
			$sets[] = ' ( '.implode(',',$values).' ) ';
		}
		$sql .= implode(', ', $sets);

		return new Psql($sql, $variables);
	}

	public function psql_from_into_params($field, $value, $compare='=', $options=[]){
		$kvv = $this->kvv_from_into_params($field, $value, $compare, $options);
		return $this->psql_from_ksv($kvv[0], [$kvv[1]], $kvv[2]);
	}

	/** get a [key, value, variables] from "into" input params */
	public function kvv_from_into_params($field, $value, $compare='=', $options=[]){
		static $defaults = ['raw'=>false, 'identity'=>false, 'compare'=>false];
		$options = array_merge($defaults, $options);

		list($field, $field_options) = $this->field_indicators_to_options($field);
		$options = array_merge($options, $field_options);


		if(!Psql::variable_conforms($value)){
			throw new \Exception('Field "'.$field.'" has non conforming value');
		}else{
			$field_quoted = $this->db->identity_quote($field);
			if($value === null){
				return [$field_quoted, 'NULL', []];
			}
			if($options['raw']){
				return [$field_quoted, $value, []];
			}elseif($options['identity']){
				return [$field_quoted, $this->db->identity_quote($value), []];
			}else{
				return [$field_quoted, '?', [$value]];
			}
		}
	}
	#+ } ======== into functions ======== }

	#+ { ======== update functions ======== {
	public function psql_from_update($args, $options=[]){
		static $class = __CLASS__;

		$count = count($args);
		if($count == 1){
			if(is_object($args[0]) && $args[0] instanceof $class){
				# it's a QueryBuilder object, extract psql
				return $this->psql_apply_options($args[0]->to_psql(), $options);
			}elseif(Psql::conforms($args[0])){
				# it's a psql, add it
				return $this->psql_apply_options($args[0], $options);
			}elseif(is_array($args[0])|| is_object($args[0])){
				# check if it is a dictionary
				if(is_array($args[0]) && !Dictionary::is($args[0])){
					/* if it is not a dictionary, it is probably intended as [field, compare, value]
					so recall this method with it as the args */
					return $this->psql_from_update($args[0], $options);
				}
				# it's an arrayable, assume '=' comparison of key to value
				$psqls = $this->psqls_from_update_array($args[0], $options);
				return Psql::many_to_one($psqls, ',');
			}elseif(is_string($args[0])){
				# it's a string, turn it into a psql and add it
				return $this->psql_apply_options([$args[0]], $options);
			}else{
				throw new \Exception('Unrecognized where argument');
			}
		}elseif($count == 2){
			return $this->psql_from_where_params($args[0], $args[1], '=', $options);
		}elseif($count == 3){
			return $this->psql_from_where_params($args[0], $args[2], $args[1], $options);
		}elseif($count == 4){
			# the 4th value is actually the options, so recall
			return $this->psql_from_update(array_slice($args,0,3), $args[3]);
		}
		throw new \Exception('Bad argument count');
	}
	public function psqls_from_update_array($array, $options=[]){
		$psqls = [];
		foreach($array as $k=>$v){
			$psqls[] = $this->psql_from_update_params($k, $v, '=', $options);
		}
		return $psqls;
	}
	public function psql_from_update_params($field, $value, $compare='=', $options=[]){
		static $defaults = ['raw'=>false, 'identity'=>false, 'compare'=>false];
		$options = array_merge($defaults, $options);

		list($field, $field_options) = $this->field_indicators_to_options($field);
		$options = array_merge($options, $field_options);


		if(!Psql::variable_conforms($value)){
			throw new \Exception('Field "'.$field.'" has non conforming value');
		}else{
			$field_quoted = $this->db->identity_quote($field);
			if($value === null){
				$psql = [$field_quoted.' = NULL'];
			}
			if($options['raw']){
				$psql = [$field_quoted.' '.$compare.' '.$value];
			}elseif($options['identity']){
				$psql = [$field_quoted.' '.$compare.' '.$this->db->identity_quote($value)];
			}else{
				$psql = [$field_quoted.' '.$compare.' ?', [$value]];
			}
		}
		return $psql;
	}
	#+ } ======== update functions ======== }

	#+ { ======== where functions ======== {
	/* turn what is passed to where() into psql

	$args will always be an array: the args passed to where()
	*/
	public function psql_from_where($args, $options=[]){
		static $class = __CLASS__;

		$count = count($args);
		if($count == 1){
			if(is_object($args[0]) && $args[0] instanceof $class){
				# it's a QueryBuilder object, extract psql
				return $this->psql_apply_options($args[0]->to_psql(), $options);
			}elseif(Psql::conforms($args[0])){
				# it's a psql, add it
				return $this->psql_apply_options($args[0], $options);
			}elseif(is_array($args[0])|| is_object($args[0])){
				# check if it is a dictionary
				if(is_array($args[0]) && !Dictionary::is($args[0])){
					/* if it is not a dictionary, it is probably intennded as [field, compare, value]
					so recall this method with it as the args */
					return $this->psql_from_where($args[0], $options);
				}
				# it's an arrayable, assume '=' comparison of key to value
				$psqls = $this->psqls_from_where_array($args[0], $options);
				return Psql::many_to_one($psqls, 'and');
			}elseif(Tool::is_int($args[0])){
				# it's an id, turn it into a id where
				return $this->psql_from_where([['id', $args[0]]], $options);
			}elseif(is_string($args[0])){
				# it's a string, turn it into a psql and add it
				return $this->psql_apply_options([$args[0]], $options);
			}else{
				throw new \Exception('Unrecognized where argument');
			}
		}elseif($count == 2){
			return $this->psql_from_where_params($args[0], $args[1], '=', $options);
		}elseif($count == 3){
			return $this->psql_from_where_params($args[0], $args[2], $args[1], $options);
		}elseif($count == 4){
			# the 4th value is actually the options, so recall
			return $this->psql_from_where(array_slice($args,0,3), $args[3]);
		}
		throw new \Exception('Bad argument count');
	}
	/** turn a 4 parameter input into a psql */
	public function psql_from_where_params($field, $value, $compare='=', $options=[]){
		static $defaults = ['raw'=>false, 'not'=>false, 'identity'=>false, 'compare'=>false];
		$options = array_merge($defaults, $options);

		list($field, $field_options) = $this->field_indicators_to_options($field);
		$options = array_merge($options, $field_options);

		if(is_array($value)){
			#+ implied IN statement {
			$psql = $this->in_statement($field, $value, $options);
			#+ }
		}else{
			$field_quoted = $this->db->identity_quote($field);
			if($value === null){
				$psql = [$field_quoted.' IS NULL'];
			}
			if($options['raw']){
				$psql = [$field_quoted.' '.$compare.' '.$value];
			}elseif($options['identity']){
				$psql = [$field_quoted.' '.$compare.' '.$this->db->identity_quote($value)];
			}else{
				$psql = [$field_quoted.' '.$compare.' ?', [$value]];
			}
		}
		if($options['not']){
			$psql = [' NOT ( '.$psql->sql.' )', $psql->variables];
		}
		return $psql;
	}
	public function psqls_from_where_array($array, $options=[]){
		$psqls = [];
		foreach($array as $k=>$v){
			$psqls[] = $this->psql_from_where_params($k, $v, '=', $options);
		}
		return $psqls;
	}
	#+ } ======== where functions ======== }
}
