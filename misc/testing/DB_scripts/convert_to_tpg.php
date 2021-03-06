<?php
require_once(dirname(__FILE__).'/../../../www/config.php');
require_once nZEDb_LIB . 'framework/db.php';
require_once nZEDb_LIB . 'ColorCLI.php';
require_once nZEDb_LIB . 'consoletools.php';
require_once nZEDb_LIB . 'groups.php';


/*This script will allow you to move from single collections/binaries/parts tables to TPG without having to run reset_truncate.
Please STOP all update scripts before running this script.

Use the following options to run:
php convert_to_tpg.php true               Convert c/b/p to tpg leaving current collections/binaries/parts tables in-tact.
php convert_to_tgp.php true delete        Convert c/b/p to tpg and TRUNCATE current collections/binaries/parts tables.
*/
$debug = false;
$db = new DB();
$c = new ColorCLI();
$groups = new Groups();
$consoletools = new ConsoleTools();

if ((!isset($argv[1])) || $argv[1] != 'true')
    exit($c->error("Mandatory argument missing\n\nThis script will allow you to move from single collections/binaries/parts tables to TPG without having to run reset_truncate.\nPlease STOP all update scripts before running this script.\n\nUse the following options to run:\nphp convert_to_tpg.php true               Convert c/b/p to tpg leaving current collections/binaries/parts tables in-tact.\nphp convert_to_tgp.php true delete        Convert c/b/p to tpg and TRUNCATE current collections/binaries/parts tables."));

$clen = $db->queryOneRow('SELECT COUNT(*) AS total FROM collections;');
$cdone = 0;
$ccount = 1;
$gdone = 1;
$actgroups = $groups->getActive();
$glen = count($actgroups);
$newtables = $glen * 3;
$starttime = time();

echo "Creating new collections, binaries, and parts tables for each active group...\n";

foreach ($actgroups as $group)
{
    if ($db->newtables($group['id']) === false)
        exit ($c->error("There is a problem creating new parts/files tables for group ${group['name']}."));
    $consoletools->overWrite("Tables Created: ".$consoletools->percentString($gdone*3, $newtables));
    $gdone++;
}
$endtime = time();
echo "\nTable creation took ".$consoletools->convertTime($endtime - $starttime).".\n";
$starttime = time();
echo "\nNew tables created, moving data from old tables to new tables.\nThis will take awhile....\n";
while ($cdone < $clen['total'])
{
    // Only load 1000 collections per loop to not overload memory.
    $collections = $db->queryAssoc('select * from collections limit '.$cdone.',1000;');
    foreach ($collections as $collection)
    {
        /*foreach ($collection as $ckey => $cval)
        {
            //if (is_int($ckey))
                //unset($collection[$ckey]);
            if ($ckey != 'groupid')
                $collection[$ckey] = $db->escapeString($cval);
        }*/
        $collection['subject'] = $db->escapeString($collection['subject']);
        $collection['fromname'] = $db->escapeString($collection['fromname']);
        $collection['date'] = $db->escapeString($collection['date']);
        $collection['collectionhash'] = $db->escapeString($collection['collectionhash']);
        $collection['dateadded'] = $db->escapeString($collection['dateadded']);
        $collection['xref'] = $db->escapeString($collection['xref']);
        $collection['releaseid'] = $db->escapeString($collection['releaseid']);
        $oldcid = array_shift($collection);
        if($debug)
        {
            echo "\n\nCollection insert:\n";
            print_r($collection);
            echo sprintf("\nINSERT INTO %d_collections (subject, fromname, date, xref, totalfiles, groupid, collectionhash, dateadded, filecheck, filesize, releaseid) VALUES (%s)\n\n", $collection['groupid'], implode(', ', $collection));
        }
        $newcid = array('collectionid' => $db->queryInsert(sprintf('INSERT INTO %d_collections (subject, fromname, date, xref, totalfiles, groupid, collectionhash, dateadded, filecheck, filesize, releaseid) VALUES (%s);', $collection['groupid'], implode(', ', $collection))));
        $consoletools->overWrite('Collections Completed: '.$consoletools->percentString($ccount, $clen['total']));

        //Get binaries and split to correct group tables.
        $binaries = $db->queryAssoc('SELECT * FROM binaries WHERE collectionID = '.$oldcid.';');
        foreach ($binaries as $binary)
        {
            $binary['name'] = $db->escapeString($binary['name']);
            $binary['binaryhash'] = $db->escapeString($binary['binaryhash']);
            $oldbid = array_shift($binary);
            $binarynew = array_replace($binary, $newcid);
            if ($debug)
            {
                echo "\n\nBinary insert:\n";
                print_r($binarynew);
                echo sprintf("\nINSERT INTO %d_binaries (name, collectionid, filenumber, totalparts, binaryhash, partcheck, partsize) VALUES (%s)\n\n", $collection['groupid'], implode(', ', $binarynew));
            }
            $newbid = array('binaryid' => $db->queryInsert(sprintf('INSERT INTO %d_binaries (name, collectionid, filenumber, totalparts, binaryhash, partcheck, partsize) VALUES (%s);', $collection['groupid'], implode(', ', $binarynew))));


            //Get parts and split to correct group tables.
            $parts = $db->queryAssoc('SELECT * FROM parts WHERE binaryID = '.$oldbid.';');
            $firstpart = true;
            $partsnew = '';
            foreach ($parts as $part)
            {
                $oldpid = array_shift($part);
                $partnew = array_replace($part, $newbid);

                $partsnew .= '(\''.implode('\', \'',$partnew).'\'), ';
            }
            $partsnew = substr($partsnew, 0, -2);
            if ($debug)
            {
                echo "\n\nParts insert:\n";
                echo sprintf("\nINSERT INTO %d_parts (binaryid, messageid, number, partnumber, size) VALUES %s;\n\n", $collection['groupid'], $partsnew);
            }
            $sql = sprintf('INSERT INTO %d_parts (binaryid, messageid, number, partnumber, size) VALUES %s;', $collection['groupid'], $partsnew);
            $db->queryExec($sql);
        }
        $ccount++;
    }
    $cdone = $cdone + 1000;
}

$endtime =  time();
echo "\nTable population took ".$consoletools->convertTimer($endtime - $starttime).".\n";

//Truncate old tables to save space.
if (isset($argv[2]) &&  $argv[2] == 'delete')
{
    echo "Truncating old tables...\n";
    $db->queryDirect('TRUNCATE TABLE collections;');
    $db->queryDirect('TRUNCATE TABLE binaries;');
    $db->queryDirect('TRUNCATE TABLE parts');
    echo "Complete.\n";
}
// Update TPG setting in site-edit.
$db->queryExec('UPDATE site SET value = 1 where setting = \'tablepergroup\';');
$db->queryExec('UPDATE tmux SET value = 2 where setting = \'releases\';');
echo "New tables have been created.\nTable Per Group has been set to  to \"TRUE\" in site-edit.\nUpdate Releases has been set to Threaded in tmux-edit.\n";



function multi_implode($array, $glue) {
    $ret = '';

    foreach ($array as $item) {
        if (is_array($item)) {
            $ret .= '('.multi_implode($item, $glue).'), ';
        } else {
            $ret .=  $item . $glue;
        }
    }

    $ret = substr($ret, 0, 0-strlen($glue));

    return $ret;
}
