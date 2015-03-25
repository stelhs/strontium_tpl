<?php

require_once("tpl.php");

// Parsing data.csv
$demo_data = array();
$csv = file_get_contents("data.csv");
$strings = explode("\n", $csv);
if ($strings)
    foreach ($strings as $string) {
        $cols = explode(";", $string);
        if (!$cols || !$cols[0])
            continue;

        $demo_data[] = array("time" => $cols[0],
                             "source" => $cols[1],
                             "type" => $cols[2],
                             "count" => $cols[3],
                            );
    }

// Create strontium instance and open teamplate
$tpl = new strontium_tpl('example_tpl.html', array(), false);
$tpl->assign();

// If data.csv is empty
if (!$demo_data) {
    $tpl->assign("no_data");
    echo $tpl->make_result();
    exit;
}

// Display data.csv in html table
$tpl->assign("data_table");
foreach ($demo_data as $row) {
    $tpl->assign("row_table", $row);
}

// Return html code
echo $tpl->result();

?>