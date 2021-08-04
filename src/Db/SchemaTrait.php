<?php

namespace Grithin\Db;

use \Grithin\Db;
use \Grithin\Db\Psql;
use \Grithin\Db\Result;
use \Grithin\Dictionary;



trait SchemaTrait{
	//+	db information {
		public function table_exists($table){
			if($this->tablesInfo[$table]){
				return true;
			}
			return (bool) count($this->rows('show tables like '.$this->quote($table)));
		}
		/**Get database tables */
		public function tables(){
			$this->ensure_loaded(); # lazy loading

			$driver = $this->under->getAttribute(\PDO::ATTR_DRIVER_NAME);
			if($driver == 'mysql'){
				return $this->column('show tables');
			}elseif($driver == 'sqlite'){
				return $this->column('SELECT name FROM sqlite_master WHERE type='.$this->quote('table'));
			}
			throw new \Exception('Unsupported driver "'.$driver.'" for function');
		}

		public $tablesInfo = [];
		/**get database table column information */
		public function table_info($table){
			$this->ensure_loaded(); # lazy loading


			if(!$this->tablesInfo[$table]){
				$columns = array();
				$keys = array();
				$driver = $this->under->getAttribute(\PDO::ATTR_DRIVER_NAME);
				if($driver == 'mysql'){
					//++ get the columns info {
					$rows = $this->rows('describe '.$this->identity_quote($table));
					foreach($rows as $row){
						$column =& $columns[$row['Field']];
						$column['type'] = self::column_type_parse($row['Type']);
						$column['limit'] = self::column_limit_parse($row['Type']);
						$column['nullable'] = $row['Null'] == 'NO' ? false : true;
						$column['autoIncrement'] = preg_match('@auto_increment@',$row['Extra']) ? true : false;
						$column['default'] = $row['Default'];
						$column['key'] = $row['Key'] == 'PRI' ? 'primary' : $row['Key'];
					}
					//++ }

					//++ get the unique keys info {
					$rows = $this->rows('show index in '.$this->identity_quote($table));
					foreach($rows as $row){
						if($row['Non_unique'] === '0'){
							$keys[$row['Key_name']][] = $row['Column_name'];
						}
					}
					//++ }
				}elseif($driver == 'sqlite'){
					$statement = $this->value('SELECT sql FROM sqlite_master WHERE type='.$this->quote('table').' and tbl_name = '.$this->quote($table));
					if($statement){
						$info = self::create_statement_parse($statement);
						$columns = $info['columns'];
					}
				}
				$this->table_info[$table] = ['columns'=>$columns,'keys'=>$keys];
			}
			return $this->table_info[$table];
		}
		public static function create_statement_parse($statement){
			preg_match('/create .*?[`"](.*?)[`"].*?\((.*)\)/sim', $statement, $match);
			$table = $match[1];
			$content = $match[2];
			$lines = preg_split('/\n/', $content);
			$columns = [];
			foreach($lines as $line){
				preg_match('/[`"`](.*?)[`"`]([^\n]+)/', $line, $match);
				if($match){
					$columns[$match[1]] = ['type'=>self::column_type_parse($match[2])];
				}
			}
			return ['table'=>$table, 'columns'=>$columns];
		}
		public function column_names($table){
			return array_keys($this->table_info($table)['columns']);
		}
		/**take db specific column type and translate it to general */
		public static function column_type_parse($type){
			$type = trim(strtolower(preg_replace('@\([^)]*\)|,@','',$type)));
			if(preg_match('@int@i',$type)){//int,bigint
				return 'int';
			}elseif(preg_match('@decimal@i',$type)){
				return 'decimal';
			}elseif(preg_match('@float@i',$type)){
				return 'float';
			}elseif(preg_match('@datetime|date|timestamp@i',$type)){
				return $type;
			}elseif(preg_match('@varchar|text@i',$type)){
				return 'text';
			}
		}
		public static function column_limit_parse($type){
			preg_match('@\(([0-9,]+)\)@',$type,$match);
			if(!empty($match[1])){
				$limit = explode(',',$match[1]);
				return $limit[0];
			}
		}
		public $indices;
		/** get all the keys in a table, including the non-unique ones */
		public function indices($table){
			if(!$this->indices[$table]){
				$rows = $this->rows('show indexes in '.$this->identity_quote($table));
				foreach($rows as $row){
					if(empty($keys[$row['Key_name']])){
						$keys[$row['Key_name']] = ['unique'=>!(bool)$row['Non_unique']];
					}
					$keys[$row['Key_name']]['columns'][$row['Seq_in_index']] = $row['Column_name'];
				}
				$this->indices[$table] = $keys;
			}
			return $this->indices[$table];
		}
	//+ }
}