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

  Microsoft SQL Server ADO data driver. Requires ADO and MSSQL client.
  Works only on MS Windows.

  Warning: Some versions of PHP (esp PHP4) leak memory when ADO/COM is used.
  Please check http://bugs.php.net/ for more info.
*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

if (!defined('_ADODB_ADO_LAYER')) {
	include_once(ADODB_DIR . "/drivers/adodb-ado5.inc.php");
}


class  ADODB_ado_mssql extends ADODB_ado {
	public  $databaseType = 'ado_mssql';
	public  $hasTop = 'top';
	public  $hasInsertID = true;
	public  $leftOuter = '*=';
	public  $rightOuter = '=*';
	public  $ansiOuter = true; // for mssql7 or later
	public  $substr = "substring";
	public  $length = 'len';

	//protected  $_inTransaction = 1; // always open recordsets, so no transaction problems.

	public function __construct()
	{
	        parent::__construct();
	}

	public function ServerInfo()
	{
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
			
		$row = $this->GetRow("execute sp_server_info 2");


		$this->SetFetchMode2($savem);

		$arr['description'] = $row[2];
		$arr['version'] = ADOConnection::_findvers($arr['description']);
		return $arr;
	}
	

	protected function _connect($pHostName, $pUserName, $pPassword, $pDataBase, $p_ = '')
		{return parent::_connect($pHostName, $pUserName, $pPassword, $pDataBase, 'mssql');}

	protected function _insertid()
	{
		return $this->GetOne('select SCOPE_IDENTITY()');
	}

	protected function _affectedrows()
	{
			return $this->GetOne('select @@rowcount');
	}

	public function SetTransactionMode( $transaction_mode )
	{
		$this->_transmode  = $transaction_mode;
		if (empty($transaction_mode)) {
			$this->Execute('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
			return;
		}
		if (!stristr($transaction_mode,'isolation')) $transaction_mode = 'ISOLATION LEVEL '.$transaction_mode;
		$this->Execute("SET TRANSACTION ".$transaction_mode);
	}

	public function qstr($s,$magic_quotes=false)
	{
		$s = ADOConnection::qstr($s, $magic_quotes);
		return str_replace("\0", "\\\\000", $s);
	}

	protected function _MetaColumns($pParsedTableName)
	{
		$table = $this->NormaliseIdentifierNameIf($pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']);
		$arr= array();
		$dbc = $this->_connectionID;

		$osoptions = array();
		$osoptions[0] = null;
		$osoptions[1] = (@$pParsedTableName['schema']['name'] ? 
				@$pParsedTableName['schema']['name'] : null);
		$osoptions[2] = $table;
		$osoptions[3] = null;

		$adors=@$dbc->OpenSchema(4, $osoptions);//tables

		if ($adors){
			while (!$adors->EOF){
				$fld = new ADOFieldObject();
				$c = $adors->Fields(3);
				$fld->name = $c->Value;
				$fld->type = 'CHAR'; // cannot discover type in ADO!
				$fld->max_length = -1;

				if($this->GetFetchMode() == ADODB_FETCH_NUM)
					{$arr[] = $fld;}
				else
					{$arr[strtoupper($fld->name)]=$fld;}

				$adors->MoveNext();
			}
			$adors->Close();
		}
		$false = false;
		return empty($arr) ? $false : $arr;
	}

	public function CreateSequence($seq='adodbseq',$start=1)
	{

		$this->Execute('BEGIN TRANSACTION adodbseq');
		$ok = ADOConnection::CreateSequence($seq,$start);
		if (!$ok) {
				$this->Execute('ROLLBACK TRANSACTION adodbseq');
				return false;
		}
		$this->Execute('COMMIT TRANSACTION adodbseq');
		return true;
	}

	public function GenID($seq='adodbseq',$start=1)
	{
		//$this->debug=1;
		$this->Execute('BEGIN TRANSACTION adodbseq');
		$num = ADOConnection::GenID($seq, $start);
		if ($num == 0) {
			$this->Execute('ROLLBACK TRANSACTION adodbseq');
			return 0;
		}	

		$this->Execute('COMMIT TRANSACTION adodbseq');
		return $num;

		// in old implementation, pre 1.90, we returned GUID...
		//return $this->GetOne("SELECT CONVERT(varchar(255), NEWID()) AS 'Char'");
	}

} // end class

class ADORecordSet_ado_mssql extends ADORecordSet_ado {

	public  $databaseType = 'ado_mssql';

}
