<?php
/*
@version   v5.21dev  ??-???-2015
@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence. See License.txt.
  Set tabs to 4 for best viewing.

  Latest version is available at http://adodb.sourceforge.net

  Microsoft Access data driver. Requires ODBC. Works only on MS Windows.
*/
if (!defined('_ADODB_ODBC_LAYER')) {
	if (!defined('ADODB_DIR')) die();

	include(ADODB_DIR."/drivers/adodb-odbc.inc.php");
}

if (!defined('_ADODB_ACCESS')) {
	define('_ADODB_ACCESS',1);

class  ADODB_odbc_access extends ADODB_odbc {
	public  $databaseType = 'odbc_access';
	public  $hasTop = 'top';		// support mssql SELECT TOP 10 * FROM TABLE
	public  $fmtDate = "#Y-m-d#";
	public  $fmtTimeStamp = "#Y-m-d h:i:sA#"; // note not comma
	protected  $_bindInputArray = false; // strangely enough, setting to true does not work reliably
	public  $hasTransactions = false;
	public  $upperCase = 'ucase';
	public  $hasGenID = true;

	public function __construct()
	{
	global $ADODB_EXTENSION;

		$ADODB_EXTENSION = false;
		parent::__construct();
	}

	public function Time()
	{
		return time();
	}

	public function BeginTrans() { return false;}

	public function IfNull( $field, $ifNull )
	{
		return " IIF(IsNull($field), $ifNull, $field) "; // if Access
	}
/*
	public function MetaTables()
	{
	global $ADODB_FETCH_MODE;

		$savem = $ADODB_FETCH_MODE;
		$ADODB_FETCH_MODE = ADODB_FETCH_NUM;
		$qid = odbc_tables($this->_connectionID);
		$rs = new ADORecordSet_odbc($qid);
		$ADODB_FETCH_MODE = $savem;
		if (!$rs) return false;

		$rs->_has_stupid_odbc_fetch_api_change = $this->_has_stupid_odbc_fetch_api_change;

		$arr = $rs->GetArray();
		//print_pre($arr);
		$arr2 = array();
		for ($i=0; $i < sizeof($arr); $i++) {
			if ($arr[$i][2] && $arr[$i][3] != 'SYSTEM TABLE')
				$arr2[] = $arr[$i][2];
		}
		return $arr2;
	}*/
}


class  ADORecordSet_odbc_access extends ADORecordSet_odbc {

	public  $databaseType = "odbc_access";

} // class

}
