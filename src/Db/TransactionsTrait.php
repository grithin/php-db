<?php

namespace Grithin\Db;

use \Grithin\Db;
use \Grithin\Db\Psql;
use \Grithin\Db\Result;
use \Grithin\Dictionary;

trait TransactionsTrait{
	# run a closure within a transaction
	public function transaction($closure){
		$this->transaction_begin();
		try{
			$closure();
		}catch(\Exception $e){
			$this->transaction_rollback();
			throw $e;
		}
		$this->transaction_commit();
	}
	/** start a transaction on the PDO instance */
	public function transaction_begin(){
		$this->ensure_loaded(); # lazy loading

		$this->under->beginTransaction();
	}

	/** commit the transaction, ending it */
	public function transaction_commit(){
		$this->ensure_loaded(); # lazy loading

		$this->under->commit();
	}
	/** cancel the transaction */
	public function transaction_rollback(){
		$this->ensure_loaded(); # lazy loading

		$this->under->rollBack();
	}
}