<?php
/**
 * process_party_feeds.php
 *
 * NOTE: Make sure the client email address (fb-video-dashboard@nyt-newsroom-dashboards.iam.gserviceaccount.com)
 * has edit permission on the sheet.  Otherwise will need to update code to impersonate someone.
 *
 */
ini_set('display_errors', 1);
date_default_timezone_set('America/New_York');
error_reporting(E_ALL|E_STRICT);

$start_time = microtime(true);
$end_time = 0;

ini_set('memory_limit','256M');

require_once __DIR__ . '/../vendor/autoload.php';

// Colors used in spreadsheet.
$color = array();
$color['s1']   = 'FFF3E0'; // Orange 50

// Set Google Sheet ID
$spreadsheet_id = '1sj7QTBQNC71RpTvUT-3dmBwLZoFiigZn_cJeQgXnUgc';
$sheet_id = 0;

print "Process Party Feeds\n";
print "===================\n";
print "Spreadsheet: https://docs.google.com/spreadsheets/d/$spreadsheet_id/\n";

// Get the Google Sheets Service.
$service = get_service();

// Clear data in spreadsheet.
print "Clearing sheet...\n\n";
clear_spreadsheet($service, $spreadsheet_id, $sheet_id);

$rows = array();
$rows[] = get_first_row();

// Write first two rows.
write_rows_to_sheet($service, $spreadsheet_id, $sheet_id, $rows, 0);


$end_time = microtime(true);
$diff_time = $end_time - $start_time;
printf("Took %2.1fs to run and used %s of memory.\n", $diff_time, bytes_to_nice_string(memory_get_usage()));

exit(0);



/**
 * Gets Google Sheets service.  Uses newsroom dashboard auth config.
 */
function get_service()
{
    $client = new Google_Client();
    $client->setApplicationName("fb-video-dashboard");
    $client->setAuthConfig(__DIR__ . '/google-project-nyt-video-dashboard.json');
    $client->setAccessType('offline');

    $client->addScope(Google_Service_Sheets::SPREADSHEETS);

    $service = new Google_Service_Sheets($client);

    return $service;
}

/**
 * Clear all the values from the spreadsheet.
 */
function clear_spreadsheet($service, $spreadsheet_id, $sheet_id)
{
    $request = new Google_Service_Sheets_Request();
    $ucRequest = new Google_Service_Sheets_UpdateCellsRequest();
    $range = new Google_Service_Sheets_GridRange();
    $range->setSheetId($sheet_id);
    $range->setStartRowIndex(0);
    $range->setStartColumnIndex(0);
    $ucRequest->setFields('*');
    $ucRequest->setRange($range);
    $request->setUpdateCells($ucRequest);
    $batchUpdate = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest();
    $batchUpdate->setRequests(array($request));
    $result = $service->spreadsheets->batchUpdate($spreadsheet_id, $batchUpdate);
}

/**
 * Get first row.
 */
function get_first_row()
{
    global $color;
    $row = new Google_Service_Sheets_RowData();
    // get column headers
    $cells = array();
    $cells[] = get_cell('Event', $color['s1'], false, 12, 'LEFT',   'BOTTOM', 'WRAP');  // A - 1
    $row->setValues($cells);
    return ($row);
}

/**
 * Writes array of rows to sheet.
 */
function write_rows_to_sheet($service, $spreadsheet_id, $sheet_id, $rows, $start_row)
{
    $num_rows = count($rows);
    $num_cols = count($rows[0]->values);

    $range = new Google_Service_Sheets_GridRange();
    $range->setSheetId($sheet_id);
    $range->setStartRowIndex($start_row);
    $range->setEndRowIndex($start_row + $num_rows);
    $range->setStartColumnIndex(0);
    $range->setEndColumnIndex($num_cols);

    $cellsRequest = new Google_Service_Sheets_UpdateCellsRequest();
    $cellsRequest->setFields('*');
    $cellsRequest->setRange($range);
    $cellsRequest->setRows($rows);
    $request = new Google_Service_Sheets_Request();
    $request->setUpdateCells($cellsRequest);

    $batchUpdate = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest();
    $batchUpdate->setRequests(array($request));
    // retry 3 times if batch update fails
    for ($i = 1; $i <= 3; $i++) {
        try {
            $result = $service->spreadsheets->batchUpdate($spreadsheet_id, $batchUpdate);
            break;
        } catch (Google_Service_Exception $gse) {
            usleep(1000000 * $i);
            print "  Got exception updating spreadsheet (try $i).\n";
        }
    }
}



