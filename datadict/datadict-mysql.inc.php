<?php

/**
  @version   v5.22.0-dev  Unreleased
  @copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
  @copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.

  Set tabs to 4 for best viewing.

*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

class ADODB2_mysql extends ADODB_DataDict {
	public  $databaseType = 'mysql';
	public  $alterCol = ' MODIFY COLUMN';
	public  $alterTableAddIndex = true;
	public  $dropTable = 'DROP TABLE IF EXISTS %s'; // requires mysql 3.22 or later

	public  $dropIndex = 'DROP INDEX %s ON %s';
	public  $renameColumn = 'ALTER TABLE %s CHANGE COLUMN %s %s %s';	// needs column-definition!
	public  $sql_sysDate = 'CURDATE()';
	public  $sql_sysTimeStamp = 'NOW()';
	public  $nameQuote = '`';

	public $blobAllowsNotNull = true;
	
	/*public function MetaType($t,$len=-1,$fieldobj=false)
	{
		
		if (is_object($t)) {
			$fieldobj = $t;
			$t = $fieldobj->type;
			$len = $fieldobj->max_length;
		}
		$is_serial = is_object($fieldobj) && $fieldobj->primary_key && $fieldobj->auto_increment;

		$len = -1; // mysql max_length is not accurate
		switch (strtoupper($t)) {
		case 'STRING':
		case 'CHAR':
		case 'VARCHAR':
		case 'TINYBLOB':
		case 'TINYTEXT':
		case 'ENUM':
		case 'SET':
			if ($len <= $this->blobSize) return 'C';

		case 'TEXT':
		case 'LONGTEXT':
		case 'MEDIUMTEXT':
			return 'X';

		// php_mysql extension always returns 'blob' even if 'text'
		// so we have to check whether binary...
		case 'IMAGE':
		case 'LONGBLOB':
		case 'BLOB':
		case 'MEDIUMBLOB':
			return !empty($fieldobj->binary) ? 'B' : 'X';

		case 'YEAR':
		case 'DATE': return 'D';

		case 'TIME':
		case 'DATETIME':
		case 'TIMESTAMP': return 'T';

		case 'FLOAT':
		case 'DOUBLE':
			return 'F';

		case 'INT':
		case 'INTEGER': return $is_serial ? 'R' : 'I';
		case 'TINYINT': return $is_serial ? 'R' : 'I1';
		case 'SMALLINT': return $is_serial ? 'R' : 'I2';
		case 'MEDIUMINT': return $is_serial ? 'R' : 'I4';
		case 'BIGINT':  return $is_serial ? 'R' : 'I8';
		default: 
			
			return ADODB_DEFAULT_METATYPE;
		}
	}*/

	public function ActualType($meta)
	{
		switch(strtoupper($meta)) {
		case 'C': return 'VARCHAR';
		case 'XL':return 'LONGTEXT';
		case 'X': return 'TEXT';

		case 'C2': return 'VARCHAR';
		case 'X2': return 'LONGTEXT';

		case 'B': return 'LONGBLOB';

		case 'D': return 'DATE';
		case 'TS':
		case 'T': return 'DATETIME';
		case 'L': return 'TINYINT';

		case 'R':
		case 'I4':
		case 'I': return 'INTEGER';
		case 'I1': return 'TINYINT';
		case 'I2': return 'SMALLINT';
		case 'I8': return 'BIGINT';

		case 'F': return 'DOUBLE';
		case 'N': return 'NUMERIC';
			
		default:
			
			return $meta;
		}
	}

	// return string must begin with space
	protected function _CreateSuffix($fname,&$ftype,$fnotnull,$fdefault,$fautoinc,$fconstraint,$funsigned)
	{
		$suffix = '';
		if ($funsigned) $suffix .= ' UNSIGNED';
		if ($fnotnull) $suffix .= ' NOT NULL';
		if (strlen($fdefault)) $suffix .= " DEFAULT $fdefault";
		if ($fautoinc) $suffix .= ' AUTO_INCREMENT';
		if ($fconstraint) $suffix .= ' '.$fconstraint;
		return $suffix;
	}

	/*
	CREATE [TEMPORARY] TABLE [IF NOT EXISTS] tbl_name [(create_definition,...)]
		[table_options] [select_statement]
		create_definition:
		col_name type [NOT NULL | NULL] [DEFAULT default_value] [AUTO_INCREMENT]
		[PRIMARY KEY] [reference_definition]
		or PRIMARY KEY (index_col_name,...)
		or KEY [index_name] (index_col_name,...)
		or INDEX [index_name] (index_col_name,...)
		or UNIQUE [INDEX] [index_name] (index_col_name,...)
		or FULLTEXT [INDEX] [index_name] (index_col_name,...)
		or [CONSTRAINT symbol] FOREIGN KEY [index_name] (index_col_name,...)
		[reference_definition]
		or CHECK (expr)
	*/

	/*
	CREATE [UNIQUE|FULLTEXT] INDEX index_name
		ON tbl_name (col_name[(length)],... )
	*/

	protected function _IndexSQL($idxname, $tabname, $flds, $idxoptions)
	{
		$sql = array();

		if ( isset($idxoptions['REPLACE']) || isset($idxoptions['DROP']) ) {
			if ($this->alterTableAddIndex) $sql[] = "ALTER TABLE $tabname DROP INDEX $idxname";
			else $sql[] = sprintf($this->dropIndex, $idxname, $tabname);

			if ( isset($idxoptions['DROP']) )
				return $sql;
		}

		if ( empty ($flds) ) {
			return $sql;
		}

		if (isset($idxoptions['FULLTEXT'])) {
			$unique = ' FULLTEXT';
		} elseif (isset($idxoptions['UNIQUE'])) {
			$unique = ' UNIQUE';
		} else {
			$unique = '';
		}

		if ( is_array($flds) ) $flds = implode(', ',$flds);

		if ($this->alterTableAddIndex) $s = "ALTER TABLE $tabname ADD $unique INDEX $idxname ";
		else $s = 'CREATE' . $unique . ' INDEX ' . $idxname . ' ON ' . $tabname;

		$s .= ' (' . $flds . ')';

		if ( isset($idxoptions[$this->upperName]) )
			$s .= $idxoptions[$this->upperName];

		$sql[] = $s;

		return $sql;
	}

	protected function _FormatDateSQL($fmt, $pParsedColumnName=false)
	{
		$col = false;

		if($pParsedColumnName)
			{$col = $pParsedColumnName['name'];}

		if($this->databaseType !== "mysqli")
		{
			if (!$col) {
				$col = $this->sql_sysTimeStamp;
			}
			$s = 'DATE_FORMAT(' . $col . ",'";
			$concat = false;
			$len = strlen($fmt);
			for ($i=0; $i < $len; $i++) {
				$ch = $fmt[$i];
				switch($ch) {

					default:
						if ($ch == '\\') {
							$i++;
							$ch = substr($fmt, $i, 1);
						}
						// FALL THROUGH
					case '-':
					case '/':
						$s .= $ch;
						break;

					case 'Y':
					case 'y':
						$s .= '%Y';
						break;

					case 'M':
						$s .= '%b';
						break;

					case 'm':
						$s .= '%m';
						break;

					case 'D':
					case 'd':
						$s .= '%d';
						break;

					case 'Q':
					case 'q':
						$s .= "'),Quarter($col)";

						if ($len > $i+1) {
							$s .= ",DATE_FORMAT($col,'";
						} else {
							$s .= ",('";
						}
						$concat = true;
						break;

					case 'H':
						$s .= '%H';
						break;

					case 'h':
						$s .= '%I';
						break;

					case 'i':
						$s .= '%i';
						break;

					case 's':
						$s .= '%s';
						break;

					case 'a':
					case 'A':
						$s .= '%p';
						break;

					case 'w':
						$s .= '%w';
						break;

					case 'W':
						$s .= '%U';
						break;

					case 'l':
						$s .= '%W';
						break;
				}
			}
			$s .= "')";
			if ($concat) {
				$s = "CONCAT($s)";
			}
			return array($s);
		}
		else
		{
			if (!$col) $col = $this->sql_sysTimeStamp;
			$s = 'DATE_FORMAT('.$col.",'";
			$concat = false;
			$len = strlen($fmt);
			for ($i=0; $i < $len; $i++) {
				$ch = $fmt[$i];
				switch($ch) {
				case 'Y':
				case 'y':
					$s .= '%Y';
					break;
				case 'Q':
				case 'q':
					$s .= "'),Quarter($col)";

					if ($len > $i+1) $s .= ",DATE_FORMAT($col,'";
					else $s .= ",('";
					$concat = true;
					break;
				case 'M':
					$s .= '%b';
					break;

				case 'm':
					$s .= '%m';
					break;
				case 'D':
				case 'd':
					$s .= '%d';
					break;

				case 'H':
					$s .= '%H';
					break;

				case 'h':
					$s .= '%I';
					break;

				case 'i':
					$s .= '%i';
					break;

				case 's':
					$s .= '%s';
					break;

				case 'a':
				case 'A':
					$s .= '%p';
					break;

				case 'w':
					$s .= '%w';
					break;

				case 'l':
					$s .= '%W';
					break;

				default:

					if ($ch == '\\') {
						$i++;
						$ch = substr($fmt,$i,1);
					}
					$s .= $ch;
					break;
				}
			}
			$s.="')";
			if ($concat) $s = "CONCAT($s)";
			return array($s);
		}
	}

	public function NormaliseIdentifierName($pIdentifierName)
		{return strtolower($pIdentifierName);}

	public function RowLockSQL($tables,$where='',$col='1 as adodbignore')
	{
		if ($where) $where = ' where '.$where;

		return array("select $col from $tables $where for update");
	}

	protected function _CreateSequenceSQL($pParsedSequenceName, $pStartID = 1)
	{
		return array
		(
			sprintf("create table if not exists %s (id int not null)", 
					$pParsedSequenceName['name']),
			sprintf("insert into %s values (%s)", $pParsedSequenceName['name'], $pStartID - 1)
		);
	}
	
	protected function _DropSequenceSQL($pParsedSequenceName)
		{return array(sprintf("drop table if exists %s", $pParsedSequenceName['name']));}

	// See http://www.mysql.com/doc/M/i/Miscellaneous_functions.html
	// Reference on Last_Insert_ID on the recommended way to simulate sequences
	protected function _GenIDSQL($pParsedSequenceName)
	{
		return array(sprintf("update %s set id=LAST_INSERT_ID(id+1);",
				$pParsedSequenceName['name']));
	}

	protected function _event_GenID_calculateAndSetGenID($pParsedSequenceName, $pADORecordSet)
	{
		if($pADORecordSet)
		{
			if(($this->databaseType === 'mysql') || ($this->databaseType === 'mysqli'))
			{
				$tInsertID = $this->connection->Insert_ID();
				
				if($tInsertID > 0)
					{$this->connection->genID = $tInsertID;}
			}
			else
			{
				$tADORecordSet = $this->connection->Execute("SELECT LAST_INSERT_ID();");
			
				if($tADORecordSet && !$tADORecordSet->EOF)
					{$this->connection->genID = (integer) reset($tADORecordSet->fields);}
			}
		}
	}
	
}
