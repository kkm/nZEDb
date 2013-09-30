<?php
require_once(dirname(__FILE__)."/../../../config.php");
require_once(WWW_DIR."lib/framework/db.php");
require_once(WWW_DIR."lib/nntp.php");

$nntp = new Nntp();
$nntp->doConnect();
$data = $nntp->getGroups();
$nntp->doQuit();

$db = new DB();
foreach ($data as $newgroup)
{
	$res1 = $db->queryOneRow(sprintf('SELECT name FROM groups WHERE name = %s', $db->escapeString($newgroup['group'])));
	if (isset($res1['name']))
		$db->queryInsert(sprintf("INSERT INTO allgroups (name, first_record, last_record, updated) VALUES (%s, %d, %d, NOW())", $db->escapeString($newgroup["group"]), $newgroup["first"], $newgroup["last"]));
}
