<?php
/*
@version   v5.22.0-dev  Unreleased
@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.
Set tabs to 4 for best viewing.

  Latest version is available at https://adodb.org/

  firebird data driver. Requires firebird client. Works on Windows and Unix.

*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

class ADODB_firebird extends ADOConnection {
	public $databaseType = "firebird";
	public $replaceQuote = "''"; // string to use to replace quotes
	public $fbird_datefmt = '%Y-%m-%d'; // For hours,mins,secs change to '%Y-%m-%d %H:%M:%S';
	public $fmtDate = "'Y-m-d'";
	public $fbird_timestampfmt = "%Y-%m-%d %H:%M:%S";
	public $fbird_timefmt = "%H:%M:%S";
	public $fmtTimeStamp = "'Y-m-d, H:i:s'";
	public $_transactionID;
	public $metaTablesSQL = "select lower(rdb\$relation_name) from rdb\$relations where rdb\$relation_name not like 'RDB\$%'";
	//OPN STUFF start
	public $metaColumnsSQL = "select lower(a.rdb\$field_name), a.rdb\$null_flag, a.rdb\$default_source, b.rdb\$field_length, b.rdb\$field_scale, b.rdb\$field_sub_type, b.rdb\$field_precision, b.rdb\$field_type from rdb\$relation_fields a, rdb\$fields b where a.rdb\$field_source = b.rdb\$field_name and UPPER(a.rdb\$relation_name) = '%s' order by a.rdb\$field_position asc";
	//OPN STUFF end
	public $ibasetrans;
	public $hasGenID = true;
	protected $_bindInputArray = true;
	public $buffers = 0;
	public $dialect = 3;
	public $ansiOuter = true;
	public $hasAffectedRows = true;
	public $poorAffectedRows = false;
	public $blobEncodeType = 'C';
	public $role = false;

	public function __construct()
	{
	// Ignore IBASE_DEFAULT we want a more practical transaction!
	//	if (defined('IBASE_DEFAULT')) $this->ibasetrans = IBASE_DEFAULT;
	//	else
		$this->ibasetrans = IBASE_WAIT | IBASE_REC_VERSION | IBASE_COMMITTED;
	}


	// returns true or false
	protected function _connect($argHostname, $argUsername, $argPassword, $argDatabasename,$persist=false)
	{
		if (!function_exists('fbird_pconnect')) return null;
		if ($argDatabasename) $argHostname .= ':'.$argDatabasename;
		$fn = ($persist) ? 'fbird_pconnect':'fbird_connect';
		if ($this->role)
			$this->_connectionID = $fn($argHostname,$argUsername,$argPassword,
					$this->charSet,$this->buffers,$this->dialect,$this->role);
		else
			$this->_connectionID = $fn($argHostname,$argUsername,$argPassword,
					$this->charSet,$this->buffers,$this->dialect);

		if ($this->dialect != 1) { // http://www.ibphoenix.com/ibp_60_del_id_ds.html
			$this->replaceQuote = "''";
		}
		if ($this->_connectionID === false) {
			$this->_handleerror();
			return false;
		}

		// PHP5 change.
		if (function_exists('fbird_timefmt')) {
			fbird_timefmt($this->fbird_datefmt,fbird_DATE );
			if ($this->dialect == 1) {
				fbird_timefmt($this->fbird_datefmt,fbird_TIMESTAMP );
			} else {
				fbird_timefmt($this->fbird_timestampfmt,fbird_TIMESTAMP );
			}
			fbird_timefmt($this->fbird_timefmt,fbird_TIME );

		} else {
			ini_set("ibase.timestampformat", $this->fbird_timestampfmt);
			ini_set("ibase.dateformat", $this->fbird_datefmt);
			ini_set("ibase.timeformat", $this->fbird_timefmt);
		}
		return true;
	}

	// returns true or false
	protected function _pconnect($argHostname, $argUsername, $argPassword, $argDatabasename)
	{
		return $this->_connect($argHostname, $argUsername, $argPassword, $argDatabasename,true);
	}


	protected function _MetaPrimaryKeys($pParsedTableName,$owner_notused=false,$internalKey=false)
	{
		if ($internalKey) {
			return array('RDB$DB_KEY');
		}
		$table = $pParsedTableName['table']['name'];
		$table = strtoupper($table);

		$sql = 'SELECT S.RDB$FIELD_NAME AFIELDNAME
	FROM RDB$INDICES I JOIN RDB$INDEX_SEGMENTS S ON I.RDB$INDEX_NAME=S.RDB$INDEX_NAME
	WHERE UPPER(I.RDB$RELATION_NAME)=\''.$table.'\' and I.RDB$INDEX_NAME like \'RDB$PRIMARY%\'
	ORDER BY I.RDB$INDEX_NAME,S.RDB$FIELD_POSITION';

		$a = $this->GetCol($sql,false,true);
		if ($a && sizeof($a)>0) return $a;
		return false;
	}


	public function ServerInfo()
	{
		$arr['dialect'] = $this->dialect;
		switch($arr['dialect']) {
		case '':
		case '1': $s = 'Firebird Dialect 1'; break;
		case '2': $s = 'Firebird Dialect 2'; break;
		default:
		case '3': $s = 'Firebird Dialect 3'; break;
		}
		$arr['version'] = ADOConnection::_findvers($s);
		$arr['description'] = $s;
		return $arr;
	}

	public function BeginTrans()
	{
		if ($this->transOff) return true;
		$this->transCnt += 1;
		$this->autoCommit = false;
		$this->_transactionID = fbird_trans( $this->ibasetrans, $this->_connectionID );
		return $this->_transactionID;
	}

	public function CommitTrans($ok=true)
	{
		if (!$ok) {
			return $this->RollbackTrans();
		}
		if ($this->transOff) {
			return true;
		}
		if ($this->transCnt) {
			$this->transCnt -= 1;
		}
		$ret = false;
		$this->autoCommit = true;
		if ($this->_transactionID) {
			//print ' commit ';
			$ret = fbird_commit($this->_transactionID);
		}
		$this->_transactionID = false;
		return $ret;
	}

	protected function _affectedrows()
	{
			return fbird_affected_rows( $this->_transactionID ? $this->_transactionID : $this->_connectionID );
	}

	// there are some compat problems with ADODB_COUNTRECS=false and $this->_logsql currently.
	// it appears that ibase extension cannot support multiple concurrent queryid's
	protected function _Execute($sql,$inputarr=false) {
	global $ADODB_COUNTRECS;

		if ($this->_logsql) {
			$savecrecs = $ADODB_COUNTRECS;
			$ADODB_COUNTRECS = true; // force countrecs
			$ret =& ADOConnection::_Execute($sql,$inputarr);
			$ADODB_COUNTRECS = $savecrecs;
		} else {
			$ret = ADOConnection::_Execute($sql,$inputarr);
		}
		return $ret;
	}

	public function RollbackTrans()
	{
		if ($this->transOff) return true;
		if ($this->transCnt) $this->transCnt -= 1;
		$ret = false;
		$this->autoCommit = true;
		if ($this->_transactionID) {
			$ret = fbird_rollback($this->_transactionID);
		}
		$this->_transactionID = false;

		return $ret;
	}

	protected function _MetaIndexes ($pParsedTableName, $primary = FALSE, $owner=false)
	{
		$false = false;
		$table = $pParsedTableName['table']['name'];
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

		$table = strtoupper($table);
		$sql = "SELECT * FROM RDB\$INDICES WHERE UPPER(RDB\$RELATION_NAME) = '".$table."'";
		if (!$primary) {
			$sql .= " AND RDB\$INDEX_NAME NOT LIKE 'RDB\$%'";
		} else {
			$sql .= " AND RDB\$INDEX_NAME NOT LIKE 'RDB\$FOREIGN%'";
		}
		// get index details
		$rs = $this->Execute($sql);
		if (!is_object($rs)) {
			// restore fetchmode
			$this->SetFetchMode2($savem);

			return $false;
		}

		$indexes = array();
		while ($row = $rs->FetchRow()) {
			$index = $row[0];
			if (!isset($indexes[$index])) {
				if (is_null($row[3])) {
					$row[3] = 0;
				}
				$indexes[$index] = array(
					'unique' => ($row[3] == 1),
					'columns' => array()
				);
			}
			$sql = "SELECT * FROM RDB\$INDEX_SEGMENTS WHERE RDB\$INDEX_NAME = '".$index."' ORDER BY RDB\$FIELD_POSITION ASC";
			$rs1 = $this->Execute($sql);
			while ($row1 = $rs1->FetchRow()) {
				$indexes[$index]['columns'][$row1[2]] = $row1[1];
			}
		}
		// restore fetchmode
		$this->SetFetchMode2($savem);

		return $indexes;
	}


	// See http://community.borland.com/article/0,1410,25844,00.html
	public function RowLock($tables,$where,$col=false)
	{
		if ($this->autoCommit) {
			$this->BeginTrans();
		}
		$vSQL = $this->_dataDict->RowLockSQL($tables,$where,$col);
		$this->Execute($vSQL[0]);
		return 1;
	}

	protected function _handleerror()
	{
		$this->_errorMsg = fbird_errmsg();
	}

	public function ErrorNo()
	{
		if (preg_match('/error code = ([\-0-9]*)/i', $this->_errorMsg,$arr)) return (integer) $arr[1];
		else return 0;
	}

	public function ErrorMsg()
	{
			return $this->_errorMsg;
	}

	public function Prepare($sql)
	{
		if(!$this->_bindInputArray) 
			{return $sql;} // no binding

		$stmt = fbird_prepare($this->_connectionID,$sql);
		if (!$stmt) return false;
		return array($sql,$stmt);
	}

	   // returns query ID if successful, otherwise false
	   // there have been reports of problems with nested queries - the code is probably not re-entrant?
	protected function _query($sql,$iarr=false)
	{
		if ( !$this->isConnected() ) return false;
		if (!$this->autoCommit && $this->_transactionID) {
			$conn = $this->_transactionID;
			$docommit = false;
		} else {
			$conn = $this->_connectionID;
			$docommit = true;
		}
		if (is_array($sql)) {
			$fn = 'fbird_execute';
			$sql = $sql[1];
			if (is_array($iarr)) {
				if ( !isset($iarr[0]) ) 
					$iarr[0] = ''; // PHP5 compat hack
				$fnarr = array_merge( array($sql) , $iarr);
				$ret = call_user_func_array($fn,$fnarr);
			}
			else {
				$ret = $fn($sql);
			}
		} else {
			$fn = 'fbird_query';
			if (is_array($iarr)) 
			{
				if (sizeof($iarr) == 0) 
					$iarr[0] = ''; // PHP5 compat hack
				$fnarr = array_merge( array($conn,$sql) , $iarr);
				$ret = call_user_func_array($fn,$fnarr);
			}
			else {
				$ret = $fn($conn, $sql);
			}
		}
		if ($docommit && $ret === true) {
			fbird_commit($this->_connectionID);
		}

		$this->_handleerror();
		return $ret;
	}

	// returns true or false
	protected function _close()
	{
		if (!$this->autoCommit) {
			@fbird_rollback($this->_connectionID);
		}
		return @fbird_close($this->_connectionID);
	}

	//OPN STUFF start
	protected function _ConvertFieldType(&$fld, $ftype, $flen, $fscale, $fsubtype, $fprecision, $dialect3)
	{
		$fscale = abs($fscale);
		$fld->max_length = $flen;
		$fld->scale = null;
		switch($ftype){
			case 7:
			case 8:
				if ($dialect3) {
					switch($fsubtype){
						case 0:
							$fld->type = ($ftype == 7 ? 'smallint' : 'integer');
							break;
						case 1:
							$fld->type = 'numeric';
							$fld->max_length = $fprecision;
							$fld->scale = $fscale;
							break;
						case 2:
							$fld->type = 'decimal';
							$fld->max_length = $fprecision;
							$fld->scale = $fscale;
							break;
					} // switch
				} else {
					if ($fscale !=0) {
						$fld->type = 'decimal';
						$fld->scale = $fscale;
						$fld->max_length = ($ftype == 7 ? 4 : 9);
					} else {
						$fld->type = ($ftype == 7 ? 'smallint' : 'integer');
					}
				}
				break;
			case 16:
				if ($dialect3) {
					switch($fsubtype){
						case 0:
							$fld->type = 'decimal';
							$fld->max_length = 18;
							$fld->scale = 0;
							break;
						case 1:
							$fld->type = 'numeric';
							$fld->max_length = $fprecision;
							$fld->scale = $fscale;
							break;
						case 2:
							$fld->type = 'decimal';
							$fld->max_length = $fprecision;
							$fld->scale = $fscale;
							break;
					} // switch
				}
				break;
			case 10:
				$fld->type = 'float';
				break;
			case 14:
				$fld->type = 'char';
				break;
			case 27:
				if ($fscale !=0) {
					$fld->type = 'decimal';
					$fld->max_length = 15;
					$fld->scale = 5;
				} else {
					$fld->type = 'double';
				}
				break;
			case 35:
				if ($dialect3) {
					$fld->type = 'timestamp';
				} else {
					$fld->type = 'date';
				}
				break;
			case 12:
				$fld->type = 'date';
				break;
			case 13:
				$fld->type = 'time';
				break;
			case 37:
				$fld->type = 'varchar';
				break;
			case 40:
				$fld->type = 'cstring';
				break;
			case 261:
				$fld->type = 'blob';
				$fld->max_length = -1;
				break;
		} // switch
	}
	//OPN STUFF end

	// returns array of ADOFieldObjects for current table
	protected function _MetaColumns($pParsedTableName)
	{
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$table = $pParsedTableName['table']['name'];

		$rs = $this->Execute(sprintf($this->metaColumnsSQL,strtoupper($table)));

		$this->SetFetchMode2($savem);
		$false = false;
		if ($rs === false) {
			return $false;
		}

		$retarr = array();
		//OPN STUFF start
		$dialect3 = ($this->dialect==3 ? true : false);
		//OPN STUFF end
		while (!$rs->EOF) { //print_r($rs->fields);
			$fld = new ADOFieldObject();
			$fld->name = trim($rs->fields[0]);
			//OPN STUFF start
			$this->_ConvertFieldType($fld, $rs->fields[7], $rs->fields[3], $rs->fields[4], $rs->fields[5], $rs->fields[6], $dialect3);
			if (isset($rs->fields[1]) && $rs->fields[1]) {
				$fld->not_null = true;
			}
			if (isset($rs->fields[2])) {

				$fld->has_default = true;
				$d = substr($rs->fields[2],strlen('default '));
				switch ($fld->type)
				{
				case 'smallint':
				case 'integer': $fld->default_value = (int) $d; break;
				case 'char':
				case 'blob':
				case 'text':
				case 'varchar': $fld->default_value = (string) substr($d,1,strlen($d)-2); break;
				case 'double':
				case 'float': $fld->default_value = (float) $d; break;
				default: $fld->default_value = $d; break;
				}
		//	case 35:$tt = 'TIMESTAMP'; break;
			}
			if ((isset($rs->fields[5])) && ($fld->type == 'blob')) {
				$fld->sub_type = $rs->fields[5];
			} else {
				$fld->sub_type = null;
			}
			//OPN STUFF end
			if ($this->GetFetchMode() == ADODB_FETCH_NUM) $retarr[] = $fld;
			else $retarr[strtoupper($fld->name)] = $fld;

			$rs->MoveNext();
		}
		$rs->Close();
		if ( empty($retarr)) return $false;
		else return $retarr;
	}

	public function BlobEncode( $blob )
	{
		$blobid = fbird_blob_create( $this->_connectionID);
		fbird_blob_add( $blobid, $blob );
		return fbird_blob_close( $blobid );
	}

	// since we auto-decode all blob's since 2.42,
	// BlobDecode should not do any transforms
	public function BlobDecode($blob)
	{
		return $blob;
	}

	// old blobdecode function
	// still used to auto-decode all blob's
	public function _BlobDecode_old( $blob )
	{
		$blobid = fbird_blob_open($this->_connectionID, $blob );
		$realblob = fbird_blob_get( $blobid,$this->maxblobsize); // 2nd param is max size of blob -- Kevin Boillet <kevinboillet@yahoo.fr>
		while($string = fbird_blob_get($blobid, 8192)){
			$realblob .= $string;
		}
		fbird_blob_close( $blobid );

		return( $realblob );
	}

	public function _BlobDecode( $blob )
	{
		$blob_data = fbird_blob_info($this->_connectionID, $blob );
		$blobid    = fbird_blob_open($this->_connectionID, $blob );

		if( $blob_data[0] > $this->maxblobsize ) {
			$realblob = fbird_blob_get($blobid, $this->maxblobsize);

			while($string = fbird_blob_get($blobid, 8192)) {
				$realblob .= $string;
			}
		} else {
			$realblob = fbird_blob_get($blobid, $blob_data[0]);
		}

		fbird_blob_close( $blobid );
		return( $realblob );
	}

	public function UpdateBlobFile($table,$column,$path,$where,$blobtype='BLOB')
	{
		$fd = fopen($path,'rb');
		if ($fd === false) return false;
		$blob_id = fbird_blob_create($this->_connectionID);

		/* fill with data */

		while ($val = fread($fd,32768)){
			fbird_blob_add($blob_id, $val);
		}

		/* close and get $blob_id_str for inserting into table */
		$blob_id_str = fbird_blob_close($blob_id);

		fclose($fd);
		return $this->Execute("UPDATE $table SET $column=(?) WHERE $where",array($blob_id_str)) != false;
	}

	/*
		Insert a null into the blob field of the table first.
		Then use UpdateBlob to store the blob.

		Usage:

		$conn->Execute('INSERT INTO blobtable (id, blobcol) VALUES (1, null)');
		$conn->UpdateBlob('blobtable','blobcol',$blob,'id=1');
	*/
	public function UpdateBlob($table,$column,$val,$where,$blobtype='BLOB')
	{
	$blob_id = fbird_blob_create($this->_connectionID);

	// fbird_blob_add($blob_id, $val);

	// replacement that solves the problem by which only the first modulus 64K /
	// of $val are stored at the blob field ////////////////////////////////////
	// Thx Abel Berenstein  aberenstein#afip.gov.ar
	$len = strlen($val);
	$chunk_size = 32768;
	$tail_size = $len % $chunk_size;
	$n_chunks = ($len - $tail_size) / $chunk_size;

	for ($n = 0; $n < $n_chunks; $n++) {
		$start = $n * $chunk_size;
		$data = substr($val, $start, $chunk_size);
		fbird_blob_add($blob_id, $data);
	}

	if ($tail_size) {
		$start = $n_chunks * $chunk_size;
		$data = substr($val, $start, $tail_size);
		fbird_blob_add($blob_id, $data);
	}
	// end replacement /////////////////////////////////////////////////////////

	$blob_id_str = fbird_blob_close($blob_id);

	return $this->Execute("UPDATE $table SET $column=(?) WHERE $where",array($blob_id_str)) != false;

	}


	public function OldUpdateBlob($table,$column,$val,$where,$blobtype='BLOB')
	{
		$blob_id = fbird_blob_create($this->_connectionID);
		fbird_blob_add($blob_id, $val);
		$blob_id_str = fbird_blob_close($blob_id);
		return $this->Execute("UPDATE $table SET $column=(?) WHERE $where",array($blob_id_str)) != false;
	}

	// Note that Interbase 6.5 uses this ROWS instead - don't you love forking wars!
	// 		SELECT col1, col2 FROM table ROWS 5 -- get 5 rows
	//		SELECT col1, col2 FROM TABLE ORDER BY col1 ROWS 3 TO 7 -- first 5 skip 2
	public function &SelectLimit($sql,$nrows=-1,$offset=-1,$inputarr=false, $secs=0)
	{
		$nrows = (integer) $nrows;
		$offset = (integer) $offset;
		$str = 'SELECT ';
		if ($nrows >= 0) $str .= "FIRST $nrows ";
		$str .=($offset>=0) ? "SKIP $offset " : '';

		$sql = preg_replace('/^[ \t]*select/i',$str,$sql);
		if ($secs)
			$rs = $this->CacheExecute($secs,$sql,$inputarr);
		else
			$rs = $this->Execute($sql,$inputarr);

		return $rs;
	}

	public function MetaType($t,$len=-1,$fieldobj=false)
	{
		if (is_object($t)) {
			$fieldobj = $t;
			$t = $fieldobj->type;
			$len = $fieldobj->max_length;
		}
		switch (strtoupper($t)) {
		case 'CHAR':
			return 'C';

		case 'TEXT':
		case 'VARCHAR':
		case 'VARYING':
		if ($len <= $this->blobSize) return 'C';
			return 'X';
		case 'BLOB':
			return 'B';

		case 'TIMESTAMP':
		case 'DATE': return 'D';
		case 'TIME': return 'T';
				//case 'T': return 'T';

				//case 'L': return 'L';
		case 'INT':
		case 'SHORT':
		case 'INTEGER': return 'I';
		default: return ADODB_DEFAULT_METATYPE;
		}
	}

}

