<?php

namespace Grithin\Db;

use \Grithin\Db;
use \Grithin\Db\Psql;
use \Grithin\Db\Result;
use \Grithin\Dictionary;

class QueryBuilder{
	use QueryBuilder\ConformInputTrait;
	use QueryBuilder\ChangeActionsTrait;
	use QueryBuilder\FormatPartsTrait;

	public $grouping = ['psql'=>null, 'type'=>null];
	public $where_psqls = [];

	const FIELD_FLAG_RAW = 1;
	const FIELD_FLAG_NOT = 2;
	const FIELD_FLAG_COMPARE = 4;
	const FIELD_FLAG_IDENTITY = 8;

	function __construct(Db $db){
		$this->db = $db;


		#+ allow construction to take where arguments {
		$args = array_slice(func_get_args(), 1);
		if($args){
			call_user_func([$this, 'where'], $args);
		}
		#+ }

	}
	function __invoke(){
		return call_user_func_array([$this, 'where'], func_get_args());

	}
	public static function __methodCallable($method){
		if(method_exists(__CLASS__, $method)){
			return true;
		}
		#+ handle functions that have `_and_options` {
		if(method_exists(__CLASS__, $method.'_and_options')){
			return true;
		}
		#+ }
		#+ handle 'with_options' modifier {
		if(substr($method, -13) == '_with_options'){
			$base = substr($method, 0, -13);
			return method_exists(__CLASS__, $base.'_and_options');
		}
		#+ }
		#+ collect all the joins {
		if(strtolower(substr($method, -5)) == '_join'){
			return true;
		}
		#+ }
		#+ potentially get a result and format the output {
		if(method_exists(Result::class, $method)){
			return true;
		}
		#+ }
		return false;
	}
	function __call($method, $params){
		#+ handle functions that have `_and_options` {
		if(method_exists($this, $method.'_and_options')){
			return call_user_func([$this, $method.'_and_options'], $params);
		}
		#+ }
		#+ handle 'with_options' modifier {
		if(substr($method, -13) == '_with_options'){
			$base = substr($method, 0, -13);
			# the last argument is the option argument, regardless how many arguments
			$options = array_slice($params, -1);
			$options = $options ? $options[0] : [];
			$params = array_slice($params, 0, -1);
			return call_user_func([$this, $base.'_and_options'], $params, $options);
		}
		#+ }
		#+ collect all the joins {
		if(strtolower(substr($method, -5)) == '_join'){
			$type = strtoupper(substr($method,0, -5));
			$params = array_merge([$params[0], $type], array_slice($params, 1));
			return call_user_func_array([$this, 'join'], $params);
		}
		#+ }
		#+ potentially get a result and format the output {
		if(method_exists(Result::class, $method)){
			/* There are 3 cases to account for:
				1. use of result method after builder construction: $db->where('bob = 1')->from('user')->row()
				2. use with sql $db->row('select * from user where bob = 1')
				3. use of sql with result method parameters: $db->key_to_record('select * from users', 'name')
				4. old form that had ($table, $where) parameters.


				#3 would require getting the result method parameter count to determine what to pass to where
				and what to pass to the result method.
				#3 and #4 are now not supported
			*/
			if(!$this->where_psqls && !$this->from){
				call_user_func_array([$this, 'where'], $params);
				$params = [];
			}

			$r = $this->get();
			return call_user_func_array([$r, $method], $params);
		}
		#+ }

		throw new \Exception('Method not found "'.$method.'"');
	}
	public function __toString(){
		$psql = $this->to_psql();
		return $psql->sql;
	}
	public function dump(){
		\Grithin\Debug::out($this->to_psql());
	}

	# check to see if the state of the QueryBuilder is executable in SQL
	public function can_not_run(){
		if(!$this->from && !$this->has_psql()){
			return true;
		}
		return false;
	}

	/* determine whether this build has psql used for query */
	public function has_psql(){
		return (bool)($this->grouping['psql'] || $this->where_psqls);
	}



	#+ ======== handle making this into a psql ======== {
	public function aggregate_with_where($aggregate){
		$where_psql = $this->consolidated_wheres();
		if($where_psql && $where_psql->sql){
			$aggregate = Psql::many_to_one([$aggregate, ['WHERE '.$where_psql->sql, $where_psql->variables]]);
		}
		return $aggregate;
	}
	public function aggregate_with_from($psql_aggregate, $prefix='FROM'){
		if(!$this->from){
			throw new \Exception('No table selected');
		}

		# from
		$from = $this->from;
		if(!($from instanceof Psql)){
			$from = $this->format_from($from);
			$from = [$prefix." ".$from];
		}
		$psql_aggregate = Psql::many_to_one([$psql_aggregate, $from]);


		# joins
		if($this->joins){
			$join = $this->format_joins($this->joins);
			$psql_aggregate = Psql::many_to_one([$psql_aggregate, $join]);
		}
		return $psql_aggregate;
	}