/**
 * Given color string (e.g. EDE7F6) returns Google_Service_Sheets_Color.
 */
function get_color($rgb)
{
    $red = substr($rgb, 0, 2);
    $green = substr($rgb, 2, 2);
    $blue = substr($rgb, 4, 2);

    $color = new Google_Service_Sheets_Color();
    $color->setAlpha(0);
    $color->setRed((hexdec($red) / 255));
    $color->setGreen((hexdec($green) / 255));
    $color->setBlue((hexdec($blue) / 255));

    return ($color);
}


function get_cell($val, $bg_color, $bold, $font_size, $halign, $valign, $wrap, $type = 'string')
{
    $cellData = new Google_Service_Sheets_CellData();
    $value = new Google_Service_Sheets_ExtendedValue();
    $cell_format = new Google_Service_Sheets_CellFormat();

    if ($type == 'int') {
        $value->setNumberValue($val);
        $number_format = new Google_Service_Sheets_NumberFormat();
        $number_format->setPattern("#,##0");
        $number_format->setType("Number");
        $cell_format->setNumberFormat($number_format);
    } elseif ($type == 'float') {
        $value->setNumberValue($val);
        $number_format = new Google_Service_Sheets_NumberFormat();
        $number_format->setPattern("#,##0.00");
        $number_format->setType("Number");
        $cell_format->setNumberFormat($number_format);
    } elseif ($type == 'number') {
        $value->setNumberValue($val);
    } elseif ($type == 'percent') {
        $value->setNumberValue($val);
        $number_format = new Google_Service_Sheets_NumberFormat();
        $number_format->setPattern("#0.00%");
        $number_format->setType("Number");
        $cell_format->setNumberFormat($number_format);
    } else {
        $value->setStringValue($val);
    }


    $g_bg_color = get_color($bg_color);
    $g_font_color = get_color('212121');

    $text_format = new Google_Service_Sheets_TextFormat();
    $text_format->setBold($bold);
    $text_format->setFontSize($font_size);
    $text_format->setForegroundColor($g_font_color);
    $cell_format->setBackgroundColor($g_bg_color);
    $cell_format->setHorizontalAlignment($halign);
    $cell_format->setVerticalAlignment($valign);
    $cell_format->setWrapStrategy($wrap);
    $cell_format->setTextFormat($text_format);

    $cellData->setUserEnteredFormat($cell_format);
    $cellData->setUserEnteredValue($value);

    return ($cellData);
}


/**
 * Return the nice column name string (e.g. AB) given the column number (e.g. 28).
 */
function get_column_name($num)
{
    // A = 65 ASCII
    $num--;
    $prefix = '';
    $prefix_num = floor($num / 26);
    if ($prefix_num > 0) {
        $prefix = chr($prefix_num + 64);
    }
    $col_num = $prefix . chr(($num % 26) + 65);
    return ($col_num);
}



/**
 * Given number of bytes returns nice string representing size (1.1 mb).
 */
function bytes_to_nice_string($bytes)
{
    $nice_str = "";
    if ($bytes > (1024 * 1024 * 1024)) {
        $nice_str = sprintf ("%2.1f gb", $bytes / (1024 * 1024 * 1024));
    } elseif ($bytes > (1024 * 1024)) {
        $nice_str = sprintf ("%2.1f mb", $bytes / (1024 * 1024));
    } elseif ($bytes > 1024) {
        $nice_str = sprintf ("%2.1f k", $bytes / 1024);
    } else {
        $nice_str = sprintf ("%d b", $bytes);
    }
    return ($nice_str);
}
