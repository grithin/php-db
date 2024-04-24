<?php

namespace Grithin\Db;

class Result{
	public function __construct(\Grithin\Db $Db, $res, $sql){
		$this->db = $Db;
		$this->res = $res;
		$this->sql = $sql;
	}
	/** refresh the PDO res by re-running query */
	public function refresh(){
		$r = $this->remake();
		$this->res = $r->res;
	}
	/** get a new Result by re-running query */
	public function remake(){
		return $this->db->q($this->sql);
	}

	public function value(){
		return $this->res->fetchColumn();
	}
	/** row with columns keyed by column name */
	public function row(){
		return $this->res->fetch(\PDO::FETCH_ASSOC);
	}
	/** row with columns numerically keyed */
	public function numeric(){
		return $this->res->fetch(\PDO::FETCH_NUM);
	}
	/** rows with columns keyed by column name */
	public function rows(){
		$res2 = [];
		$i = 0;
		while($row=$this->res->fetch(\PDO::FETCH_ASSOC)){
			foreach($row as $k=>$v){
				$res2[$i][$k]=$v;
			}
			$i++;
		}
		return $res2;
	}
	/** array of values */
	public function column(){
		$res2 = [];
		while($row=$this->res->fetch(\PDO::FETCH_NUM)){
			$res2[]=$row[0];
		}
		return $res2;
	}

	/** rows with columns numerically keyed */
	public function numerics(){
		$res2 = [];
		while($row = $this->res->fetch(\PDO::FETCH_NUM)){
			$res2[]=$row;
			return $res2;
		}
	}
	/** use some field as the key and point to the row array */
	public function key_to_record($key){
		$rows = $this->rows();
		return Arrays::key_on_sub_key($rows, $key);
	}
	/** use some field as the key and point to some single column value */
	public function key_to_column($key, $column){
		$rows = $this->rows();
		return Arrays::key_on_sub_key_to_remaining($rows, $key, ['only'=>$column]);
	}
	public function count(){
		return $this->res->rowCount;
	}

	public $id;
	public function id(){
		return $this->resolve_id();
	}
	/** get the last id from PDO and set it */
	public function resolve_id(){
		if(!$this->id){
			$this->id = $this->db->under->lastInsertId();
		}
		return $this->id;
	}
}