	public function to_psql(){
		# choose which kind of psql to return
		if($this->select){
			return $this->select_psql();
		}
		if($this->into_psql){
			return $this->into_psql();
		}
		if($this->update_psql){
			return $this->update_psql();
		}
		return $this->select_psql();
	}

	public function select_psql(){
		$where_psql = $this->consolidated_wheres();

		if($this->from){ # this is intended to be a full statement
			/*
			most parts may be a psql, so check
			*/
			$psql_aggregate = [''];

			$select = $this->select;
			if(!($select instanceof Psql)){
				$select = $this->format_select($select);
				$select = ['SELECT '.$select];

			}
			$psql_aggregate = Psql::many_to_one([$psql_aggregate, $select]);


			# from
			$psql_aggregate = $this->aggregate_with_from($psql_aggregate);

			# where
			$psql_aggregate = $this->aggregate_with_where($psql_aggregate);


			$sql = $psql_aggregate->sql;


			$order = $this->format_order($this->order);
			if($order){
				$sql .= "\nORDER BY ".$order;
			}
			if($this->limit[1]){
				$sql .= "\nLIMIT ".$this->limit[1];
			}
			if($this->limit[0]){
				$sql .= "\nOFFSET ".$this->limit[0];
			}
			$group = $this->format_group($this->group);
			if($group){
				$sql .= "\nGROUP BY  ".$group;
			}

			$psql_aggregate->sql = $sql;

			if($this->having_psqls){
				$having_psql = Psql::many_to_one($this->having_psqls, 'and');
				$having_psql->sql = "HAVING ".$having_psql->sql;
				$psql_aggregate = Psql::many_to_one([$psql_aggregate, $having_psql]);
			}

			if($this->name){
				$psql_aggregate->sql = ' ( '.$psql_aggregate->sql.' ) '.$this->db->identity_quote($this->name);
			}
			return $psql_aggregate;
		}
		return $where_psql;
	}

	public function possibly_quote_identity($x){
		if($this->could_be_identity($x)){
			return $this->db->identity_quote($x);
		}else{
			# might be a as clause `tablename ref` or `tablename as ref`
			$parts = explode(' ', $x);
			if(count($parts) == 2 || count($parts) == 3){
				if($this->could_be_identity($parts[0])){
					$parts[0] =$this->db->identity_quote($parts[0]);
					return implode(' ', $parts);
				}
			}
		}
		return $x;
	}
	static function could_be_identity($x){
		if(preg_match('/[^a-z0-9\.]/i', $x)){  # non identity characters
			# might be JSON hierarchy, but don't quote it
			return false;
		}
		if(preg_match('/^[0-9]*$/i', $x)){
			return false; # a number
		}
		return true;
	}



	#+ ======== handle making this into a psql ======== }


	# check if some SQL statement has a limit
	public function should_limit(){
		if($this->limit){
			# there's already a limit using QB.  Should be find to modify
			return true;
		}

		# do an ok job and detecting the presence of an existing limit
		$last = end($this->where_psqls);
		if($last){
			return $this->sql_has_limit(Psql::from($last)->sql);
		}
		return true;
	}
	public static function sql_has_limit($sql){
		return preg_match('@[\s]*show|limit\s*[0-9]+(,\s*[0-9]+)\s*?$@i',$sql);
	}

	#+ ======== get/run methods ======== {
	public function get(){
		$psql = $this->select_psql();
		return $this->db->q($psql);
	}

	/** transaction dependent read lock (prevents other transactions from obtaining write lock */
	public function read_lock(){
		$psql = $this->select_psql();
		$psql->sql .= ' FOR SHARE';
		return $this->get();
	}
	/** transaction dependent write lock (prevents other transactions from
		writing, and prevents them from geting read lock) */
	public function write_lock(){
		$psql = $this->select_psql();
		$psql->sql .= ' FOR UPDATE';
		return $this->get();
	}


	#+ some methods need to be here so as to modify the query prior to running {
	public function value($name=null){
		# account for old style where SQL was provided to result method
		if($this->can_not_run()){
			call_user_func_array([$this, 'where'], func_get_args());
			$name = null;
		}
		if($name){
			$this->select($name);
		}
		if($this->should_limit()){
			$this->limit(1);
		}
		$r = $this->get();
		return $r->value();
	}
	public function row(){
		# account for old style where SQL was provided to result method
		if($this->can_not_run()){
			call_user_func_array([$this, 'where'], func_get_args());
		}
		if($this->should_limit()){
			$this->limit(1);
		}
		$r = $this->get();
		return $r->row();
	}
	public function exists(){
		$this->select('1');
		return $this->value() == 1;
	}
	public function numeric(){
		# account for old style where SQL was provided to result method
		if($this->can_not_run()){
			call_user_func_array([$this, 'where'], func_get_args());
		}

		if($this->should_limit()){
			$this->limit(1);
		}
		$r = $this->get();
		return $r->numeric();
	}
	public function column($name=null){
		# account for old style where SQL was provided to result method
		if($this->can_not_run()){
			call_user_func_array([$this, 'where'], func_get_args());
			$name = null;
		}

		if($name){
			$this->select($name);
		}
		$r = $this->get();
		return $r->column();
	}
	#+ }