/*--------------------------------------------------------------------------------------
		 Class Name: Recordset
--------------------------------------------------------------------------------------*/

class  ADORecordset_firebird extends ADORecordSet
{

	public  $databaseType = "firebird";
	public  $bind=false;
	protected $_cacheType;


	/**
	 * Get column information in the Recordset object.
	 * FetchField() can be used in order to obtain information about fields in
	 * a certain query result. If the field offset isn't specified, the next
	 * field that wasn't yet retrieved by FetchField() is retrieved.
	 * @return object containing field information.
	*/
	protected function _FetchField($fieldOffset = -1)
	{
			$fld = new ADOFieldObject;
			 $ibf = fbird_field_info($this->_queryID,$fieldOffset);

			if($ibf === false)
				{return false;}

			$name = empty($ibf['alias']) ? $ibf['name'] : $ibf['alias'];

			$fld->name = $name;

			$fld->type = $ibf['type'];
			$fld->max_length = $ibf['length'];

			/*       This needs to be populated from the metadata */
			$fld->not_null = false;
			$fld->has_default = false;
			$fld->default_value = 'null';
			return $fld;
	}

	protected function _initrs()
	{
		$this->_numOfRows = -1;
		$this->_numOfFields = @fbird_num_fields($this->_queryID);

		// cache types for blob decode check
		for ($i=0, $max = $this->_numOfFields; $i < $max; $i++) {
			$f1 = $this->FetchField($i);
			$this->_cacheType[] = $f1->type;
		}
	}

