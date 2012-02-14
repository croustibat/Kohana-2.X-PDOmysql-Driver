<?php defined('SYSPATH') OR die('No direct access allowed.');
/*
 * Class: Database_PdoMysql_Driver
 * Provides specific database items for PDO Mysql.
 *
 * Connection string should be, eg: "pdomysql://path/to/database.db"
 *
 * Version 1.0 alpha
 *  author    - Croustibat <https://github.com/croustibat>
 *  copyright - (c) BSD
 *  license   - <no>
 */

class Database_Pdomysql_Driver extends Database_Driver {

	// Database connection link
	protected $link;
	protected $db_config;

	/*
	 * Constructor: __construct
	 *  Sets up the config for the class.
	 *
	 * Parameters:
	 *  config - database configuration
	 *
	 */
	public function __construct($config)
	{
		$this->db_config = $config;

		Kohana::log('debug', 'PDO:Mysql Database Driver Initialized');
	}

	public function connect()
	{
		// Import the connect variables
		extract($this->db_config['connection']);

		try
		{
			// build dsn
			$dsn = 'mysql:host='.$host.';dbname='.$database;
			
			if($socket)
			{
				$dsn .= ";port=$socket";
			}
			
			if ($charset = $this->db_config['character_set'])
			{
				 $dsn .= ";charset=$charset";
			}
			
			// Force PDO to use exceptions for all errors
			$attrs = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
		
			if ( ! empty($this->db_config['persistent']))
			{
				// Make the connection persistent
				$attrs[PDO::ATTR_PERSISTENT] = TRUE;
			}
		
			$this->link = new PDO($dsn, $user, $pass, $attrs);
		}
		catch (PDOException $e)
		{
			throw new Kohana_Database_Exception('database.error', $e->getMessage());
		}

		// Clear password after successful connect
		$this->db_config['connection']['pass'] = NULL;

		return $this->link;
	}

	public function query($sql)
	{
    try
		{
			$sth = $this->link->prepare($sql, array(PDO::ATTR_CURSOR, PDO::CURSOR_SCROLL));
		}
		catch (PDOException $e)
		{
			throw new Kohana_Database_Exception('database.error', $e->getMessage());
		}
		
		return new Pdomysql_Result($sth, $this->link, $this->db_config['object'], $sql);
	}

	public function set_charset($charset)
	{
		$this->link->query('PRAGMA encoding = '.$this->escape_str($charset));
	}

	public function escape_table($table)
	{
		if ( ! $this->db_config['escape'])
			return $table;

		return '`'.str_replace('.', '`.`', $table).'`';
	}

	public function escape_column($column)
	{
		if ( ! $this->db_config['escape'])
			return $column;

		if ($column == '*')
			return $column;

		// This matches any functions we support to SELECT.
		if ( preg_match('/(avg|count|sum|max|min)\(\s*(.*)\s*\)(\s*as\s*(.+)?)?/i', $column, $matches))
		{
			if ( count($matches) == 3)
			{
				return $matches[1].'('.$this->escape_column($matches[2]).')';
			}
			else if ( count($matches) == 5)
			{
				return $matches[1].'('.$this->escape_column($matches[2]).') AS '.$this->escape_column($matches[2]);
			}
		}

		// This matches any modifiers we support to SELECT.
		if ( ! preg_match('/\b(?:rand|all|distinct(?:row)?|high_priority|sql_(?:small_result|b(?:ig_result|uffer_result)|no_cache|ca(?:che|lc_found_rows)))\s/i', $column))
		{
			if (stripos($column, ' AS ') !== FALSE)
			{
				// Force 'AS' to uppercase
				$column = str_ireplace(' AS ', ' AS ', $column);

				// Runs escape_column on both sides of an AS statement
				$column = array_map(array($this, __FUNCTION__), explode(' AS ', $column));

				// Re-create the AS statement
				return implode(' AS ', $column);
			}

			return preg_replace('/[^.*]+/', '`$0`', $column);
		}

		$parts = explode(' ', $column);
		$column = '';

		for ($i = 0, $c = count($parts); $i < $c; $i++)
		{
			// The column is always last
			if ($i == ($c - 1))
			{
				$column .= preg_replace('/[^.*]+/', '`$0`', $parts[$i]);
			}
			else // otherwise, it's a modifier
			{
				$column .= $parts[$i].' ';
			}
		}
		return $column;
	}

