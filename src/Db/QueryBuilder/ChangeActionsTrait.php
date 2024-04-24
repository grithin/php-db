<?php

namespace Grithin\Db\QueryBuilder;

use \Grithin\Db;
use \Grithin\Db\Psql;
use \Grithin\Db\Result;
use \Grithin\Dictionary;

trait ChangeActionsTrait{
	public function delete_and_options($args, $options=[]){
		$this->where_and_options($args, $options);

		$psql_aggregate = $this->aggregate_with_from([''], 'DELETE FROM ');

		if(!$this->risky && !$this->grouping['psql']){
			throw new \Exception('No "where" on delete.  Call "risky()->delete(...)" to run');
		}

		# where
		$psql_aggregate = $this->aggregate_with_where($psql_aggregate);
		return $this->db->q($psql_aggregate);
	}

	public function replace_and_options($args, $options=[]){
		if($this->driver == 'sqlite'){
			$type = 'INSERT OR REPLACE INTO';
		}else{
			$type = 'REPLACE INTO';
		}
		return $this->into($args, $type);
	}
	/** Insert with a table and ignore if duplicate key found */
	public function insert_ignore_and_options($args, $options=[]){
		if($this->driver == 'sqlite'){
			$type = 'INSERT OR IGNORE INTO';
		}else{
			$type = 'INSERT IGNORE INTO';
		}
		return $this->into($args, $type);
	}
	/** insert into table; on duplicate key update */
	public function insert_update($insert, $update=null){
		if(!$update){
			$update = $insert;
		}
		$this->update_and_options($update, ['hold'=>true]);
		return $this->into($insert, 'INSERT INTO', ['update'=>true]);
	}
	public function insert_and_options($args, $options=[]){
		return $this->into($args, 'INSERT INTO', $options);
	}
	public $into_psql;
	/**
	@note	insert ignore and insert update do not return a row id, so, if the id is not provided and the matchKeys are not provided, may not return row id
	@return will attempt to get row id, otherwise will return count of affected rows
	*/
	public function into($args, $type, $options=[]){
		static $option_defaults = ['return_id'=>true];
		$options = array_merge($option_defaults, $options);
		if($args){
			$this->into_psql = $this->psql_from_into($args, $options);
		}elseif(!$this->into_psql){
			throw new \Exception('No "into" args provided');
		}

		#+ option indicated not to run the action {
		if(!empty($options['hold'])){
			return $this;
		}
		#+ }

		$psql_aggregate = $this->into_psql($type);

		$result = $this->db->q($psql_aggregate);
		$result->resolve_id();
		if($options['return_id']){
			return $result->id;
		}
		return $result;
	}
	public function into_psql($type){
		$psql_aggregate = $this->aggregate_with_from([''], $type);

		# set
		$this->into_psql = Psql::from($this->into_psql);
		$psql_aggregate = Psql::many_to_one([$psql_aggregate, $this->into_psql]);

		if(!empty($options['update'])){
			$this->update_psql = Psql::from($this->update_psql);
			$psql_aggregate = Psql::many_to_one([$psql_aggregate, ['ON DUPLICATE KEY UPDATE '.$this->update_psql->sql, $this->update_psql->variables]]);
		}

		# where
		$psql_aggregate = $this->aggregate_with_where($psql_aggregate);
		return $psql_aggregate;
	}



	public $risky = false;
	public function risky($risky=true){
		$this->risky = $risky;
	}


	public $update_psql = [];
	public function update_and_options($args, $options=[]){
		if($args){
			$this->update_psql = $this->psql_from_update($args, $options);
		}elseif(!$this->update_psql){
			throw new \Exception('No update provided');
		}

		#+ option indicated not to run the action {
		if(!empty($options['hold'])){
			return $this;
		}
		#+ }

		#+ validate {
		if(!$this->risky && !$this->has_psql()){
			throw new \Exception('No "where" on update.  Call "risky()->update(...)" to run');
		}
		#+ }

		$psql_aggregate = $this->update_psql();

		return $this->db->q($psql_aggregate);
	}

	public function update_psql(){
		$psql_aggregate = $this->aggregate_with_from([''], 'UPDATE ');

		# set
		$this->update_psql = Psql::from($this->update_psql);
		$psql_aggregate = Psql::many_to_one([$psql_aggregate, ['SET '.$this->update_psql->sql, $this->update_psql->variables]]);

		# where
		$psql_aggregate = $this->aggregate_with_where($psql_aggregate);
		return $psql_aggregate;
	}
}