	#+ ======== get/run methods ======== }

	/** get the psql that would prepresent all the current wheres consolicated */
	public function consolidated_wheres(){
		if($this->grouping['psql'] && $this->where_psqls){
			return $this->grouped_wheres();
		}elseif($this->grouping['psql']){
			return $this->grouping['psql'];
		}else{
			return Psql::many_to_one($this->where_psqls);
		}
	}
	/** group the previous logic group and the current */
	public function grouped_wheres(){
		$current = Psql::many_to_one($this->where_psqls, 'and');
		$current->sql = ' ( '.$current->sql.' ) ';
		if($this->grouping['psql']){
			$previous = $this->grouping['psql'];
			return Psql::many_to_one([$previous, $current], $this->grouping['type']);
		}else{
			return $current;
		}
	}
	/** reduce the previous where_psqls into single, and combine it with previous reductions */
	public function grouping_reduce(){
		if(!$this->where_psqls){
			return;
		}
		$this->grouping['psql'] = $this->grouped_wheres();

		$this->where_psqls = [];
	}

	public $unions;
	public function union($union){
		$this->unions[] = $union;
	}

	public $name;
	/** if the SQL statement will be used as a table, it needs a name */
	public function name($name){
		$this->name = $name;
		return $this;
	}


	public $joins = [];
	public function join($table, $type, $on=null){
		$this->joins[] = [$table, $type, $on];
		return $this;
	}

	public $select;
	public function select($select){
		$this->select = $select;
		return $this;
	}
	/* set the from */
	public $from = [];
	public function from($from){
		$this->from = $from;
		return $this;
	}

	/* append table to from */
	public function table($table){
		if(!is_array($this->from)){
			if($this->from){
				$this->from = [$this->from];
			}else{
				$this->from = [];
			}
		}
		$this->from[] = $table;
		return $this;
	}
	public $limit = [null,null];
	public function limit($limit){
		$args = func_get_args();
		if(count($args) == 2){
			$this->limit = [$args[0], $args[1]];
		}else{
			$this->limit[1] = $limit;
		}

		return $this;
	}
	public function offset($offset){
		$this->limit[0] = $offset;
		return $this;
	}
	public $order;
	/** params
	< order > (
		'name asc'
		||
		'name'
		||
		['name', 'asc']
	), ...

	*/
	public function order($order){
		$this->order = func_get_args();
		return $this;
	}
	public $group;
	public function group($order){
		$this->group = func_get_args();
		return $this;
	}





	public function in_statement($field, $value, $options){
		if(!Dictionary::in($value)){
			throw new \Exception('In statement value array must be a list, not a dictionary');
		}
		$sql = '';
		$variables = [];
		if($options['raw']){
			$sql = $this->db->identity_quote($field).' IN ('.implode(', ',$value).')';
			return [$sql];
		}elseif($options['identity']){
			# all the identity need to be separated quoted
			$sql = $this->db->identity_quote($field).' IN ('.implode(', ',array_map([$this->db,'identity_quote'],$value)).')';
			return [$sql];
		}else{
			$sql = $this->db->identity_quote($field).' IN ('.implode(', ',array_fill(0, count($value), '?')).')';
			return [$sql, $value];
		}
	}





	#+ { ======== select functions ======== {

	/** group the previous statements and combine with new states using AND */
	public function and_and_options($args, $options=[]){
		$this->grouping_reduce();
		$this->grouping['type'] = 'and';

		return $this->where_and_options($args, $options);
	}
	/** group the previous statements and combine with new states using OR */
	public function or_and_options($args, $options=[]){
		$this->grouping_reduce();
		$this->grouping['type'] = 'or';

		return $this->where_and_options($args, $options);
	}

	public $having_psqls = [];
	public function having_and_options($args, $options=[]){
		$this->having_psqls[] = $this->psql_from_where($args, $options);
		return $this;
	}

	function not_and_options($args, $options=[]){
		static $defaults = ['not'=>true];

		return $this->where_and_options($args, array_merge($options, $defaults));
	}
	function raw_and_options($args, $options=[]){
		static $defaults = ['raw'=>true];

		return $this->where_and_options($args, array_merge($options, $defaults));
	}
	function identity_and_options($args, $options=[]){
		static $defaults = ['identity'=>true];

		return $this->where_and_options($args, array_merge($options, $defaults));
	}

	public function where_and_options($args, $options=[]){
		$this->where_psqls[] = $this->psql_from_where($args, $options);
		return $this;
	}
	#+ } ======== select functions ======== }


}