	public function limit($limit, $offset = 0)
	{
		return 'LIMIT '.$offset.', '.$limit;
	}

	public function compile_select($database)
	{
		$sql = ($database['distinct'] == TRUE) ? 'SELECT DISTINCT ' : 'SELECT ';
		$sql .= (count($database['select']) > 0) ? implode(', ', $database['select']) : '*';

		if (count($database['from']) > 0)
		{
			$sql .= "\nFROM ";
			$sql .= implode(', ', $database['from']);
		}

		if (count($database['join']) > 0)
		{
			foreach($database['join'] AS $join)
			{
				$sql .= "\n".$join['type'].'JOIN '.implode(', ', $join['tables']).' ON '.$join['conditions'];
			}
		}

		if (count($database['where']) > 0)
		{
			$sql .= "\nWHERE ";
		}

		$sql .= implode("\n", $database['where']);

		if (count($database['groupby']) > 0)
		{
			$sql .= "\nGROUP BY ";
			$sql .= implode(', ', $database['groupby']);
		}

		if (count($database['having']) > 0)
		{
			$sql .= "\nHAVING ";
			$sql .= implode("\n", $database['having']);
		}

		if (count($database['orderby']) > 0)
		{
			$sql .= "\nORDER BY ";
			$sql .= implode(', ', $database['orderby']);
		}

		if (is_numeric($database['limit']))
		{
			$sql .= "\n";
			$sql .= $this->limit($database['limit'], $database['offset']);
		}

		return $sql;
	}

	public function escape_str($str)
	{
		if ( ! $this->db_config['escape'])
			return $str;

		if (function_exists('mysql_escape_string'))
		{
			$res = mysql_escape_string($str);
		}
		else
		{
			$res = str_replace("'", "''", $str);
		}
		return $res;
	}

	public function list_tables()
	{
		$tables = array();

		if ($query = $this->query('SHOW TABLES FROM '.$this->escape_table($this->db_config['connection']['database'])))
		{
			foreach ($query->result(FALSE) as $row)
			{
				$tables[] = current($row);
			}
		}

		return $tables;
	}

	public function show_error()
	{
		$err = $this->link->errorInfo();
		return isset($err[2]) ? $err[2] : 'Unknown error!';
	}

	public function list_fields($table, $query = FALSE)
	{
		$columns = array();

		if ($query = $this->link->query('SHOW COLUMNS FROM '.$this->escape_table($table)))
		{
			foreach ($query->fetchAll() as $row)
			{
				$columns[$row['Field']] = $this->sql_type($row['Type']);
			}
		}

		return $columns;
	}

	public function field_data($table)
	{
		Kohana::log('error', 'This method is under developing');
	}
	/**
	 * Version number query string
	 *
	 * @access	public
	 * @return	string
	 */
	function version()
	{
		return $this->link->getAttribute(constant("PDO::ATTR_SERVER_VERSION"));
	}

} // End Database_PdoSqlite_Driver Class

/*
 * PDO-mysql Result
 */
class Pdomysql_Result extends Database_Result {

	// Data fetching types
	protected $fetch_type  = PDO::FETCH_OBJ;
	protected $return_type = PDO::FETCH_ASSOC;
	public $seekableResult = array();
		