	protected function _seek($row)
	{
		return false;
	}

	protected function _fetch()
	{
		$this->bind = false;
		$f = @fbird_fetch_row($this->_queryID);
		if ($f === false) {
			$this->fields = false;
			return false;
		}
		// OPN stuff start - optimized
		// fix missing nulls and decode blobs automatically

		global $ADODB_ANSI_PADDING_OFF;
		//$ADODB_ANSI_PADDING_OFF=1;
		$rtrim = !empty($ADODB_ANSI_PADDING_OFF);

		for ($i=0, $max = $this->_numOfFields; $i < $max; $i++) {
			if ($this->_cacheType[$i]=="BLOB") {
				if (isset($f[$i])) {
					$f[$i] = $this->connection->_BlobDecode($f[$i]);
				} else {
					$f[$i] = null;
				}
			} else {
				if (!isset($f[$i])) {
					$f[$i] = null;
				} else if ($rtrim && is_string($f[$i])) {
					$f[$i] = rtrim($f[$i]);
				}
			}
		}
		// OPN stuff end

		$this->fields = $f;
		if ($this->fetchMode == ADODB_FETCH_ASSOC) {
			$this->fields = $this->GetEmulatedRowAssoc();
		} else if ($this->fetchMode == ADODB_FETCH_BOTH) {
			$this->fields = array_merge($this->fields,$this->GetEmulatedRowAssoc());
		}
		return true;
	}

	protected function _close()
	{
			return @fbird_free_result($this->_queryID);
	}


}
