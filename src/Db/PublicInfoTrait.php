<?php

namespace Grithin\Db;

use Grithin\Arrays;

trait PublicInfoTrait{
	/** extract non-sensitive info from connection info for use in debugging
	@return	array database connection info
	*/
	public function public_info(){
		$info = Arrays::pick($this->connection_info, ['driver', 'database', 'host', 'port']);
		$info['driver'] = $this->driver;
		$info['class'] = __CLASS__;
		return $info;
	}
	/** if the Db object is printed, display the public info */
	function __toArray(){
		return $this->public_info();
	}
	/** if the Db object is printed, display the public info */
	function __toString(){
		return var_export($this->public_info(),true);
	}
}