	/**
	 * Sets up the result variables.
	 *
	 * @param  resource  query result
	 * @param  resource  database link
	 * @param  boolean   return objects or arrays
	 * @param  string    SQL query that was run
	 */
	public function __construct(PDOStatement $result, $link, $object = TRUE, $sql)
	{	
		if( is_object($result) )
		{
			// run the query. Return true if success, false otherwise
			if( ! $result->execute() )
			{
				// Throw Kohana Exception with error message. See PDOStatement errorInfo() method
				$arr_infos = $result->errorInfo();
				throw new Kohana_Database_Exception('database.error', $arr_infos[2]);
			}

			if (preg_match('/^SELECT|EXPLAIN/i', $sql))
			{
				$this->result 			= $result;
				$this->current_row 	= 0;
				$this->total_rows 	= $result->rowCount();

				$this->fetch_type = ($object === TRUE) ? PDO::FETCH_OBJ : PDO::FETCH_ASSOC;
			}
			elseif (preg_match('/^DELETE|INSERT|UPDATE/i', $sql))
			{
				$this->insert_id  = $link->lastInsertId();
				$this->total_rows = $result->rowCount();
			}
		}
		else
		{
			// SQL error
			throw new Kohana_Database_Exception('database.error', $link->errorInfo().' - '.$sql);
		}

		// Set result type
		$this->result($object);

		// Store the SQL
		$this->sql = $sql;
	}

	/*
	 * Destructor: __destruct
	 *  Magic __destruct function, frees the result.
	 */
	public function __destruct()
	{
		if (is_object($this->result))
		{
			$this->result->closeCursor();
			$this->result = null;
			$this->seekableResult = array();
		}
	}

	public function result($object = TRUE, $type = PDO::FETCH_BOTH)
	{
		$this->fetch_type = (bool) $object ? PDO::FETCH_OBJ : PDO::FETCH_BOTH;

		if ($this->fetch_type == PDO::FETCH_OBJ)
		{
			$this->return_type = (is_string($type) AND Kohana::auto_load($type)) ? $type : 'stdClass';
		}
		else
		{
			$this->return_type = $type;
		}
		
		return $this;
	}

	public function as_array($object = NULL, $type = PDO::FETCH_ASSOC)
	{
		return $this->result_array($object, $type);
	}

	public function result_array($object = NULL, $type = PDO::FETCH_ASSOC)
	{
		$rows = array();

		if (is_string($object))
		{
			$fetch = $object;
		}
		elseif (is_bool($object))
		{
			if ($object === TRUE)
			{
				$fetch = PDO::FETCH_OBJ;

				// NOTE - The class set by $type must be defined before fetching the result,
				// autoloading is disabled to save a lot of stupid overhead.
				$type = (is_string($type) AND Kohana::auto_load($type)) ? $type : 'stdClass';
			}
			else
			{
				$fetch = PDO::FETCH_OBJ;
			}
		}
		else
		{
			// Use the default config values
			$fetch = $this->fetch_type;

			if ($fetch == PDO::FETCH_OBJ)
			{
				$type = (is_string($type) AND Kohana::auto_load($type)) ? $type : 'stdClass';
			}
		}
		try
		{
			while ($row = $this->result->fetch($fetch))
			{
				$rows[] = $row;
			}
		}
		catch(PDOException $e)
		{
			throw new Kohana_Database_Exception('database.error', $e->getMessage());
			return FALSE;
		}
		return $rows;
	}

	public function list_fields()
	{
		$field_names = array();
		for ($i = 0, $max = $this->result->columnCount(); $i < $max; $i++)
		{
			$info = $this->result->getColumnMeta($i);
			$field_names[] = $info['name'];
		}
		return $field_names;
	}

	public function seek($offset)
	{
		// To request a scrollable cursor for your PDOStatement object, you must
		// set the PDO::ATTR_CURSOR attribute to PDO::CURSOR_SCROLL when you
		// prepare the statement.
		//Kohana::log('error', get_class($this).' does not support scrollable cursors, '.__FUNCTION__.' call ignored');
		return FALSE;
	}

	public function offsetGet($offset)
	{
		try
		{
			if(isset($this->seekableResult[$offset])) {
				return $this->seekableResult[$offset];
			}
				
			$this->seekableResult[$offset] = $this->result->fetch($this->fetch_type);	
			return $this->seekableResult[$offset];  
		}
		catch(PDOException $e)
		{
			throw new Kohana_Database_Exception('database.error', $e->getMessage());
		}
	}

} // End PdoMysql_Result Class