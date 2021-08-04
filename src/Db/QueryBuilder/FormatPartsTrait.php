<?php

namespace Grithin\Db\QueryBuilder;

use \Grithin\Db;
use \Grithin\Db\Psql;
use \Grithin\Db\Result;
use \Grithin\Dictionary;

trait FormatPartsTrait{
	public function format_group($group){
		if($group){
			if(!is_array($group)){
				$parts = explode(' ', $group);
				if(count($parts) == 1){
					return $this->db->identity_quote($group);
				}
				return $group;
			}else{
				$groups = array();
				foreach($group as $v){
					$groups[] = $this->format_group($v);
				}
				return implode(', ', $groups);
			}
		}
	}
	public function format_order($order){
		if($order){
			if(!is_array($order)){
				$parts = explode(' ', $order);
				if(count($parts) == 1){
					return $this->db->identity_quote($order).' ASC';
				}elseif(count($parts) == 2){
					return $this->db->identity_quote($parts[0]).' '.$parts[1];
				}
				return $order;
			}else{
				$orders = array();
				foreach($order as $v){
					$orders[] = $this->format_order($v);
				}
				return implode(', ', $orders);
			}
		}
	}
	public function format_select($select){
		if(!$select){
			return '*';
		}elseif(is_string($select)){
			# see if this is a single column, and not some function like `NOW()`
			return $this->possibly_quote_identity($select);

		}elseif(is_array($select)){
			return implode(', ',array_map([$this->db,'identity_quote'],$select));
		}
	}
	public function format_from($from){
		if(is_array($from)){
			return implode(', ', array_map([$this->db,'identity_quote'],$from));
		}elseif(strpos($from,' ') === false){//ensure no space; don't quote a from statement
			return $this->db->identity_quote($from);
		}
	}

	public function format_joins($joins){
		static $class = __CLASS__;
		$psqls = [];
		foreach($joins as $join){
			$join[1] = $join[1] ?: 'NATURAL';

			if($join[0] instanceof Psql){
				$psqls[] = $join[0];
			}elseif($join[0] instanceof $class){
				$psql = $join[0]->to_psql();
				$psql->sql = $join[1].' JOIN '.$psql->sql;

				if($join[2]){
					$psql->sql .= ' ON ';
					$on_psql = $this->psql_from_where([$join[2]], ['identity'=>true]);
					$psqls[] = Psql::many_to_one([$psql, $on_psql]);
				}else{
					$psqls[] = $psql;
				}
			}else{
				if(!$join[1] && !$join[2]){
					# see if this is a single table or a full join statement
					if(preg_match('/[^a-z0-9\.]/i', $select)){
						$psqls[] = [$join[0]];
					}else{
						$psqls[] = 'NATURAL JOIN '.$this->db->identity_quote($join[0]);
					}
				}else{
					$psql = $join[1].' JOIN '.$this->db->identity_quote($join[0]);
					if($join[2]){
						$psql .= ' ON ';
						$on_psql = $this->psql_from_where([$join[2]], ['identity'=>true]);
						$psqls[] = Psql::many_to_one([$psql, $on_psql]);
					}else{
						$psqls[] = $psql;
					}
				}
			}
		}
		return Psql::many_to_one($psqls);
	}
}