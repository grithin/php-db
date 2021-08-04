<?php
namespace Grithin\Db;

/** Database level named locks */
class Lock{
	public function __construct($db, $name, $options=[]){
		static $defaults = [
				'persist'=>false, # close on exit
				'timeout'=>10,
			];

		$options = array_merge($defaults, $options);
		$this->db = $db;
		$this->name = $name;
		$this->name_quoted = $this->db->quote($this->name);
		$this->options = $options;

		$this->lock($options['timeout']);
	}
	public function __destruct(){
		if(!$this->options['persist']){
			$this->unlock();
		}
	}
	public function lock($timeout=10){
		$this->locked = $this->db->value(["select GET_LOCK(?, ?)", [$this->name, $timeout]]);
		return $this->locked;
	}
	public function locked(){
		return $this->locked;
	}
	/** checks against database regardless of local locked variable */
	public function is_free(){
		return $this->db->value(["SELECT IS_FREE_LOCK(?)", [$this->name]]);
	}
	public function release(){
		if($this->locked){
			$this->unlock();
		}else{
			return false;
		}
	}
	public function unlock(){
		$this->locked = false;
		return $this->db->value(["SELECT RELEASE_LOCK(?)", [$this->name]]);
	}
}
