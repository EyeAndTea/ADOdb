<?php
/*
 @version   v5.21dev  ??-???-2015
 @copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
 @copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.
  Set tabs to 8.

  Original version derived from Alberto Cerezal (acerezalp@dbnet.es) - DBNet Informatica & Comunicaciones.
  08 Nov 2000 jlim - Minor corrections, removing mysql stuff
  09 Nov 2000 jlim - added insertid support suggested by "Christopher Kings-Lynne" <chriskl@familyhealth.com.au>
			  jlim - changed concat operator to || and data types to MetaType to match documented pgsql types
					 see http://www.postgresql.org/devel-corner/docs/postgres/datatype.htm
  22 Nov 2000 jlim - added changes to FetchField() and MetaTables() contributed by "raser" <raser@mail.zen.com.tw>
  27 Nov 2000 jlim - added changes to _connect/_pconnect from ideas by "Lennie" <leen@wirehub.nl>
  15 Dec 2000 jlim - added changes suggested by Additional code changes by "Eric G. Werk" egw@netguide.dk.
  31 Jan 2002 jlim - finally installed postgresql. testing
  01 Mar 2001 jlim - Freek Dijkstra changes, also support for text type

  See http://www.varlena.com/varlena/GeneralBits/47.php

	-- What indexes are on my table?
	select * from pg_indexes where tablename = 'tablename';

	-- What triggers are on my table?
	select c.relname as "Table", t.tgname as "Trigger Name",
	   t.tgconstrname as "Constraint Name", t.tgenabled as "Enabled",
	   t.tgisconstraint as "Is Constraint", cc.relname as "Referenced Table",
	   p.proname as "Function Name"
	from pg_trigger t, pg_class c, pg_class cc, pg_proc p
	where t.tgfoid = p.oid and t.tgrelid = c.oid
	   and t.tgconstrrelid = cc.oid
	   and c.relname = 'tablename';

	-- What constraints are on my table?
	select r.relname as "Table", c.conname as "Constraint Name",
	   contype as "Constraint Type", conkey as "Key Columns",
	   confkey as "Foreign Columns", consrc as "Source"
	from pg_class r, pg_constraint c
	where r.oid = c.conrelid
	   and relname = 'tablename';

*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

function adodb_addslashes($s)
{
	$len = strlen($s);
	if ($len == 0) return "''";
	if (strncmp($s,"'",1) === 0 && substr($s,$len-1) == "'") return $s; // already quoted

	return "'".addslashes($s)."'";
}

class ADODB_postgres64 extends ADOConnection{
	public  $databaseType = 'postgres64';
	public  $dataProvider = 'postgres';
	public  $hasInsertID = true;
	protected  $_resultid = false;
	public  $metaDatabasesSQL = "select datname from pg_database where datname not in ('template0','template1') order by 1";
	public  $metaTablesSQL = "select tablename,'T' from pg_tables where tablename not like 'pg\_%'
		and tablename not in ('sql_features', 'sql_implementation_info', 'sql_languages',
			'sql_packages', 'sql_sizing', 'sql_sizing_profiles')
	union
		select viewname,'V' from pg_views where viewname not like 'pg\_%'";
	//"select tablename from pg_tables where tablename not like 'pg_%' order by 1";
	public  $isoDates = true; // accepts dates in ISO format
	public  $blobEncodeType = 'C';
	public  $metaColumnsSQL = "SELECT a.attname,t.typname,a.attlen,a.atttypmod,a.attnotnull,a.atthasdef,a.attnum
		FROM pg_class c, pg_attribute a,pg_type t
		WHERE relkind in ('r','v') AND (c.relname='%s' or c.relname = lower('%s')) and a.attname not like '....%%'
		AND a.attnum > 0 AND a.atttypid = t.oid AND a.attrelid = c.oid ORDER BY a.attnum";

	// used when schema defined
	public  $metaColumnsSQL1 = "SELECT a.attname, t.typname, a.attlen, a.atttypmod, a.attnotnull, a.atthasdef, a.attnum
		FROM pg_class c, pg_attribute a, pg_type t, pg_namespace n
		WHERE relkind in ('r','v') AND (c.relname='%s' or c.relname = lower('%s'))
		and c.relnamespace=n.oid and n.nspname='%s'
		and a.attname not like '....%%' AND a.attnum > 0
		AND a.atttypid = t.oid AND a.attrelid = c.oid ORDER BY a.attnum";

	// get primary key etc -- from Freek Dijkstra
	public  $metaKeySQL = "SELECT ic.relname AS index_name, a.attname AS column_name,i.indisunique AS unique_key, i.indisprimary AS primary_key
		FROM pg_class bc, pg_class ic, pg_index i, pg_attribute a
		WHERE bc.oid = i.indrelid AND ic.oid = i.indexrelid
		AND (i.indkey[0] = a.attnum OR i.indkey[1] = a.attnum OR i.indkey[2] = a.attnum OR i.indkey[3] = a.attnum OR i.indkey[4] = a.attnum OR i.indkey[5] = a.attnum OR i.indkey[6] = a.attnum OR i.indkey[7] = a.attnum)
		AND a.attrelid = bc.oid AND bc.relname = '%s'";

	public  $hasAffectedRows = true;
	public  $hasLimit = false;	// set to true for pgsql 7 only. support pgsql/mysql SELECT * FROM TABLE LIMIT 10
	// below suggested by Freek Dijkstra
	public  $true = 'TRUE';		// string that represents TRUE for a database
	public  $false = 'FALSE';		// string that represents FALSE for a database
	public  $fmtDate = "'Y-m-d'";	// used by DBDate() as the default date format used by the database
	public  $fmtTimeStamp = "'Y-m-d H:i:s'"; // used by DBTimeStamp as the default timestamp fmt.
	public  $hasMoveFirst = true;
	public  $hasGenID = true;
	public  $metaDefaultsSQL = "SELECT d.adnum as num, d.adsrc as def from pg_attrdef d, pg_class c where d.adrelid=c.oid and c.relname='%s' order by d.adnum";
	public  $random = 'random()';		/// random function
	public  $autoRollback = true; // apparently pgsql does not autorollback properly before php 4.3.4
							// http://bugs.php.net/bug.php?id=25404

	public  $uniqueIisR = true;
	protected  $_bindInputArray = false; // requires postgresql 7.3+ and ability to modify database
	public  $disableBlobs = false; // set to true to disable blob checking, resulting in 2-5% improvement in performance.

	protected  $_pnum = 0;

	// The last (fmtTimeStamp is not entirely correct:
	// PostgreSQL also has support for time zones,
	// and writes these time in this format: "2001-03-01 18:59:26+02".
	// There is no code for the "+02" time zone information, so I just left that out.
	// I'm not familiar enough with both ADODB as well as Postgres
	// to know what the concequences are. The other values are correct (wheren't in 0.94)
	// -- Freek Dijkstra

	public function ServerInfo()
	{
		if (isset($this->version)) return $this->version;

		$arr['description'] = $this->GetOne("select version()");
		$arr['version'] = ADOConnection::_findvers($arr['description']);
		$this->version = $arr;
		return $arr;
	}

	public function IfNull( $field, $ifNull )
	{
		return " coalesce($field, $ifNull) ";
	}

	// get the last id - never tested
	public function pg_insert_id($tablename,$fieldname)
	{
		$result=pg_query($this->_connectionID, 'SELECT last_value FROM '. $tablename .'_'. $fieldname .'_seq');
		if ($result) {
			$arr = @pg_fetch_row($result,0);
			pg_free_result($result);
			if (isset($arr[0])) return $arr[0];
		}
		return false;
	}

	/**
	 * Warning from http://www.php.net/manual/function.pg-getlastoid.php:
	 * Using a OID as a unique identifier is not generally wise.
	 * Unless you are very careful, you might end up with a tuple having
	 * a different OID if a database must be reloaded.
	 */
	protected function _insertid($table,$column)
	{
		if (!is_resource($this->_resultid) || get_resource_type($this->_resultid) !== 'pgsql result') return false;
		$oid = pg_getlastoid($this->_resultid);
		// to really return the id, we need the table and column-name, else we can only return the oid != id
		return empty($table) || empty($column) ? $oid : $this->GetOne("SELECT $column FROM $table WHERE oid=".(int)$oid);
	}

	protected function _affectedrows()
	{
		if (!is_resource($this->_resultid) || get_resource_type($this->_resultid) !== 'pgsql result') return false;
		return pg_affected_rows($this->_resultid);
	}


	/**
	 * @return true/false
	 */
	public function BeginTrans()
	{
		if ($this->transOff) return true;
		$this->transCnt += 1;
		return pg_query($this->_connectionID, 'begin '.$this->_transmode);
	}

	// returns true/false.
	public function CommitTrans($ok=true)
	{
		if ($this->transOff) return true;
		if (!$ok) return $this->RollbackTrans();

		$this->transCnt -= 1;
		return pg_query($this->_connectionID, 'commit');
	}

	// returns true/false
	public function RollbackTrans()
	{
		if ($this->transOff) return true;
		$this->transCnt -= 1;
		return pg_query($this->_connectionID, 'rollback');
	}

	public function MetaTables($ttype=false,$showSchema=false,$mask=false)
	{
		$info = $this->ServerInfo();
		if ($info['version'] >= 7.3) {
		$this->metaTablesSQL = "
			select table_name,'T' from information_schema.tables where table_schema not in ( 'pg_catalog','information_schema')
			union
			select table_name,'V' from information_schema.views where table_schema not in ( 'pg_catalog','information_schema') ";
		}
		if ($mask) {
			$save = $this->metaTablesSQL;
			$mask = $this->qstr(strtolower($mask));
			if ($info['version']>=7.3)
				$this->metaTablesSQL = "
					select table_name,'T' from information_schema.tables where table_name like $mask and table_schema not in ( 'pg_catalog','information_schema')
					union
					select table_name,'V' from information_schema.views where table_name like $mask and table_schema not in ( 'pg_catalog','information_schema') ";
			else
				$this->metaTablesSQL = "
					select tablename,'T' from pg_tables where tablename like $mask
					union
					select viewname,'V' from pg_views where viewname like $mask";
		}
		$ret = ADOConnection::MetaTables($ttype,$showSchema);

		if ($mask) {
			$this->metaTablesSQL = $save;
		}
		return $ret;
	}


	// if magic quotes disabled, use pg_escape_string()
	public function qstr($s,$magic_quotes=false)
	{
		if (is_bool($s)) return $s ? 'true' : 'false';

		if (!$magic_quotes) {
			if (ADODB_PHPVER >= 0x5200 && $this->_connectionID) {
				return  "'".pg_escape_string($this->_connectionID,$s)."'";
			}
			return  "'".pg_escape_string($s)."'";
		}

		// undo magic quotes for "
		$s = str_replace('\\"','"',$s);
		return "'$s'";
	}

	/*
	* Load a Large Object from a file
	* - the procedure stores the object id in the table and imports the object using
	* postgres proprietary blob handling routines
	*
	* contributed by Mattia Rossi mattia@technologist.com
	* modified for safe mode by juraj chlebec
	*/
	public function UpdateBlobFile($table,$column,$path,$where,$blobtype='BLOB')
	{
		pg_query($this->_connectionID, 'begin');

		$fd = fopen($path,'r');
		$contents = fread($fd,filesize($path));
		fclose($fd);

		$oid = pg_lo_create($this->_connectionID);
		$handle = pg_lo_open($this->_connectionID, $oid, 'w');
		pg_lo_write($handle, $contents);
		pg_lo_close($handle);

		// $oid = pg_lo_import ($path);
		pg_query($this->_connectionID, 'commit');
		$rs = ADOConnection::UpdateBlob($table,$column,$oid,$where,$blobtype);
		$rez = !empty($rs);
		return $rez;
	}

	/*
	* Deletes/Unlinks a Blob from the database, otherwise it
	* will be left behind
	*
	* Returns TRUE on success or FALSE on failure.
	*
	* contributed by Todd Rogers todd#windfox.net
	*/
	public function BlobDelete( $blob )
	{
		pg_query($this->_connectionID, 'begin');
		$result = @pg_lo_unlink($blob);
		pg_query($this->_connectionID, 'commit');
		return( $result );
	}

	/*
		Hueristic - not guaranteed to work.
	*/
	public function GuessOID($oid)
	{
		if (strlen($oid)>16) return false;
		return is_numeric($oid);
	}

	/*
	* If an OID is detected, then we use pg_lo_* to open the oid file and read the
	* real blob from the db using the oid supplied as a parameter. If you are storing
	* blobs using bytea, we autodetect and process it so this function is not needed.
	*
	* contributed by Mattia Rossi mattia@technologist.com
	*
	* see http://www.postgresql.org/idocs/index.php?largeobjects.html
	*
	* Since adodb 4.54, this returns the blob, instead of sending it to stdout. Also
	* added maxsize parameter, which defaults to $db->maxblobsize if not defined.
	*/
	public function BlobDecode($blob,$maxsize=false,$hastrans=true)
	{
		if (!$this->GuessOID($blob)) return $blob;

		if ($hastrans) pg_query($this->_connectionID,'begin');
		$fd = @pg_lo_open($this->_connectionID,$blob,'r');
		if ($fd === false) {
			if ($hastrans) pg_query($this->_connectionID,'commit');
			return $blob;
		}
		if (!$maxsize) $maxsize = $this->maxblobsize;
		$realblob = @pg_lo_read($fd,$maxsize);
		@pg_loclose($fd);
		if ($hastrans) pg_query($this->_connectionID,'commit');
		return $realblob;
	}

	/*
		See http://www.postgresql.org/idocs/index.php?datatype-binary.html

		NOTE: SQL string literals (input strings) must be preceded with two backslashes
		due to the fact that they must pass through two parsers in the PostgreSQL
		backend.
	*/
	public function BlobEncode($blob)
	{
		if (ADODB_PHPVER >= 0x5200) return pg_escape_bytea($this->_connectionID, $blob);
		return pg_escape_bytea($blob);
		// note that there is a pg_escape_bytea function only for php 4.2.0 or later
	}

	// assumes bytea for blob, and varchar for clob
	public function UpdateBlob($table,$column,$val,$where,$blobtype='BLOB')
	{
		if ($blobtype == 'CLOB') {
			return $this->Execute("UPDATE $table SET $column=" . $this->qstr($val) . " WHERE $where");
		}
		// do not use bind params which uses qstr(), as blobencode() already quotes data
		return $this->Execute("UPDATE $table SET $column='".$this->BlobEncode($val)."'::bytea WHERE $where");
	}

	public function OffsetDate($dayFraction,$date=false)
	{
		if (!$date) $date = $this->sysDate;
		else if (strncmp($date,"'",1) == 0) {
			$len = strlen($date);
			if (10 <= $len && $len <= 12) $date = 'date '.$date;
			else $date = 'timestamp '.$date;
		}


		return "($date+interval'".($dayFraction * 1440)." minutes')";
		#return "($date+interval'$dayFraction days')";
	}

	/**
	 * Generate the SQL to retrieve MetaColumns data
	 * @param string $table Table name
	 * @param string $schema Schema name (can be blank)
	 * @return string SQL statement to execute
	 */
	protected function _generateMetaColumnsSQL($table, $schema)
	{
		if ($schema) {
			return sprintf($this->metaColumnsSQL1, $table, $table, $schema);
		}
		else {
			return sprintf($this->metaColumnsSQL, $table, $table, $schema);
		}
	}

	// for schema support, pass in the $table param "$schema.$tabname".
	// converts field names to lowercase, $upper is ignored
	// see http://phplens.com/lens/lensforum/msgs.php?id=14018 for more info
	protected function _MetaColumns($pParsedTableName)
	{
		global $ADODB_FETCH_MODE;

		$false = false;
		$table = $pParsedTableName['table']['name'];
		$normalize = $pParsedTableName['table']['isToNormalize'];
		$schema = (array_key_exists('schema', $pParsedTableName) ? 
				$pParsedTableName['schema']['name'] : false);

		if ($normalize) $table = strtolower($table);

		$save = $ADODB_FETCH_MODE;
		$ADODB_FETCH_MODE = ADODB_FETCH_NUM;
		if ($this->fetchMode !== false) $savem = $this->SetFetchMode(false);

		$rs = $this->Execute($this->_generateMetaColumnsSQL($table, $schema));
		if (isset($savem)) $this->SetFetchMode($savem);
		$ADODB_FETCH_MODE = $save;

		if ($rs === false) {
			return $false;
		}
		if (!empty($this->metaKeySQL)) {
			// If we want the primary keys, we have to issue a separate query
			// Of course, a modified version of the metaColumnsSQL query using a
			// LEFT JOIN would have been much more elegant, but postgres does
			// not support OUTER JOINS. So here is the clumsy way.

			$ADODB_FETCH_MODE = ADODB_FETCH_ASSOC;

			$rskey = $this->Execute(sprintf($this->metaKeySQL,($table)));
			// fetch all result in once for performance.
			$keys = $rskey->GetArray();
			if (isset($savem)) $this->SetFetchMode($savem);
			$ADODB_FETCH_MODE = $save;

			$rskey->Close();
			unset($rskey);
		}

		$rsdefa = array();
		if (!empty($this->metaDefaultsSQL)) {
			$ADODB_FETCH_MODE = ADODB_FETCH_ASSOC;
			$sql = sprintf($this->metaDefaultsSQL, ($table));
			$rsdef = $this->Execute($sql);
			if (isset($savem)) $this->SetFetchMode($savem);
			$ADODB_FETCH_MODE = $save;

			if ($rsdef) {
				while (!$rsdef->EOF) {
					$num = $rsdef->fields['num'];
					$s = $rsdef->fields['def'];
					if (strpos($s,'::')===false && substr($s, 0, 1) == "'") { /* quoted strings hack... for now... fixme */
						$s = substr($s, 1);
						$s = substr($s, 0, strlen($s) - 1);
					}

					$rsdefa[$num] = $s;
					$rsdef->MoveNext();
				}
			} else {
				ADOConnection::outp( "==> SQL => " . $sql);
			}
			unset($rsdef);
		}

		$retarr = array();
		while (!$rs->EOF) {
			$fld = new ADOFieldObject();
			$fld->name = $rs->fields[0];
			$fld->type = $rs->fields[1];
			$fld->max_length = $rs->fields[2];
			$fld->attnum = $rs->fields[6];

			if ($fld->max_length <= 0) $fld->max_length = $rs->fields[3]-4;
			if ($fld->max_length <= 0) $fld->max_length = -1;
			if ($fld->type == 'numeric') {
				$fld->scale = $fld->max_length & 0xFFFF;
				$fld->max_length >>= 16;
			}
			// dannym
			// 5 hasdefault; 6 num-of-column
			$fld->has_default = ($rs->fields[5] == 't');
			if ($fld->has_default) {
				$fld->default_value = $rsdefa[$rs->fields[6]];
			}

			//Freek
			$fld->not_null = $rs->fields[4] == 't';


			// Freek
			if (is_array($keys)) {
				foreach($keys as $key) {
					if ($fld->name == $key['column_name'] AND $key['primary_key'] == 't')
						$fld->primary_key = true;
					if ($fld->name == $key['column_name'] AND $key['unique_key'] == 't')
						$fld->unique = true; // What name is more compatible?
				}
			}

			if ($ADODB_FETCH_MODE == ADODB_FETCH_NUM) $retarr[] = $fld;
			else $retarr[strtoupper($fld->name)] = $fld;

			$rs->MoveNext();
		}
		$rs->Close();
		if (empty($retarr))
			return  $false;
		else
			return $retarr;

	}

	public function Param($name,$type='C')
	{
		if ($name) {
			$this->_pnum += 1;
		} else {
			// Reset param num if $name is false
			$this->_pnum = 1;
		}
		return '$'.$this->_pnum;
	}

	protected function _MetaIndexes ($pParsedTableName, $primary = FALSE, $owner = false)
	{
		global $ADODB_FETCH_MODE;

		$table = $pParsedTableName['table']['name'];
		$schema = @$pParsedTableName['schema']['name'];

		if ($schema) { // requires pgsql 7.3+ - pg_namespace used.
			$sql = '
				SELECT c.relname as "Name", i.indisunique as "Unique", i.indkey as "Columns"
				FROM pg_catalog.pg_class c
				JOIN pg_catalog.pg_index i ON i.indexrelid=c.oid
				JOIN pg_catalog.pg_class c2 ON c2.oid=i.indrelid
					,pg_namespace n
				WHERE (c2.relname=\'%s\' or c2.relname=lower(\'%s\'))
				and c.relnamespace=c2.relnamespace
				and c.relnamespace=n.oid
				and n.nspname=\'%s\'';
		} else {
			$sql = '
				SELECT c.relname as "Name", i.indisunique as "Unique", i.indkey as "Columns"
				FROM pg_catalog.pg_class c
				JOIN pg_catalog.pg_index i ON i.indexrelid=c.oid
				JOIN pg_catalog.pg_class c2 ON c2.oid=i.indrelid
				WHERE (c2.relname=\'%s\' or c2.relname=lower(\'%s\'))';
		}

		if ($primary == FALSE) {
			$sql .= ' AND i.indisprimary=false;';
		}

		$save = $ADODB_FETCH_MODE;
		$ADODB_FETCH_MODE = ADODB_FETCH_NUM;
		if ($this->fetchMode !== FALSE) {
			$savem = $this->SetFetchMode(FALSE);
		}

		$rs = $this->Execute(sprintf($sql,$table,$table,$schema));
		if (isset($savem)) {
			$this->SetFetchMode($savem);
		}
		$ADODB_FETCH_MODE = $save;

		if (!is_object($rs)) {
			$false = false;
			return $false;
		}

		$col_names = $this->_MetaColumnNames($pParsedTableName,true,true);
		//3rd param is use attnum,
		// see http://sourceforge.net/tracker/index.php?func=detail&aid=1451245&group_id=42718&atid=433976
		$indexes = array();
		while ($row = $rs->FetchRow()) {
			$columns = array();
			foreach (explode(' ', $row[2]) as $col) {
				$columns[] = $col_names[$col];
			}

			$indexes[$row[0]] = array(
				'unique' => ($row[1] == 't'),
				'columns' => $columns
			);
		}
		return $indexes;
	}

	// returns true or false
	//
	// examples:
	// 	$db->Connect("host=host1 user=user1 password=secret port=4341");
	// 	$db->Connect('host1','user1','secret');
	protected function _connect($str,$user='',$pwd='',$db='',$ctype=0)
	{
		if (!function_exists('pg_connect')) return null;

		$this->_errorMsg = false;

		if ($user || $pwd || $db) {
			$user = adodb_addslashes($user);
			$pwd = adodb_addslashes($pwd);
			if (strlen($db) == 0) $db = 'template1';
			$db = adodb_addslashes($db);
			if ($str)  {
				$host = explode(":", $str);
				if ($host[0]) $str = "host=".adodb_addslashes($host[0]);
				else $str = '';
				if (isset($host[1])) $str .= " port=$host[1]";
				else if (!empty($this->port)) $str .= " port=".$this->port;
			}
			if ($user) $str .= " user=".$user;
			if ($pwd)  $str .= " password=".$pwd;
			if ($db)   $str .= " dbname=".$db;
		}

		//if ($user) $linea = "user=$user host=$linea password=$pwd dbname=$db port=5432";

		if ($ctype === 1) { // persistent
			$this->_connectionID = pg_pconnect($str);
		} else {
			if ($ctype === -1) { // nconnect, we trick pgsql ext by changing the connection str
				static $ncnt;

				if (empty($ncnt)) $ncnt = 1;
				else $ncnt += 1;

				$str .= str_repeat(' ',$ncnt);
			}
			$this->_connectionID = pg_connect($str);
		}
		if ($this->_connectionID === false) return false;
		$this->Execute("set datestyle='ISO'");

		$info = $this->ServerInfo();
		$this->pgVersion = (float) substr($info['version'],0,3);
		if ($this->pgVersion >= 7.1) { // good till version 999
			$this->_nestedSQL = true;
		}

		# PostgreSQL 9.0 changed the default output for bytea from 'escape' to 'hex'
		# PHP does not handle 'hex' properly ('x74657374' is returned as 't657374')
		# https://bugs.php.net/bug.php?id=59831 states this is in fact not a bug,
		# so we manually set bytea_output
		if ( !empty($this->connection->noBlobs) && version_compare($info['version'], '9.0', '>=')) {
			$this->Execute('set bytea_output=escape');
		}

		return true;
	}

	protected function _nconnect($argHostname, $argUsername, $argPassword, $argDatabaseName)
	{
		return $this->_connect($argHostname, $argUsername, $argPassword, $argDatabaseName,-1);
	}

	// returns true or false
	//
	// examples:
	// 	$db->PConnect("host=host1 user=user1 password=secret port=4341");
	// 	$db->PConnect('host1','user1','secret');
	protected function _pconnect($str,$user='',$pwd='',$db='')
	{
		return $this->_connect($str,$user,$pwd,$db,1);
	}


	// returns queryID or false
	public function _query($sql,$inputarr=false)
	{
		$this->_pnum = 0;
		$this->_errorMsg = false;
		if ($inputarr) {
		/*
			It appears that PREPARE/EXECUTE is slower for many queries.

			For query executed 1000 times:
			"select id,firstname,lastname from adoxyz
				where firstname not like ? and lastname not like ? and id = ?"

			with plan = 1.51861286163 secs
			no plan =   1.26903700829 secs
		*/
			$plan = 'P'.md5($sql);

			$execp = '';
			foreach($inputarr as $v) {
				if ($execp) $execp .= ',';
				if (is_string($v)) {
					if (strncmp($v,"'",1) !== 0) $execp .= $this->qstr($v);
				} else {
					$execp .= $v;
				}
			}

			if ($execp) $exsql = "EXECUTE $plan ($execp)";
			else $exsql = "EXECUTE $plan";


			$rez = @pg_execute($this->_connectionID,$exsql);
			if (!$rez) {
			# Perhaps plan does not exist? Prepare/compile plan.
				$params = '';
				foreach($inputarr as $v) {
					if ($params) $params .= ',';
					if (is_string($v)) {
						$params .= 'VARCHAR';
					} else if (is_integer($v)) {
						$params .= 'INTEGER';
					} else {
						$params .= "REAL";
					}
				}
				$sqlarr = explode('?',$sql);
				//print_r($sqlarr);
				$sql = '';
				$i = 1;
				foreach($sqlarr as $v) {
					$sql .= $v.' $'.$i;
					$i++;
				}
				$s = "PREPARE $plan ($params) AS ".substr($sql,0,strlen($sql)-2);
				//adodb_pr($s);
				$rez = pg_execute($this->_connectionID,$s);
				//echo $this->ErrorMsg();
			}
			if ($rez)
				$rez = pg_execute($this->_connectionID,$exsql);
		} else {
			//adodb_backtrace();
			$rez = pg_query($this->_connectionID,$sql);
		}
		// check if no data returned, then no need to create real recordset
		if ($rez && pg_num_fields($rez) <= 0) {
			if (is_resource($this->_resultid) && get_resource_type($this->_resultid) === 'pgsql result') {
				pg_free_result($this->_resultid);
			}
			$this->_resultid = $rez;
			return true;
		}

		return $rez;
	}

	protected function _errconnect()
	{
		if (defined('DB_ERROR_CONNECT_FAILED')) return DB_ERROR_CONNECT_FAILED;
		else return 'Database connection failed';
	}

	/*	Returns: the last error message from previous database operation	*/
	public function ErrorMsg()
	{
		if ($this->_errorMsg !== false) return $this->_errorMsg;
		if (!empty($this->_resultid)) {
			$this->_errorMsg = @pg_result_error($this->_resultid);
			if ($this->_errorMsg) return $this->_errorMsg;
		}

		if (!empty($this->_connectionID)) {
			$this->_errorMsg = @pg_last_error($this->_connectionID);
		} else $this->_errorMsg = $this->_errconnect();
		return $this->_errorMsg;
	}

	public function ErrorNo()
	{
		$e = $this->ErrorMsg();
		if (strlen($e)) {
			return ADOConnection::MetaError($e);
		}
		return 0;
	}

	// returns true or false
	protected function _close()
	{
		if ($this->transCnt) $this->RollbackTrans();
		if ($this->_resultid) {
			@pg_free_result($this->_resultid);
			$this->_resultid = false;
		}
		@pg_close($this->_connectionID);
		$this->_connectionID = false;
		return true;
	}


	/*
	* Maximum size of C field
	*/
	public function CharMax()
	{
		return 1000000000;  // should be 1 Gb?
	}

	/*
	* Maximum size of X field
	*/
	public function TextMax()
	{
		return 1000000000; // should be 1 Gb?
	}


}

/*--------------------------------------------------------------------------------------
	Class Name: Recordset
--------------------------------------------------------------------------------------*/

class ADORecordSet_postgres64 extends ADORecordSet{
	protected  $_blobArr;
	public  $databaseType = "postgres64";
	public  $canSeek = true;

	public function __construct($queryID, $mode=false)
	{
		if ($mode === false) {
			global $ADODB_FETCH_MODE;
			$mode = $ADODB_FETCH_MODE;
		}
		switch ($mode)
		{
		case ADODB_FETCH_NUM: $this->fetchMode = PGSQL_NUM; break;
		case ADODB_FETCH_ASSOC:$this->fetchMode = PGSQL_ASSOC; break;

		case ADODB_FETCH_DEFAULT:
		case ADODB_FETCH_BOTH:
		default: $this->fetchMode = PGSQL_BOTH; break;
		}
		$this->adodbFetchMode = $mode;

		// Parent's constructor
		parent::__construct($queryID);
	}

	public function GetRowAssoc($upper = ADODB_ASSOC_CASE)
	{
		if ($this->fetchMode == PGSQL_ASSOC && $upper == ADODB_ASSOC_CASE_LOWER) {
			return $this->fields;
		}
		$row = ADORecordSet::GetRowAssoc($upper);
		return $row;
	}


	protected function _initrs()
	{
	global $ADODB_COUNTRECS;
		$qid = $this->_queryID;
		$this->_numOfRows = ($ADODB_COUNTRECS)? @pg_num_rows($qid):-1;
		$this->_numOfFields = @pg_num_fields($qid);

		// cache types for blob decode check
		// apparently pg_field_type actually performs an sql query on the database to get the type.
		if (empty($this->connection->noBlobs))
		for ($i=0, $max = $this->_numOfFields; $i < $max; $i++) {
			if (pg_field_type($qid,$i) == 'bytea') {
				$this->_blobArr[$i] = pg_field_name($qid,$i);
			}
		}
	}

		/* Use associative array to get fields array */
	public function Fields($colname)
	{
		if ($this->fetchMode != PGSQL_NUM) return @$this->fields[$colname];

		if (!$this->bind) {
			$this->bind = array();
			for ($i=0; $i < $this->_numOfFields; $i++) {
				$o = $this->FetchField($i);
				$this->bind[strtoupper($o->name)] = $i;
			}
		}
		return $this->fields[$this->bind[strtoupper($colname)]];
	}

	public function FetchField($off = 0)
	{
		// offsets begin at 0

		$o= new ADOFieldObject();
		$o->name = @pg_field_name($this->_queryID,$off);
		$o->type = @pg_field_type($this->_queryID,$off);
		$o->max_length = @pg_fieldsize($this->_queryID,$off);
		return $o;
	}

	protected function _seek($row)
	{
		return @pg_fetch_row($this->_queryID,$row);
	}

	protected function _decode($blob)
	{
		if ($blob === NULL) return NULL;
//		eval('$realblob="'.str_replace(array('"','$'),array('\"','\$'),$blob).'";');
		return pg_unescape_bytea($blob);
	}

	protected function _fixblobs()
	{
		if ($this->fetchMode == PGSQL_NUM || $this->fetchMode == PGSQL_BOTH) {
			foreach($this->_blobArr as $k => $v) {
				$this->fields[$k] = ADORecordSet_postgres64::_decode($this->fields[$k]);
			}
		}
		if ($this->fetchMode == PGSQL_ASSOC || $this->fetchMode == PGSQL_BOTH) {
			foreach($this->_blobArr as $k => $v) {
				$this->fields[$v] = ADORecordSet_postgres64::_decode($this->fields[$v]);
			}
		}
	}

	// 10% speedup to move MoveNext to child class
	public function MoveNext()
	{
		if (!$this->EOF) {
			$this->_currentRow++;
			if ($this->_numOfRows < 0 || $this->_numOfRows > $this->_currentRow) {
				$this->fields = @pg_fetch_array($this->_queryID,$this->_currentRow,$this->fetchMode);
				if (is_array($this->fields) && $this->fields) {
					if (isset($this->_blobArr)) $this->_fixblobs();
					return true;
				}
			}
			$this->fields = false;
			$this->EOF = true;
		}
		return false;
	}

	protected function _fetch()
	{

		if ($this->_currentRow >= $this->_numOfRows && $this->_numOfRows >= 0)
			return false;

		$this->fields = @pg_fetch_array($this->_queryID,$this->_currentRow,$this->fetchMode);

		if ($this->fields && isset($this->_blobArr)) $this->_fixblobs();

		return (is_array($this->fields));
	}

	protected function _close()
	{
		return @pg_free_result($this->_queryID);
	}

	public function MetaType($t,$len=-1,$fieldobj=false)
	{
		if (is_object($t)) {
			$fieldobj = $t;
			$t = $fieldobj->type;
			$len = $fieldobj->max_length;
		}
		switch (strtoupper($t)) {
				case 'MONEY': // stupid, postgres expects money to be a string
				case 'INTERVAL':
				case 'CHAR':
				case 'CHARACTER':
				case 'VARCHAR':
				case 'NAME':
				case 'BPCHAR':
				case '_VARCHAR':
				case 'INET':
				case 'MACADDR':
					if ($len <= $this->blobSize) return 'C';

				case 'TEXT':
					return 'X';

				case 'IMAGE': // user defined type
				case 'BLOB': // user defined type
				case 'BIT':	// This is a bit string, not a single bit, so don't return 'L'
				case 'VARBIT':
				case 'BYTEA':
					return 'B';

				case 'BOOL':
				case 'BOOLEAN':
					return 'L';

				case 'DATE':
					return 'D';


				case 'TIMESTAMP WITHOUT TIME ZONE':
				case 'TIME':
				case 'DATETIME':
				case 'TIMESTAMP':
				case 'TIMESTAMPTZ':
					return 'T';

				case 'SMALLINT':
				case 'BIGINT':
				case 'INTEGER':
				case 'INT8':
				case 'INT4':
				case 'INT2':
					if (isset($fieldobj) &&
				empty($fieldobj->primary_key) && (!$this->connection->uniqueIisR || empty($fieldobj->unique))) return 'I';

				case 'OID':
				case 'SERIAL':
					return 'R';

				default:
					return 'N';
			}
	}

}
